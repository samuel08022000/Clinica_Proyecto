<?php

namespace App\Services\Auth;

use App\Exceptions\AuthDomainException;
use App\Repositories\AuthAccountRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Hashing\HashManager;

class PasswordRecoveryService
{
    public const GENERIC_MESSAGE = 'Si la cuenta es elegible, recibirás instrucciones para restablecer la contraseña.';

    public function __construct(
        private DatabaseManager $database, private AuthMailService $mail,
        private HashManager $hash, private AuthAccountRepository $accounts,
        private SessionService $sessions,
    ) {}

    public function request(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new AuthDomainException('VALIDATION_ERROR', 422, 'Introduce un correo válido.');
        }
        try {
            $this->mail->ensureTransport();
            $token = $this->database->connection('clinical')->transaction(
                fn ($db) => $db->scalar('SELECT public.crear_token_recuperacion(?)', [$email]), 1);
            if (is_string($token) && $token !== '') {
                $url = route('password.reset', ['token' => $token, 'email' => $email]);
                try {
                    $this->mail->sendPasswordReset($email, $url);
                } catch (\Throwable) {
                }
            }
        } catch (AuthDomainException|QueryException) {
            // Respuesta deliberadamente neutral: no confirma cuenta, límite ni transporte.
        }

        return self::GENERIC_MESSAGE;
    }

    public function reset(string $email, string $token, string $password): void
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($token) < 32 || strlen($token) > 512) {
            throw $this->invalidToken();
        }
        PasswordPolicy::validate($password);
        $account = $this->accounts->findByEmail($email);
        $changed = $this->database->connection('clinical')->transaction(function ($db) use ($email, $token, $password, $account): bool {
            if ($account) {
                $db->selectOne("SELECT set_config('app.cod_usu', ?, true)", [$account->cod_usu]);
            }

            return (bool) $db->scalar('SELECT public.restablecer_contrasena(?, ?, ?)', [
                $email, $token, $this->hash->driver('bcrypt')->make($password),
            ]);
        }, 1);
        if (! $changed || ! $account) {
            throw $this->invalidToken();
        }
        $this->sessions->revokeAllForUser($account->cod_usu);
    }

    private function invalidToken(): AuthDomainException
    {
        return new AuthDomainException('PASSWORD_RESET_TOKEN_INVALID', 422,
            'El enlace no es válido, ya fue utilizado o expiró.');
    }
}
