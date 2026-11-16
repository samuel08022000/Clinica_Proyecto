<?php

namespace App\Services\Auth;

use App\Exceptions\AuthDomainException;
use Illuminate\Cache\RateLimiter;

final class LoginAttemptService
{
    private const MAX_ATTEMPTS = 5;

    private const MAX_EMAIL_ATTEMPTS = 15;

    private const DECAY_SECONDS = 900;

    public function __construct(private RateLimiter $limiter) {}

    public function ensureAllowed(string $email, string $ip): void
    {
        $key = $this->key($email, $ip);
        if ($this->limiter->tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new AuthDomainException('TOO_MANY_ATTEMPTS', 429,
                'Se alcanzó el límite de intentos. Espera antes de volver a intentarlo.',
                $this->limiter->availableIn($key));
        }
        $emailKey = $this->emailKey($email);
        if ($this->limiter->tooManyAttempts($emailKey, self::MAX_EMAIL_ATTEMPTS)) {
            throw new AuthDomainException('TOO_MANY_ATTEMPTS', 429,
                'Se alcanzó el límite de intentos. Espera antes de volver a intentarlo.',
                $this->limiter->availableIn($emailKey));
        }
    }

    public function failure(string $email, string $ip): void
    {
        $this->limiter->hit($this->key($email, $ip), self::DECAY_SECONDS);
        $this->limiter->hit($this->emailKey($email), self::DECAY_SECONDS);
    }

    public function success(string $email, string $ip): void
    {
        $this->limiter->clear($this->key($email, $ip));
        $this->limiter->clear($this->emailKey($email));
    }

    private function key(string $email, string $ip): string
    {
        return 'clinical-auth:'.hash('sha256', mb_strtolower(trim($email)).'|'.$ip);
    }

    private function emailKey(string $email): string
    {
        return 'clinical-auth-email:'.hash('sha256', mb_strtolower(trim($email)));
    }
}
