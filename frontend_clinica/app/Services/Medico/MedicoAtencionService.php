<?php

namespace App\Services\Medico;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Clinical\ClinicalSqlException;
use App\Services\Clinical\ClinicalTransaction;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

final class MedicoAtencionService
{
    private const CITA_SQL = <<<'SQL'
        SELECT c.cod_cit AS "codCit", c.cod_pac AS "codPac", up.nombre_completo AS "nombrePaciente",
               c.cod_esp AS "codEsp", e.nombre_especialidad AS "nombreEspecialidad",
               c.fecha, c.hora_inicio AS "horaInicio", c.hora_fin AS "horaFin", c.estado,
               c.canal_reserva AS "canalReserva", c.hora_llegada_checkin AS "horaLlegadaCheckin",
               c.checkin_por_usu AS "checkinPorUsu", c.fecha_atencion AS "fechaAtencion",
               c.atendida_por_usu AS "atendidaPorUsu"
        FROM citas c
        JOIN pacientes p ON p.cod_pac=c.cod_pac
        JOIN usuarios up ON up.cod_usu=p.cod_usu
        JOIN especialidades e ON e.cod_esp=c.cod_esp
        WHERE c.cod_med=?
        SQL;

    public function __construct(
        private DatabaseManager $database,
        private ClinicalTransaction $transactions,
        private ClinicalRoleGuard $roles,
    ) {}

    public function dashboard(): array
    {
        return $this->read(function (Connection $db): array {
            $doctor = $this->physician($db);
            $date = (string) $db->scalar('SELECT fecha_clinica()');
            $appointments = $this->agendaRows($db, $doctor['codMed'], $date);
            $pendingReferrals = (int) $db->scalar(
                "SELECT count(*) FROM derivaciones WHERE cod_med_emisor=? AND estado='Pendiente'",
                [$doctor['codMed']],
            );

            return [
                'medico' => $doctor,
                'fecha' => $date,
                'citas' => $appointments,
                'resumen' => [
                    'citasHoy' => count($appointments),
                    'enEspera' => count(array_filter($appointments, fn (array $row): bool => $row['estado'] === 'En Espera')),
                    'atendidas' => count(array_filter($appointments, fn (array $row): bool => $row['estado'] === 'Atendida')),
                    'derivacionesPendientes' => $pendingReferrals,
                ],
            ];
        });
    }

    public function agenda(?string $date): array
    {
        return $this->read(function (Connection $db) use ($date): array {
            $doctor = $this->physician($db);
            $resolved = $date === null ? (string) $db->scalar('SELECT fecha_clinica()') : self::date($date, 'fecha');

            return ['fecha' => $resolved, 'citas' => $this->agendaRows($db, $doctor['codMed'], $resolved)];
        });
    }

    public function appointment(string $code): array
    {
        self::identifier($code);

        return $this->read(function (Connection $db) use ($code): array {
            $doctor = $this->physician($db);

            return $this->appointmentRow($db, $doctor['codMed'], $code);
        });
    }

    public function history(array $filters): array
    {
        return $this->read(function (Connection $db) use ($filters): array {
            $doctor = $this->physician($db);
            $page = self::page($filters['page'] ?? '0', 0, 10000, 'page');
            $size = self::page($filters['size'] ?? '20', 1, 50, 'size');
            $from = array_key_exists('desde', $filters) ? self::date((string) $filters['desde'], 'desde') : null;
            $to = array_key_exists('hasta', $filters) ? self::date((string) $filters['hasta'], 'hasta') : null;
            if ($from !== null && $to !== null && $from > $to) {
                throw new ClinicalOperationException(400, 'El rango de fechas no es válido.', 'VALIDACION');
            }
            $sql = self::CITA_SQL." AND c.estado='Atendida'";
            $bindings = [$doctor['codMed']];
            if ($from !== null) {
                $sql .= ' AND c.fecha>=?';
                $bindings[] = $from;
            }
            if ($to !== null) {
                $sql .= ' AND c.fecha<=?';
                $bindings[] = $to;
            }
            array_push($bindings, $size + 1, $page * $size);
            $rows = self::rows($db->select($sql.' ORDER BY c.fecha DESC,c.hora_inicio DESC,c.cod_cit DESC LIMIT ? OFFSET ?', $bindings));
            $more = count($rows) > $size;

            return ['citas' => array_slice($rows, 0, $size), 'page' => $page, 'size' => $size, 'hayMas' => $more];
        });
    }

