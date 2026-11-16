<?php

namespace App\Services\Auth;

use App\Exceptions\AuthDomainException;
use App\Repositories\AuthAccountRepository;
use Carbon\CarbonImmutable;

class AccountStatusService
{
    public const ROLES = ['ROL001', 'ROL002', 'ROL003', 'ROL004'];

    public function __construct(private AuthAccountRepository $accounts) {}

    public function requireActive(string $userId, bool $requireVerifiedEmail = true): object
    {
        $account = $this->accounts->status($userId);
        if (! $account) {
            throw new AuthDomainException('ACCOUNT_NOT_FOUND', 401, 'La cuenta ya no está disponible.');
        }
        if (! in_array($account->cod_rol, self::ROLES, true)) {
            throw new AuthDomainException('ROLE_NOT_ALLOWED', 403, 'La cuenta no tiene un rol autorizado.');
        }
        if ($account->usuario_estado !== 'Activo') {
            throw new AuthDomainException('ACCOUNT_DISABLED', 403, 'La cuenta no está habilitada.');
        }
        if ($account->rol_estado !== 'Activo') {
            throw new AuthDomainException('ROLE_DISABLED', 403, 'El rol de la cuenta no está habilitado.');
        }
        if (! $account->perfil_presente) {
            throw new AuthDomainException('PROFILE_UNAVAILABLE', 403, 'La cuenta no tiene un perfil habilitado.');
        }
        if ($account->perfil_estado === 'Bloqueado_Temporal') {
            throw new AuthDomainException('ACCOUNT_TEMPORARILY_BLOCKED', 423, 'La cuenta está bloqueada temporalmente.');
        }
        if ($account->perfil_estado !== 'Activo') {
            throw new AuthDomainException('PROFILE_DISABLED', 403, 'El perfil de la cuenta no está habilitado.');
        }
        if ($requireVerifiedEmail && $account->cod_rol === 'ROL001' && $account->email_verificado !== true) {
            throw new AuthDomainException('EMAIL_VERIFICATION_REQUIRED', 403, 'Debes verificar tu correo antes de iniciar sesión.');
        }
        if ($account->bloqueado_hasta && CarbonImmutable::parse($account->bloqueado_hasta)->isFuture()) {
            throw new AuthDomainException('ACCOUNT_TEMPORARILY_BLOCKED', 423, 'La cuenta está bloqueada temporalmente.');
        }

        return $account;
    }
}
