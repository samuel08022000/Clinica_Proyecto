<?php

namespace App\Services\Medico;

use App\Exceptions\ClinicalOperationException;
use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Clinical\ClinicalSqlException;
use App\Services\Clinical\ClinicalTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

final class MedicoDerivacionService
{
    private const SUMMARY_SQL = <<<'SQL'
        SELECT d.cod_der AS "codDer",d.cod_cit_origen AS "codCitOrigen",c.cod_pac AS "codPac",
               up.nombre_completo AS "nombrePaciente",d.cod_med_emisor AS "codMedEmisor",
               ue.nombre_completo AS "nombreMedicoEmisor",d.cod_esp_destino AS "codEspDestino",
               e.nombre_especialidad AS "nombreEspecialidad",d.cod_med_destino AS "codMedDestino",
               ud.nombre_completo AS "nombreMedicoDestino",d.estado,d.fecha_derivacion AS "fechaDerivacion",
               d.cod_cit_hija AS "codCitHija"
        FROM derivaciones d
        JOIN citas c ON c.cod_cit=d.cod_cit_origen
        JOIN pacientes p ON p.cod_pac=c.cod_pac
        JOIN usuarios up ON up.cod_usu=p.cod_usu
        JOIN medicos me ON me.cod_med=d.cod_med_emisor
        JOIN usuarios ue ON ue.cod_usu=me.cod_usu
        JOIN especialidades e ON e.cod_esp=d.cod_esp_destino
        LEFT JOIN medicos md ON md.cod_med=d.cod_med_destino
        LEFT JOIN usuarios ud ON ud.cod_usu=md.cod_usu
        WHERE d.cod_med_emisor=?
        SQL;

    public function __construct(
        private DatabaseManager $database,
        private ClinicalTransaction $transactions,
        private ClinicalRoleGuard $roles,
        private MedicoAtencionService $appointments,
    ) {}

