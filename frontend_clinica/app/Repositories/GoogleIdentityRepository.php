<?php

namespace App\Repositories;

use App\Exceptions\AuthDomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

final class GoogleIdentityRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function findBySubject(string $subject): ?object
    {
        return $this->database->connection('clinical')->selectOne(
            "SELECT cod_identidad, cod_usu, email_proveedor, email_verificado FROM usuarios_identidades_externas WHERE proveedor='google' AND identificador_externo=?",
            [$subject]);
    }

    public function link(string $userId, array $identity): void
    {
        try {
            $this->database->connection('clinical')->insert(
                "INSERT INTO usuarios_identidades_externas(cod_usu,proveedor,identificador_externo,email_proveedor,email_verificado) VALUES (?,'google',?,?,?)",
                [$userId, $identity['subject'], $identity['email'], $identity['emailVerified']]);
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? '') === '23505') {
                throw new AuthDomainException('GOOGLE_IDENTITY_ALREADY_LINKED', 409, 'La cuenta ya tiene una identidad Google vinculada.');
            }

            throw $error;
        }
    }

    public function updateMetadata(string $identityId, array $identity): void
    {
        $this->database->connection('clinical')->update(
            'UPDATE usuarios_identidades_externas SET email_proveedor=?, email_verificado=?, actualizado_en=now() WHERE cod_identidad=?',
            [$identity['email'], $identity['emailVerified'], $identityId]);
    }

    public function registerPatient(array $identity, array $details): string
    {
        try {
            return $this->database->connection('clinical')->transaction(function ($db) use ($identity, $details): string {
                $user = $db->selectOne("INSERT INTO usuarios(nombre_completo,email,password_hash,cod_rol,estado) VALUES (?,?,NULL,'ROL001','Activo') RETURNING cod_usu",
                    [$identity['name'], $identity['email']]);
                $db->selectOne("INSERT INTO pacientes(cod_usu,ci,fecha_nacimiento,telefono,telefono_verificado,email_verificado,email_verificado_en,ciudad_residencia,estado_cuenta) VALUES (?,?,?::date,?,false,?,CASE WHEN ? THEN now() ELSE NULL END,?,'Activo') RETURNING cod_pac",
                    [$user->cod_usu, $details['ci'], $details['fecha_nacimiento'], $details['telefono'], $identity['emailVerified'], $identity['emailVerified'], $details['ciudad_residencia']]);
                $this->link($user->cod_usu, $identity);

                return $user->cod_usu;
            }, 1);
        } catch (QueryException $error) {
            $constraint = (string) ($error->errorInfo[2] ?? '');

            throw match (true) {
                str_contains($constraint, 'uq_pacientes_ci_normalizado') => new AuthDomainException('DOCUMENT_ALREADY_REGISTERED', 409, 'El documento ya está registrado.'),
                ($error->errorInfo[0] ?? '') === '23505' => new AuthDomainException('GOOGLE_ACCOUNT_CONFLICT', 409, 'No fue posible completar el registro con esa identidad de Google.'),
                default => new AuthDomainException('AUTH_OPERATION_FAILED', 500, 'No fue posible completar el registro con Google.'),
            };
        }
    }
}
