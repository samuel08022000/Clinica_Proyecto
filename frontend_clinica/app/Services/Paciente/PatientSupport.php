<?php

namespace App\Services\Paciente;

use App\Exceptions\ClinicalOperationException;
use App\Services\Clinical\ClinicalSqlException;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/** Contrato interno compartido de validación y resolución del paciente autenticado. */
final class PatientSupport
{
    public static function read(DatabaseManager $database, Closure $operation): array
    {
        try {
            return $operation($database->connection('clinical'));
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }

    public static function patient(Connection $db, string $userCode): array
    {
        $row = $db->selectOne(
            <<<'SQL'
            SELECT p.cod_pac AS "codPac", p.cod_usu AS "codUsu", u.nombre_completo AS "nombreCompleto",
                   u.email, p.ci, p.fecha_nacimiento AS "fechaNacimiento", p.telefono,
                   p.ciudad_residencia AS "ciudadResidencia", p.estado_cuenta AS "estadoCuenta",
                   p.bloqueado_hasta AS "bloqueadoHasta", p.email_verificado AS "emailVerificado",
                   public.estado_reserva_paciente(p.cod_pac) AS "estadoReserva"
            FROM pacientes p
            JOIN usuarios u ON u.cod_usu=p.cod_usu
            WHERE p.cod_usu=? AND u.cod_rol='ROL001'
            SQL,
            [$userCode],
        );
        if (! $row) {
            throw new ClinicalOperationException(403, 'La cuenta no tiene un perfil de paciente activo.', 'PERFIL_INACTIVO');
        }
        $patient = (array) $row;
        $patient['habilitadoParaReservar'] = $patient['estadoReserva'] === 'Activo';

        return $patient;
    }

    public static function identifier(string $value, string $field = 'identificador'): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 64 || preg_match('/\A[A-Za-z0-9]+\z/', $trimmed) !== 1) {
            throw new ClinicalOperationException(400, "El campo $field no tiene un formato válido.", 'VALIDACION');
        }

        return $trimmed;
    }

    public static function date(string $value, string $field): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (! $parsed || $parsed->format('Y-m-d') !== trim($value)) {
            throw new ClinicalOperationException(400, "La fecha en $field no es válida.", 'FECHA_INVALIDA');
        }

        return $parsed->format('Y-m-d');
    }

    public static function page(mixed $value, int $min, int $max, string $field): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/\A\d+\z/', trim($value)) === 1)) {
            throw new ClinicalOperationException(400, "El parámetro $field no es válido.", 'VALIDACION');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new ClinicalOperationException(400, "El parámetro $field está fuera del rango permitido.", 'VALIDACION');
        }

        return $number;
    }

    public static function text(mixed $value, int $max, string $field): string
    {
        if (! is_string($value)) {
            throw new ClinicalOperationException(400, "El campo $field es obligatorio.", 'VALIDACION');
        }
        $trimmed = trim($value);
        if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > $max) {
            throw new ClinicalOperationException(400, "El campo $field debe tener entre 1 y $max caracteres.", 'VALIDACION');
        }

        return $trimmed;
    }

    /** @return array<int, array<string, mixed>> */
    public static function rows(array $rows): array
    {
        return array_map(static fn (object $row): array => (array) $row, $rows);
    }

    public static function missing(string $message): never
    {
        throw new ClinicalOperationException(404, $message, 'NO_ENCONTRADO');
    }
}