    public function finish(string $code): array
    {
        self::identifier($code);
        $user = $this->roles->require('ROL003');

        return $this->transactions->run(function (Connection $db) use ($code, $user): array {
            $doctor = $this->physician($db, $user);
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$doctor['codMed']]);
            $row = $db->selectOne(
                'SELECT estado,hora_llegada_checkin,checkin_por_usu,fecha_atencion,atendida_por_usu FROM citas WHERE cod_cit=? AND cod_med=?',
                [$code, $doctor['codMed']],
            );
            if ($row === null) {
                self::missingAppointment();
            }
            if ($row->estado !== 'En Espera' || $row->hora_llegada_checkin === null || $row->checkin_por_usu === null
                || $row->fecha_atencion !== null || $row->atendida_por_usu !== null) {
                throw new ClinicalOperationException(
                    409,
                    'Solo puede finalizarse una cita en espera, con check-in completo y sin atención previa.',
                    'ATENCION_NO_PERMITIDA',
                );
            }
            $db->scalar('SELECT marcar_cita_atendida(?,?)', [$code, $user->cod_usu]);

            return $this->appointmentRow($db, $doctor['codMed'], $code);
        }, 'doctor.appointment.finished', ['entity_id' => $code]);
    }

    public function specialties(): array
    {
        return $this->read(function (Connection $db): array {
            $this->physician($db);

            return self::rows($db->select(
                "SELECT cod_esp AS \"codEsp\",nombre_especialidad AS \"nombreEspecialidad\" FROM especialidades WHERE estado='Activo' ORDER BY nombre_especialidad,cod_esp",
            ));
        });
    }

    public function eligibleDoctors(): array
    {
        return $this->read(function (Connection $db): array {
            $this->physician($db);

            return self::rows($db->select(<<<'SQL'
                SELECT me.cod_esp AS "codEsp",m.cod_med AS "codMed",u.nombre_completo AS "nombreCompleto"
                FROM medico_especialidades me
                JOIN medicos m ON m.cod_med=me.cod_med
                JOIN usuarios u ON u.cod_usu=m.cod_usu
                JOIN roles r ON r.cod_rol=u.cod_rol
                JOIN especialidades e ON e.cod_esp=me.cod_esp
                WHERE me.estado='Activo' AND m.estado='Activo' AND u.estado='Activo'
                  AND u.cod_rol='ROL003' AND r.estado='Activo' AND e.estado='Activo'
                ORDER BY me.cod_esp,u.nombre_completo,m.cod_med
                SQL));
        });
    }

    /** @return array{codMed:string,codUsu:string,nombreCompleto:string} */
    public function physician(Connection $db, ?Usuario $authenticated = null): array
    {
        $user = $authenticated ?? $this->roles->require('ROL003');
        $row = $db->selectOne(<<<'SQL'
            SELECT m.cod_med AS "codMed",m.cod_usu AS "codUsu",u.nombre_completo AS "nombreCompleto"
            FROM medicos m
            JOIN usuarios u ON u.cod_usu=m.cod_usu
            JOIN roles r ON r.cod_rol=u.cod_rol
            WHERE m.cod_usu=? AND m.estado='Activo' AND u.estado='Activo'
              AND u.cod_rol='ROL003' AND r.estado='Activo'
            SQL, [$user->cod_usu]);
        if ($row === null) {
            throw new ClinicalOperationException(403, 'El perfil médico no está habilitado.', 'MEDICO_NO_HABILITADO');
        }

        return (array) $row;
    }

    public static function identifier(string $value): string
    {
        if (! preg_match('/^[A-Za-z0-9]{1,64}$/D', $value)) {
            throw new ClinicalOperationException(400, 'El identificador no es válido.', 'VALIDACION');
        }

        return $value;
    }

    public static function page(mixed $value, int $minimum, int $maximum, string $name): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
            $number = (int) $value;
        } else {
            throw new ClinicalOperationException(400, "$name debe ser un número entero válido.", 'VALIDACION');
        }
        if ($number < $minimum || $number > $maximum) {
            throw new ClinicalOperationException(400, "$name debe estar entre $minimum y $maximum.", 'VALIDACION');
        }

        return $number;
    }

    private static function date(string $value, string $field): string
    {
        if (! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            throw new ClinicalOperationException(400, "El campo $field debe usar el formato YYYY-MM-DD.", 'VALIDACION');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value || $date->format('Y') === '0000') {
            throw new ClinicalOperationException(400, "El campo $field debe contener una fecha válida.", 'VALIDACION');
        }

        return $value;
    }

    private function agendaRows(Connection $db, string $doctor, string $date): array
    {
        return self::rows($db->select(self::CITA_SQL.' AND c.fecha=? ORDER BY c.hora_inicio,c.cod_cit', [$doctor, $date]));
    }

    private function appointmentRow(Connection $db, string $doctor, string $code): array
    {
        $row = $db->selectOne(self::CITA_SQL.' AND c.cod_cit=?', [$doctor, $code]);
        if ($row === null) {
            self::missingAppointment();
        }
        $result = (array) $row;
        $result['checkinRegistrado'] = $result['horaLlegadaCheckin'] !== null && $result['checkinPorUsu'] !== null;

        return $result;
    }

    private static function rows(array $rows): array
    {
        return array_map(function (object $row): array {
            $result = (array) $row;
            if (array_key_exists('horaLlegadaCheckin', $result)) {
                $result['checkinRegistrado'] = $result['horaLlegadaCheckin'] !== null && $result['checkinPorUsu'] !== null;
            }

            return $result;
        }, $rows);
    }

    private static function missingAppointment(): never
    {
        throw new ClinicalOperationException(404, 'No existe la cita para el médico autenticado.', 'NO_ENCONTRADO');
    }

    private function read(callable $operation): mixed
    {
        try {
            return $operation($this->database->connection('clinical'));
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }
}
