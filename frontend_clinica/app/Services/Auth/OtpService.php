<?php

namespace App\Services\Auth;

use App\Exceptions\AuthDomainException;
use App\Repositories\AuthAccountRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Hashing\HashManager;

final class OtpService
{
    private string $dummyHash;

    public function __construct(
        private DatabaseManager $database, private ChallengeStore $challenges,
        private AuthMailService $mail, private AuthAccountRepository $accounts,
        private AccountStatusService $status, private LoginAttemptService $attempts,
        private HashManager $hash, private PatientRegistrationService $registration,
    ) {
        $this->dummyHash = $this->hash->driver('bcrypt')->make('dummy-otp-comparison-only');
    }

    public function request(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $this->attempts->ensureAllowed($email, $ip);
        $user = $this->accounts->findByEmail($email);
        $stored = $user?->password_hash;
        $valid = false;
        try {
            $valid = is_string($stored) && $stored !== '' ? $this->hash->check($password, $stored) : $this->hash->check($password, $this->dummyHash) && false;
        } catch (\Throwable) {
        }
        if (! $user || ! $valid) {
            $this->attempts->failure($email, $ip);
            throw new AuthDomainException('INVALID_VERIFICATION_CREDENTIALS', 401, 'No fue posible iniciar la verificación con esos datos.');
        }
        $account = $this->status->requireActive($user->cod_usu, false);
        if ($account->cod_rol !== 'ROL001' || $account->email_verificado === true) {
            throw new AuthDomainException('EMAIL_ALREADY_VERIFIED', 409, 'El correo ya fue verificado.');
        }
        $this->attempts->success($email, $ip);

        return $this->issue($user->cod_usu, $email);
    }

    public function issue(string $userId, string $email): array
    {
        $this->mail->ensureTransport();
        $token = $this->challenges->issue('email-verification', $userId,
            ['masked' => PatientRegistrationService::maskEmail($email)], 1800, true);
        try {
            $this->database->connection('clinical')->transaction(function ($db) use ($userId, $email): void {
                $code = $db->scalar('SELECT public.crear_otp_email(?)', [$userId]);
                $this->mail->sendOtp($email, (string) $code);
            }, 1);
        } catch (QueryException $error) {
            $this->challenges->revoke($token);
            throw $this->translate($error);
        } catch (\Throwable $error) {
            $this->challenges->revoke($token);
            throw $error;
        }

        return $this->registration->challengeData($token, $userId, '', $email);
    }

    public function resend(string $token): array
    {
        $challenge = $this->requireChallenge($token);
        $account = $this->status->requireActive($challenge->userId, false);
        if ($account->email_verificado === true) {
            throw new AuthDomainException('EMAIL_ALREADY_VERIFIED', 409, 'El correo ya fue verificado.');
        }
        try {
            $this->database->connection('clinical')->transaction(function ($db) use ($challenge, $account): void {
                $code = $db->scalar('SELECT public.crear_otp_email(?)', [$challenge->userId]);
                $this->mail->sendOtp($account->email, (string) $code);
            }, 1);
        } catch (QueryException $error) {
            throw $this->translate($error);
        }

        return $this->registration->challengeData($token, $challenge->userId, '', $account->email);
    }

    public function verify(string $token, string $code): bool
    {
        if (! preg_match('/^[0-9]{6}$/', $code)) {
            throw new AuthDomainException('OTP_FORMAT_INVALID', 422, 'Ingresa los seis dígitos del código.');
        }
        $challenge = $this->requireChallenge($token);
        // El resultado falso sale de la transacción antes de lanzar el error: así persiste el contador SQL.
        $verified = $this->database->connection('clinical')->transaction(
            fn ($db) => (bool) $db->scalar('SELECT public.validar_otp_email(?, ?)', [$challenge->userId, $code]), 1);
        if (! $verified) {
            throw new AuthDomainException('OTP_INVALID_OR_EXPIRED', 422, 'El código no es válido, expiró o alcanzó el límite de intentos.');
        }
        $this->challenges->consume($token, 'email-verification');

        return true;
    }

    public function status(string $token): array
    {
        $challenge = $this->requireChallenge($token);
        $account = $this->accounts->status($challenge->userId);

        return ['verified' => $account?->email_verificado === true, 'maskedDestination' => $challenge->payload['masked'] ?? '***@***', 'expiresAt' => $challenge->expiresAt];
    }

    private function requireChallenge(string $token): object
    {
        return $this->challenges->read($token, 'email-verification')
            ?? throw new AuthDomainException('INVALID_VERIFICATION_CHALLENGE', 401, 'La solicitud de verificación expiró o no es válida.');
    }

    private function translate(QueryException $error): AuthDomainException
    {
        $message = mb_strtolower((string) ($error->errorInfo[2] ?? $error->getMessage()));

        return match (true) {
            str_contains($message, '60 segundos') => new AuthDomainException('OTP_RESEND_TOO_SOON', 429, 'Espera al menos 60 segundos antes de solicitar otro código.', 60),
            str_contains($message, 'limite de solicitudes otp'), str_contains($message, 'límite de solicitudes otp') => new AuthDomainException('OTP_HOURLY_LIMIT_REACHED', 429, 'Se alcanzó el límite temporal de solicitudes.', 3600),
            default => new AuthDomainException('AUTH_OPERATION_FAILED', 500, 'No fue posible procesar la verificación.'),
        };
    }
}