    public function create(string $appointment, array $data): array
    {
        MedicoAtencionService::identifier($appointment);
        $specialty = MedicoAtencionService::identifier((string) ($data['codEspDestino'] ?? ''));
        $destination = filled($data['codMedDestino'] ?? null)
            ? MedicoAtencionService::identifier((string) $data['codMedDestino']) : null;
        $reason = self::text($data['motivo'] ?? null, 2000, 'motivo');
        $user = $this->roles->require('ROL003');

        return $this->transactions->run(function (Connection $db) use ($appointment, $specialty, $destination, $reason, $user): array {
            $doctor = $this->appointments->physician($db, $user);
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$doctor['codMed']]);
            $origin = $db->selectOne('SELECT estado FROM citas WHERE cod_cit=? AND cod_med=?', [$appointment, $doctor['codMed']]);
            if ($origin === null) {
                self::missing();
            }
            if ($origin->estado !== 'Atendida') {
                throw new ClinicalOperationException(409, 'La cita origen debe estar atendida.', 'DERIVACION_NO_PERMITIDA');
            }
            if (! $db->table('especialidades')->where('cod_esp', $specialty)->where('estado', 'Activo')->exists()) {
                throw new ClinicalOperationException(409, 'La especialidad destino no está habilitada.', 'DESTINO_NO_HABILITADO');
            }
            if ($destination !== null && ! $db->selectOne(<<<'SQL'
                SELECT 1 FROM medico_especialidades me
                JOIN medicos m ON m.cod_med=me.cod_med
                JOIN usuarios u ON u.cod_usu=m.cod_usu
                JOIN roles r ON r.cod_rol=u.cod_rol
                WHERE me.cod_med=? AND me.cod_esp=? AND me.estado='Activo' AND m.estado='Activo'
                  AND u.estado='Activo' AND u.cod_rol='ROL003' AND r.estado='Activo'
                SQL, [$destination, $specialty])) {
                throw new ClinicalOperationException(409, 'El médico destino no está habilitado para esa especialidad.', 'DESTINO_NO_HABILITADO');
            }

            $duplicateSql = 'SELECT cod_der FROM derivaciones WHERE cod_cit_origen=? AND cod_esp_destino=? AND motivo_derivacion=? AND ';
            $bindings = [$appointment, $specialty, $reason];
            if ($destination === null) {
                $duplicateSql .= 'cod_med_destino IS NULL';
            } else {
                $duplicateSql .= 'cod_med_destino=?';
                $bindings[] = $destination;
            }
            $duplicate = $db->selectOne($duplicateSql.' ORDER BY cod_der LIMIT 1', $bindings);
            $code = $duplicate?->cod_der ?? $db->scalar(
                'SELECT crear_derivacion(?,?,?,?,?)',
                [$appointment, $user->cod_usu, $specialty, $reason, $destination],
            );

            return $this->detailRow($db, $doctor['codMed'], (string) $code);
        });
    }

    public function listing(array $filters): array
    {
        return $this->read(function (Connection $db) use ($filters): array {
            $doctor = $this->appointments->physician($db);
            $rawState = $filters['estado'] ?? null;
            if ($rawState !== null && ! is_string($rawState)) {
                throw new ClinicalOperationException(400, 'El estado de derivación no es válido.', 'VALIDACION');
            }
            $state = filled($rawState) ? $rawState : null;
            if ($state !== null && ! in_array($state, ['Pendiente', 'Agendada', 'Completada', 'Cancelada'], true)) {
                throw new ClinicalOperationException(400, 'El estado de derivación no es válido.', 'VALIDACION');
            }
            $page = MedicoAtencionService::page($filters['page'] ?? '0', 0, 10000, 'page');
            $size = MedicoAtencionService::page($filters['size'] ?? '20', 1, 50, 'size');
            $sql = self::SUMMARY_SQL;
            $bindings = [$doctor['codMed']];
            if ($state !== null) {
                $sql .= ' AND d.estado=?';
                $bindings[] = $state;
            }
            array_push($bindings, $size + 1, $page * $size);
            $rows = self::rows($db->select($sql.' ORDER BY d.fecha_derivacion DESC,d.cod_der DESC LIMIT ? OFFSET ?', $bindings));
            $more = count($rows) > $size;

            return ['derivaciones' => array_slice($rows, 0, $size), 'page' => $page, 'size' => $size, 'hayMas' => $more];
        });
    }

    public function detail(string $code): array
    {
        MedicoAtencionService::identifier($code);

        return $this->read(function (Connection $db) use ($code): array {
            $doctor = $this->appointments->physician($db);

            return $this->detailRow($db, $doctor['codMed'], $code);
        });
    }

    public function forAppointment(string $appointment): array
    {
        MedicoAtencionService::identifier($appointment);

        return $this->read(function (Connection $db) use ($appointment): array {
            $doctor = $this->appointments->physician($db);
            if (! $db->table('citas')->where('cod_cit', $appointment)->where('cod_med', $doctor['codMed'])->exists()) {
                self::missing();
            }

            return self::rows($db->select(
                self::SUMMARY_SQL.' AND d.cod_cit_origen=? ORDER BY d.fecha_derivacion DESC,d.cod_der DESC',
                [$doctor['codMed'], $appointment],
            ));
        });
    }

    public static function text(mixed $value, int $maximum, string $field): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '' || str_contains($text, "\0") || mb_strlen($text) > $maximum) {
            throw new ClinicalOperationException(400, "El campo $field requiere entre 1 y $maximum caracteres.", 'VALIDACION');
        }

        return $text;
    }

    private function detailRow(Connection $db, string $doctor, string $code): array
    {
        $row = $db->selectOne(self::SUMMARY_SQL.' AND d.cod_der=?', [$doctor, $code]);

        return $row ? (array) $row : self::missing();
    }

    private static function rows(array $rows): array
    {
        return array_map(fn (object $row): array => (array) $row, $rows);
    }

    private static function missing(): never
    {
        throw new ClinicalOperationException(404, 'No existe el recurso solicitado para el médico autenticado.', 'NO_ENCONTRADO');
    }

    private function read(callable $operation): mixed
    {
        $this->roles->require('ROL003');
        try {
            return $operation($this->database->connection('clinical'));
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }
}
