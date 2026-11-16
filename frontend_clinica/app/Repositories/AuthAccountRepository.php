<?php

namespace App\Repositories;

use App\Models\Usuario;
use Illuminate\Database\DatabaseManager;

final class AuthAccountRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function findByEmail(string $email): ?Usuario
    {
        return Usuario::query()->whereRaw('lower(trim(email)) = ?', [mb_strtolower(trim($email))])->first();
    }

    public function findById(string $userId): ?Usuario
    {
        return Usuario::query()->find($userId);
    }

    public function status(string $userId): ?object
    {
        return $this->database->connection('clinical')->selectOne(<<<'SQL'
            SELECT u.cod_usu, u.nombre_completo, lower(trim(u.email)) AS email,
                   u.password_hash, u.cod_rol, u.estado AS usuario_estado,
                   r.nombre_rol, r.estado AS rol_estado,
                   CASE u.cod_rol
                     WHEN 'ROL001' THEN (SELECT public.estado_reserva_paciente(p.cod_pac) FROM pacientes p WHERE p.cod_usu=u.cod_usu)
                     WHEN 'ROL002' THEN (SELECT s.estado FROM secretarias s WHERE s.cod_usu=u.cod_usu)
                     WHEN 'ROL003' THEN (SELECT m.estado FROM medicos m WHERE m.cod_usu=u.cod_usu)
                     WHEN 'ROL004' THEN u.estado
                   END AS perfil_estado,
                   CASE u.cod_rol
                     WHEN 'ROL001' THEN EXISTS(SELECT 1 FROM pacientes p WHERE p.cod_usu=u.cod_usu)
                     WHEN 'ROL002' THEN EXISTS(SELECT 1 FROM secretarias s WHERE s.cod_usu=u.cod_usu)
                     WHEN 'ROL003' THEN EXISTS(SELECT 1 FROM medicos m WHERE m.cod_usu=u.cod_usu)
                     WHEN 'ROL004' THEN true ELSE false
                   END AS perfil_presente,
                   (SELECT p.email_verificado FROM pacientes p WHERE p.cod_usu=u.cod_usu) AS email_verificado,
                   (SELECT p.bloqueado_hasta FROM pacientes p WHERE p.cod_usu=u.cod_usu) AS bloqueado_hasta
            FROM usuarios u JOIN roles r ON r.cod_rol=u.cod_rol
            WHERE u.cod_usu=?
            SQL, [$userId]);
    }

    public function byEmailStatus(string $email): ?object
    {
        $user = $this->findByEmail($email);

        return $user ? $this->status($user->cod_usu) : null;
    }
}
