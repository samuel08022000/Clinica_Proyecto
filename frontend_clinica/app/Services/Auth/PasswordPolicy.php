<?php

namespace App\Services\Auth;

use App\Exceptions\AuthDomainException;

final class PasswordPolicy
{
    public static function validate(string $password): void
    {
        if (mb_strlen($password) < 10 || strlen($password) > 72
            || ! preg_match('/\p{Lu}/u', $password)
            || ! preg_match('/\p{Ll}/u', $password)
            || ! preg_match('/[0-9]/', $password)) {
            throw new AuthDomainException('VALIDATION_ERROR', 422,
                'La contraseña debe tener al menos 10 caracteres, máximo 72 bytes, mayúsculas, minúsculas y números.');
        }
    }
}
