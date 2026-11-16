<?php

namespace App\Services\Auth;

use App\Contracts\GoogleIdentityProvider;
use App\Exceptions\AuthDomainException;

final class UnavailableGoogleIdentityProvider implements GoogleIdentityProvider
{
    public function available(): bool
    {
        return false;
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge, string $redirectUri): string
    {
        throw $this->unavailable();
    }

    public function exchange(string $code, string $codeVerifier, string $expectedNonce, string $redirectUri): array
    {
        throw $this->unavailable();
    }

    private function unavailable(): AuthDomainException
    {
        return new AuthDomainException('GOOGLE_OIDC_LIBRARY_MISSING', 503,
            'Google OAuth está deshabilitado hasta instalar un validador OIDC compatible.');
    }
}
