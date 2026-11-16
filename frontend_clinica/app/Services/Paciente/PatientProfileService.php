<?php

namespace App\Services\Paciente;

use App\Contracts\PatientProfileProvider;
use App\Exceptions\ClinicalOperationException;
use App\Services\Clinical\ClinicalRoleGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/** Lectura estrictamente acotada al paciente autenticado: perfil, incidencias, derivaciones y análisis. */
final class PatientProfileService implements PatientProfileProvider
{
    public function __construct(
        private DatabaseManager $database,
        private ClinicalRoleGuard $roles,
    ) {}

    public function profile(): array
    {
        $user = $this->roles->require('ROL001');

        return PatientSupport::read($this->database, function (Connection $db) use ($user): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            $latestSanction = $db->selectOne(
                <<<'SQL'
                SELECT cod_inc AS "codInc", cod_cit AS "codCit", tipo_incidencia AS "tipoIncidencia",
                       motivo, fecha_fin_sancion AS "fechaFinSancion", fecha_registro AS "fechaRegistro"
                FROM incidencias_sanciones
                WHERE cod_pac=? AND fecha_fin_sancion IS NOT NULL AND fecha_fin_sancion > ahora_clinica()
                ORDER BY fecha_fin_sancion DESC, secuencial DESC
                LIMIT 1
                SQL,
                [$patient['codPac']],
            );

            $upcoming = (int) $db->scalar(
                "SELECT count(*) FROM citas WHERE cod_pac=? AND estado='Confirmada' AND (fecha + hora_fin) > ahora_clinica()",
                [$patient['codPac']],
            );
            $pendingReferrals = (int) $db->scalar(
                "SELECT count(*) FROM derivaciones d JOIN citas c ON c.cod_cit=d.cod_cit_origen WHERE c.cod_pac=? AND d.estado='Pendiente'",
                [$patient['codPac']],
            );
            $incidencesCount = (int) $db->scalar(
                'SELECT count(*) FROM incidencias_sanciones WHERE cod_pac=?',
                [$patient['codPac']],
            );

            $patient['sancionVigente'] = $latestSanction ? (array) $latestSanction : null;
            $patient['resumen'] = [
                'citasProximas' => $upcoming,
                'derivacionesPendientes' => $pendingReferrals,
                'incidenciasRegistradas' => $incidencesCount,
            ];

            return $patient;
        });
    }

    public function incidences(array $filters = []): array
    {
        $user = $this->roles->require('ROL001');

        return PatientSupport::read($this->database, function (Connection $db) use ($user, $filters): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            $page = PatientSupport::page($filters['page'] ?? '0', 0, 10000, 'page');
            $size = PatientSupport::page($filters['size'] ?? '20', 1, 50, 'size');
            $from = filled($filters['desde'] ?? null) ? PatientSupport::date((string) $filters['desde'], 'desde') : null;
            $to = filled($filters['hasta'] ?? null) ? PatientSupport::date((string) $filters['hasta'], 'hasta') : null;
            if ($from !== null && $to !== null && $from > $to) {
                throw new ClinicalOperationException(400, 'La fecha inicial no puede ser posterior a la final.', 'FECHA_INVALIDA');
            }

            $sql = <<<'SQL'
                SELECT i.cod_inc AS "codInc", i.cod_cit AS "codCit", i.tipo_incidencia AS "tipoIncidencia",
                       i.motivo, i.fecha_fin_sancion AS "fechaFinSancion", i.fecha_registro AS "fechaRegistro",
                       c.fecha AS "fechaCita", e.nombre_especialidad AS "nombreEspecialidad"
                FROM incidencias_sanciones i
                LEFT JOIN citas c ON c.cod_cit=i.cod_cit
                LEFT JOIN especialidades e ON e.cod_esp=c.cod_esp
                WHERE i.cod_pac=?
                SQL;
            $bindings = [$patient['codPac']];
            if (filled($filters['tipoIncidencia'] ?? null)) {
                $sql .= ' AND i.tipo_incidencia=?';
                $bindings[] = PatientSupport::text($filters['tipoIncidencia'], 50, 'tipoIncidencia');
            }
            if ($from !== null) {
                $sql .= ' AND i.fecha_registro::date>=?';
                $bindings[] = $from;
            }
            if ($to !== null) {
                $sql .= ' AND i.fecha_registro::date<=?';
                $bindings[] = $to;
            }
            array_push($bindings, $size + 1, $page * $size);
            $rows = PatientSupport::rows($db->select($sql.' ORDER BY i.fecha_registro DESC, i.secuencial DESC LIMIT ? OFFSET ?', $bindings));

            return [
                'incidencias' => array_slice($rows, 0, $size),
                'page' => $page,
                'size' => $size,
                'hayMas' => count($rows) > $size,
            ];
        });
    }

    public function referrals(array $filters = []): array
    {
        $user = $this->roles->require('ROL001');

        return PatientSupport::read($this->database, function (Connection $db) use ($user, $filters): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            $page = PatientSupport::page($filters['page'] ?? '0', 0, 10000, 'page');
            $size = PatientSupport::page($filters['size'] ?? '20', 1, 50, 'size');

            $sql = <<<'SQL'
                SELECT d.cod_der AS "codDer", d.cod_cit_origen AS "codCitOrigen", d.cod_cit_hija AS "codCitHija",
                       d.cod_med_emisor AS "codMedEmisor", ue.nombre_completo AS "nombreMedicoEmisor",
                       d.cod_esp_destino AS "codEspDestino", e.nombre_especialidad AS "nombreEspecialidadDestino",
                       d.cod_med_destino AS "codMedDestino", ud.nombre_completo AS "nombreMedicoDestino",
                       d.motivo_derivacion AS "motivoDerivacion", d.estado, d.fecha_derivacion AS "fechaDerivacion"
                FROM derivaciones d
                JOIN citas c ON c.cod_cit=d.cod_cit_origen
                JOIN especialidades e ON e.cod_esp=d.cod_esp_destino
                JOIN medicos me ON me.cod_med=d.cod_med_emisor
                JOIN usuarios ue ON ue.cod_usu=me.cod_usu
                LEFT JOIN medicos md ON md.cod_med=d.cod_med_destino
                LEFT JOIN usuarios ud ON ud.cod_usu=md.cod_usu
                WHERE c.cod_pac=?
                SQL;
            $bindings = [$patient['codPac']];
            if (filled($filters['estado'] ?? null)) {
                $sql .= ' AND d.estado=?';
                $bindings[] = PatientSupport::text($filters['estado'], 30, 'estado');
            }
            array_push($bindings, $size + 1, $page * $size);
            $rows = PatientSupport::rows($db->select($sql.' ORDER BY d.fecha_derivacion DESC, d.secuencial DESC LIMIT ? OFFSET ?', $bindings));

            return [
                'derivaciones' => array_slice($rows, 0, $size),
                'page' => $page,
                'size' => $size,
                'hayMas' => count($rows) > $size,
            ];
        });
    }

    public function analyses(string $appointmentCode): array
    {
        $code = PatientSupport::identifier($appointmentCode, 'codCit');
        $user = $this->roles->require('ROL001');

        return PatientSupport::read($this->database, function (Connection $db) use ($user, $code): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            if (! $db->table('citas')->where('cod_cit', $code)->where('cod_pac', $patient['codPac'])->exists()) {
                PatientSupport::missing('La cita solicitada no existe.');
            }

            $rows = PatientSupport::rows($db->select(
                <<<'SQL'
                SELECT a.cod_ana AS "codAna", a.cod_cit AS "codCit", a.tipo_analisis AS "tipoAnalisis",
                       a.estado, a.resultado, a.archivo_pdf_url AS "archivoPdfUrl",
                       a.fecha_registro AS "fechaRegistro", a.fecha_entrega_estimada AS "fechaEntregaEstimada",
                       a.fecha_entrega_real AS "fechaEntregaReal"
                FROM analisis a
                WHERE a.cod_cit=?
                ORDER BY a.fecha_registro DESC, a.secuencial DESC
                SQL,
                [$code],
            ));

            return [
                'codCit' => $code,
                'analisis' => $rows,
            ];
        });
    }
}
