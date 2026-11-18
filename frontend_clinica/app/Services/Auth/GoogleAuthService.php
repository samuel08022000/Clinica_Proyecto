<?php

namespace App\Services\Auth;

use App\Contracts\GoogleIdentityProvider;
use App\Exceptions\AuthDomainException;
use App\Repositories\AuthAccountRepository;
use App\Repositories\GoogleIdentityRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;

final class GoogleAuthService
{
    public function __construct(
        private GoogleIdentityProvider $provider, private GoogleIdentityRepository $identities,
        private AuthAccountRepository $accounts, private ChallengeStore $challenges,
        private AuthenticationService $authentication, private AccountStatusService $status,
        private DatabaseManager $database, private OtpService $otp,
    ) {}

    public function available(): bool
    {
        return $this->provider->available() && (bool) config('clinic.features.google_oauth');
    }

    public function authorizationUrl(array $flow): string
    {
        $this->requireAvailable();

        return $this->provider->authorizationUrl($flow['state'], $flow['nonce'], $flow['challenge'], $flow['redirect_uri']);
    }

    public function exchange(Request $request, string $code, array $flow): array
    {
        $this->requireAvailable();

        $identity = $this->validateIdentity($this->provider->exchange(
            $code, $flow['verifier'], $flow['nonce'], $flow['redirect_uri']));
        $linked = $this->identities->findBySubject($identity['subject']);
        if ($linked) {
            $account = $this->status->requireActive($linked->cod_usu, false);
            if ($account->cod_rol !== 'ROL001') {
                throw new AuthDomainException('GOOGLE_PATIENT_ONLY', 403, 'Google está disponible solo para pacientes.');
            }
            $this->identities->updateMetadata($linked->cod_identidad, $identity);
            if ($account->email_verificado !== true) {
                return ['status' => 'EMAIL_VERIFICATION_REQUIRED', 'verification' => $this->otp->issue($account->cod_usu, $account->email)];
            }
            $this->authentication->trustedLogin($request, $account->cod_usu);

            return ['status' => 'AUTHENTICATED', 'role' => 'ROL001'];
        }
        if ($this->accounts->findByEmail($identity['email'])) {
            return ['status' => 'LINK_REQUIRED', 'continuationToken' => $this->continuation('google-link', $identity)];
        }

        return ['status' => 'REGISTRATION_REQUIRED', 'continuationToken' => $this->continuation('google-register', $identity),
            'suggestedName' => $identity['name'], 'email' => $identity['email']];
    }

    public function register(Request $request, string $token, array $details): array
    {
        $this->requireAvailable();

        [$userId, $identity] = $this->database->connection()->transaction(function () use ($token, $details): array {
            $continuation = $this->challenges->claim($token, 'google-register')
                ?? throw new AuthDomainException('GOOGLE_CONTINUATION_INVALID', 401, 'La continuación expiró o ya fue utilizada.');
            $identity = $this->validateIdentity($continuation->payload);
            if ($this->identities->findBySubject($identity['subject']) || $this->accounts->findByEmail($identity['email'])) {
                throw new AuthDomainException('GOOGLE_ACCOUNT_CONFLICT', 409, 'No fue posible completar el registro con Google.');
            }

            return [$this->identities->registerPatient($identity, $details), $identity];
        }, 1);
        if (! $identity['emailVerified']) {
            return ['status' => 'EMAIL_VERIFICATION_REQUIRED', 'verification' => $this->otp->issue($userId, $identity['email'])];
        }
        $account = $this->authentication->trustedLogin($request, $userId);

        return ['status' => 'AUTHENTICATED', 'account' => $account];
    }

    public function linkAuthenticated(string $userId, string $token): void
    {
        $this->requireAvailable();

        $continuation = $this->challenges->claim($token, 'google-link')
            ?? throw new AuthDomainException('GOOGLE_CONTINUATION_INVALID', 401, 'La continuación expiró o ya fue utilizada.');
        $identity = $this->validateIdentity($continuation->payload);
        $account = $this->status->requireActive($userId);
        if ($account->cod_rol !== 'ROL001' || ! hash_equals($account->email, $identity['email'])) {
            throw new AuthDomainException('GOOGLE_LINK_NOT_ALLOWED', 403, 'No fue posible vincular la cuenta Google.');
        }
        if ($this->identities->findBySubject($identity['subject'])) {
            throw new AuthDomainException('GOOGLE_IDENTITY_ALREADY_LINKED', 409, 'La identidad Google ya está vinculada.');
        }
        $this->database->connection('clinical')->transaction(function ($db) use ($userId, $identity): void {
            $db->selectOne("SELECT set_config('app.cod_usu', ?, true)", [$userId]);
            $this->identities->link($userId, $identity);
        }, 1);
    }

    private function continuation(string $purpose, array $identity): string
    {
        return $this->challenges->issue($purpose, null, $identity, 600);
    }

    private function requireAvailable(): void
    {
        if (! $this->available()) {
            throw new AuthDomainException('GOOGLE_OAUTH_NOT_CONFIGURED', 503, 'Google OAuth no está configurado.');
        }
    }

    private function validateIdentity(array $identity): array
    {
        $email = mb_strtolower(trim((string) ($identity['email'] ?? '')));
        $name = trim((string) ($identity['name'] ?? ''));
        $subject = trim((string) ($identity['subject'] ?? ''));
        if ($subject === '' || strlen($subject) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) < 3) {
            throw new AuthDomainException('GOOGLE_ID_TOKEN_INVALID', 401, 'No fue posible validar la identidad Google.');
        }

        return ['subject' => $subject, 'email' => $email, 'name' => mb_substr($name, 0, 150), 'emailVerified' => ($identity['emailVerified'] ?? false) === true];
    }
}
