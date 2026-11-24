<?php

namespace App\Services\Medico;

use App\Exceptions\ClinicalOperationException;
use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Clinical\ClinicalSqlException;
use App\Services\Clinical\ClinicalTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

final class MedicoAnalisisService
{
    private const SUMMARY_SQL = <<<'SQL'
        SELECT a.cod_ana AS "codAna",a.cod_cit AS "codCit",a.solicitado_por_usu AS "solicitadoPorUsu",
               a.tipo_analisis AS "tipoAnalisis",a.estado,a.resultado,a.fecha_registro AS "fechaRegistro",
               a.fecha_entrega_estimada AS "fechaEntregaEstimada",a.fecha_entrega_real AS "fechaEntregaReal"
        FROM analisis a JOIN citas c ON c.cod_cit=a.cod_cit
        WHERE c.cod_med=?
        SQL;

    public function __construct(
        private DatabaseManager $database,
        private ClinicalTransaction $transactions,
        private ClinicalRoleGuard $roles,
        private MedicoAtencionService $appointments,
    ) {}

    public function request(string $appointment, array $data): array
    {
        MedicoAtencionService::identifier($appointment);
        $type = MedicoDerivacionService::text($data['tipoAnalisis'] ?? null, 150, 'tipoAnalisis');
        $user = $this->roles->require('ROL003');

        return $this->transactions->run(function (Connection $db) use ($appointment, $type, $user): array {
            $doctor = $this->appointments->physician($db, $user);
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$doctor['codMed']]);
            $origin = $db->selectOne('SELECT estado FROM citas WHERE cod_cit=? AND cod_med=?', [$appointment, $doctor['codMed']]);
            if ($origin === null) {
                self::missing();
            }
            self::assertAppointmentState($origin->estado);
            $previous = $db->selectOne(
                'SELECT cod_ana FROM analisis WHERE cod_cit=? AND lower(trim(tipo_analisis))=lower(?) ORDER BY cod_ana LIMIT 1',
                [$appointment, $type],
            );
            $code = $previous?->cod_ana;
            if ($code === null) {
                $code = $db->scalar(
                    'INSERT INTO analisis(cod_cit,solicitado_por_usu,tipo_analisis) VALUES (?,?,?) RETURNING cod_ana',
                    [$appointment, $user->cod_usu, $type],
                );
            }

            return $this->detailRow($db, $doctor['codMed'], (string) $code);
        }, 'doctor.analysis.request_processed', []);
    }

    public function listing(string $appointment): array
    {
        MedicoAtencionService::identifier($appointment);

        return $this->read(function (Connection $db) use ($appointment): array {
            $doctor = $this->appointments->physician($db);
            if (! $db->table('citas')->where('cod_cit', $appointment)->where('cod_med', $doctor['codMed'])->exists()) {
                self::missing();
            }

            return self::rows($db->select(
                self::SUMMARY_SQL.' AND a.cod_cit=? ORDER BY a.fecha_registro,a.cod_ana',
                [$doctor['codMed'], $appointment],
            ));
        });
    }

    public function update(string $code, array $data): array
    {
        MedicoAtencionService::identifier($code);
        $state = (string) ($data['estado'] ?? '');
        if (! in_array($state, ['En Proceso', 'Completado'], true)) {
            throw new ClinicalOperationException(400, 'El estado debe ser En Proceso o Completado.', 'VALIDACION');
        }
        $result = $state === 'Completado'
            ? MedicoDerivacionService::text($data['resultado'] ?? null, 20000, 'resultado') : null;
        if ($state === 'En Proceso' && filled($data['resultado'] ?? null)) {
            throw new ClinicalOperationException(400, 'El resultado se registra únicamente al completar el análisis.', 'VALIDACION');
        }
        $user = $this->roles->require('ROL003');

        return $this->transactions->run(function (Connection $db) use ($code, $state, $result, $user): array {
            $doctor = $this->appointments->physician($db, $user);
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$doctor['codMed']]);
            $owner = $db->selectOne(
                'SELECT a.cod_cit FROM analisis a JOIN citas c ON c.cod_cit=a.cod_cit WHERE a.cod_ana=? AND c.cod_med=?',
                [$code, $doctor['codMed']],
            );
            if ($owner === null) {
                self::missing();
            }
            $appointment = $db->selectOne('SELECT estado FROM citas WHERE cod_cit=? AND cod_med=?', [$owner->cod_cit, $doctor['codMed']]);
            if ($appointment === null) {
                self::missing();
            }
            self::assertAppointmentState($appointment->estado);
            $analysis = $db->selectOne('SELECT * FROM analisis WHERE cod_ana=?', [$code]);
            if ($analysis === null || $analysis->solicitado_por_usu !== $user->cod_usu) {
                self::missing();
            }
            if (! in_array($analysis->estado, ['Pendiente', 'En Proceso'], true)
                || $analysis->resultado !== null || $analysis->archivo_pdf_url !== null
                || $analysis->fecha_entrega_real !== null
                || ($analysis->estado === 'En Proceso' && $state === 'En Proceso')) {
                throw new ClinicalOperationException(
                    409,
                    'El análisis no admite esa transición o ya tiene un resultado final.',
                    'ANALISIS_NO_MODIFICABLE',
                );
            }
            $db->update('UPDATE analisis SET estado=?,resultado=? WHERE cod_ana=?', [$state, $result, $code]);

            return $this->detailRow($db, $doctor['codMed'], $code);
        }, 'doctor.analysis.status_changed', ['entity_id' => $code]);
    }

    private function detailRow(Connection $db, string $doctor, string $code): array
    {
        $row = $db->selectOne(self::SUMMARY_SQL.' AND a.cod_ana=?', [$doctor, $code]);

        return $row ? (array) $row : self::missing();
    }

    private static function assertAppointmentState(string $state): void
    {
        if (! in_array($state, ['En Espera', 'Atendida'], true)) {
            throw new ClinicalOperationException(409, 'La cita debe estar en espera o atendida.', 'ANALISIS_NO_PERMITIDO');
        }
    }

    private static function rows(array $rows): array
    {
        return array_map(fn (object $row): array => (array) $row, $rows);
    }

    private static function missing(): never
    {
        throw new ClinicalOperationException(404, 'No existe el análisis para el médico autenticado.', 'NO_ENCONTRADO');
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
