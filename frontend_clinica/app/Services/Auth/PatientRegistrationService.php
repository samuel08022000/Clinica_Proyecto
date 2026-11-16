<?php

namespace App\Services\Auth;

use App\Exceptions\AuthDomainException;
use App\Repositories\AuthAccountRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Hashing\HashManager;

final class PatientRegistrationService
{
    public function __construct(
        private DatabaseManager $database,
        private HashManager $hash,
        private ChallengeStore $challenges,
        private AuthMailService $mail,
        private AuthAccountRepository $accounts,
    ) {}

    public function register(array $data): array
    {
        $valid = $this->validate($data);
        $this->mail->ensureTransport();
        $challenge = null;
        try {
            return $this->database->connection('clinical')->transaction(function ($db) use ($valid, &$challenge): array {
                $patientId = $db->scalar('SELECT public.registrar_paciente(?, ?, ?, ?, ?::date, ?, ?)', [
                    $valid['name'], $valid['email'], $this->hash->driver('bcrypt')->make($valid['password']),
                    $valid['document'], $valid['birthDate'], $valid['phone'], $valid['city'],
                ]);
                $user = $this->accounts->findByEmail($valid['email']);
                if (! $user) {
                    throw new AuthDomainException('AUTH_OPERATION_FAILED', 500, 'No fue posible recuperar la cuenta creada.');
                }
                $challenge = $this->challenges->issue('email-verification', $user->cod_usu,
                    ['masked' => self::maskEmail($valid['email'])], 1800, true);
                $code = $db->scalar('SELECT public.crear_otp_email(?)', [$user->cod_usu]);
                $this->mail->sendOtp($valid['email'], (string) $code);

                return $this->challengeData($challenge, $user->cod_usu, (string) $patientId, $valid['email']);
            }, 1);
        } catch (QueryException $error) {
            if ($challenge) {
                $this->challenges->revoke($challenge);
            }
            throw $this->translate($error);
        } catch (\Throwable $error) {
            if ($challenge) {
                $this->challenges->revoke($challenge);
            }
            throw $error;
        }
    }

    public function challengeData(string $token, string $userId, string $patientId, string $email): array
    {
        return [
            'verificationToken' => $token, 'userId' => $userId, 'patientId' => $patientId,
            'maskedDestination' => self::maskEmail($email),
            'otpExpiresAt' => now()->addMinutes(5)->toIso8601String(),
            'resendAvailableAt' => now()->addSeconds(60)->toIso8601String(),
            'verificationExpiresAt' => now()->addMinutes(30)->toIso8601String(),
            'deliveryMode' => strtoupper((string) config('mail.default')),
            'deliveryConfirmed' => false,
        ];
    }

    private function validate(array $data): array
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) ($data['nombre_completo'] ?? '')));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $document = trim((string) ($data['ci'] ?? ''));
        $phoneRaw = trim((string) ($data['telefono'] ?? ''));
        $city = preg_replace('/\s+/u', ' ', trim((string) ($data['ciudad_residencia'] ?? '')));
        $birthDate = (string) ($data['fecha_nacimiento'] ?? '');
        $password = (string) ($data['password'] ?? '');
        PasswordPolicy::validate($password);
        $digits = preg_replace('/[^0-9]/', '', $phoneRaw);
        $phone = str_starts_with($phoneRaw, '+') ? '+'.$digits : $digits;
        if (! preg_match("/^[\p{L}][\p{L} .'-]{1,148}[\p{L}.]$/u", $name)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254
            || mb_strlen($document) > 30 || mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $document)) < 4
            || strlen($digits) < 8 || strlen($digits) > 15
            || ! preg_match("/^[\p{L}][\p{L} .'-]{1,98}[\p{L}.]$/u", $city)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)
            || $birthDate > now((string) config('database.connections.clinical.timezone', 'America/La_Paz'))->toDateString()) {
            throw new AuthDomainException('VALIDATION_ERROR', 422, 'Revisa los datos ingresados e intenta nuevamente.');
        }

        return compact('name', 'email', 'document', 'phone', 'city', 'birthDate', 'password');
    }

    private function translate(QueryException $error): AuthDomainException
    {
        $constraint = (string) ($error->errorInfo[2] ?? $error->getMessage());

        return match (true) {
            str_contains($constraint, 'uq_usuarios_email_ci') => new AuthDomainException('EMAIL_ALREADY_REGISTERED', 409, 'El correo ya está registrado.'),
            str_contains($constraint, 'uq_pacientes_ci_normalizado') => new AuthDomainException('DOCUMENT_ALREADY_REGISTERED', 409, 'El documento ya está registrado.'),
            default => new AuthDomainException('AUTH_OPERATION_FAILED', 500, 'No fue posible completar el registro.'),
        };
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '***');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
