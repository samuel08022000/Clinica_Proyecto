<?php

namespace App\Contracts;

interface GoogleIdentityProvider
{
    public function available(): bool;

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge, string $redirectUri): string;

    /** Retorna subject, email, name y emailVerified solo después de validación OIDC criptográfica completa. */
    public function exchange(string $code, string $codeVerifier, string $expectedNonce, string $redirectUri): array;
}
