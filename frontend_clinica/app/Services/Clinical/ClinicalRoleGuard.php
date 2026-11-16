<?php

namespace App\Services\Clinical;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use Illuminate\Auth\AuthManager;
use Illuminate\Database\DatabaseManager;

/** Autoriza también dentro del servicio; el middleware no es la única barrera. */
final class ClinicalRoleGuard
{
    public function __construct(private AuthManager $auth, private DatabaseManager $database) {}

    public function require(string $role): Usuario
    {
        $user = $this->auth->guard('web')->user();
        if (! $user instanceof Usuario || $user->cod_rol !== $role || $user->estado !== 'Activo') {
            throw new ClinicalOperationException(403, 'No tienes permisos para realizar esta operación.', 'ROL_INSUFICIENTE');
        }

        $db = $this->database->connection('clinical');
        $profile = match ($role) {
            'ROL001' => $db->table('pacientes')
                ->where('cod_usu', $user->cod_usu)
                ->where('estado_cuenta', '!=', 'Inactivo')
                ->where('email_verificado', true)
                ->exists(),
            'ROL002' => $db->table('secretarias')->where('cod_usu', $user->cod_usu)->where('estado', 'Activo')->exists(),
            'ROL003' => $db->table('medicos')->where('cod_usu', $user->cod_usu)->where('estado', 'Activo')->exists(),
            'ROL004' => true,
            default => false,
        };
        $roleActive = $db->table('roles')->where('cod_rol', $role)->where('estado', 'Activo')->exists();
        if (! $profile || ! $roleActive) {
            throw new ClinicalOperationException(403, 'La cuenta o su perfil ya no está habilitado.', 'PERFIL_INACTIVO');
        }

        return $user;
    }
}
