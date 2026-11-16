<?php

namespace App\Console\Commands;

use App\Services\Auth\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** Provisionamiento local, interactivo y de un solo uso; nunca crea credenciales predeterminadas. */
final class BootstrapClinicalAdministrator extends Command
{
    protected $signature = 'clinical:bootstrap-admin';

    protected $description = 'Crea interactivamente el primer administrador clínico cuando aún no existe ninguno';

    public function handle(DatabaseManager $database): int
    {
        $db = $database->connection('clinical');
        if ($db->table('usuarios')->where('cod_rol', 'ROL004')->exists()) {
            $this->error('Ya existe una identidad administradora; el aprovisionamiento inicial fue rechazado.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Nombre completo'));
        $email = mb_strtolower(trim((string) $this->ask('Correo')));
        $password = (string) $this->secret('Contraseña (no se mostrará)');
        $confirmation = (string) $this->secret('Confirma la contraseña');
        $validation = Validator::make(compact('name', 'email'), [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'email', 'max:254'],
        ]);
        if ($validation->fails() || ! hash_equals($password, $confirmation)) {
            $this->error('Los datos no son válidos o las contraseñas no coinciden.');

            return self::INVALID;
        }

        try {
            PasswordPolicy::validate($password);
            $code = $db->transaction(function ($connection) use ($name, $email, $password) {
                $connection->selectOne("SELECT pg_advisory_xact_lock(hashtextextended('bootstrap-admin',0))");
                if ($connection->table('usuarios')->where('cod_rol', 'ROL004')->exists()) {
                    throw new \LogicException('Administrador ya provisionado.');
                }

                return $connection->selectOne("INSERT INTO usuarios(nombre_completo,email,password_hash,cod_rol,estado) VALUES (?,?,?,'ROL004','Activo') RETURNING cod_usu", [$name, $email, Hash::make($password)])->cod_usu;
            });
            $this->info('Administrador clínico creado: '.$code.'. La contraseña no fue registrada ni mostrada.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No se pudo crear la identidad. Verifica duplicados, política de contraseña y conexión clínica.');

            return self::FAILURE;
        } finally {
            unset($password, $confirmation);
        }
    }
}
