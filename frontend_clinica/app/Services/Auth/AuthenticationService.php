<?php

namespace App\Services\Auth;

use App\Exceptions\AuthDomainException;
use App\Models\Usuario;
use App\Repositories\AuthAccountRepository;
use App\Support\ClinicalLog;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Request;

final class AuthenticationService
{
    private string $dummyHash;

    public function __construct(
        private AuthAccountRepository $accounts,
        private AccountStatusService $status,
        private LoginAttemptService $attempts,
        private SessionService $sessions,
        private HashManager $hash,
    ) {
        $this->dummyHash = $this->hash->driver('bcrypt')->make('dummy-authentication-comparison-only');
    }

    public function login(Request $request, string $email, string $password): object
    {
        $email = mb_strtolower(trim($email));
        $ip = $request->ip() ?: 'unknown';
        $this->attempts->ensureAllowed($email, $ip);
        $user = $this->accounts->findByEmail($email);
        $storedHash = $user?->password_hash;
        $valid = false;
        try {
            $valid = is_string($storedHash) && $storedHash !== ''
                ? $this->hash->driver('bcrypt')->check($password, $storedHash)
                : $this->hash->driver('bcrypt')->check($password, $this->dummyHash) && false;
        } catch (\Throwable) {
            $valid = false;
        }
        if (! $user || ! $valid) {
            $this->attempts->failure($email, $ip);
            ClinicalLog::event('auth.login.failed', ['error_code' => 'INVALID_CREDENTIALS'], 'warning');
            throw new AuthDomainException('INVALID_CREDENTIALS', 401, 'Correo o contraseña incorrectos.');
        }
        try {
            $account = $this->status->requireActive($user->cod_usu);
        } catch (AuthDomainException $error) {
            ClinicalLog::event('auth.login.failed', ['error_code' => $error->errorCode], 'warning');
            throw $error;
        }
        $this->attempts->success($email, $ip);
        $this->sessions->establish($request, $user, $account);
        ClinicalLog::event('auth.login.success', [
            'actor_id' => $user->cod_usu, 'role' => $account->cod_rol, 'action' => 'credentials',
        ]);

        return $account;
    }

    public function trustedLogin(Request $request, string $userId): object
    {
        $account = $this->status->requireActive($userId);
        $user = $this->accounts->findById($userId);
        if (! $user instanceof Usuario) {
            throw new AuthDomainException('ACCOUNT_NOT_FOUND', 401, 'La cuenta no está disponible.');
        }
        $this->sessions->establish($request, $user, $account);
        ClinicalLog::event('auth.login.success', [
            'actor_id' => $user->cod_usu, 'role' => $account->cod_rol, 'action' => 'verified_identity',
        ]);

        return $account;
    }
}
