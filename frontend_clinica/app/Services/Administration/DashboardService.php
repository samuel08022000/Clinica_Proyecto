<?php

namespace App\Services\Administration;

use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Clinical\ClinicalSqlException;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

class DashboardService
{
    public function __construct(
        private DatabaseManager $database,
        private ClinicalRoleGuard $roles,
        private ColaMetricaService $queues,
    ) {}

    /**
     * Las citas históricas se filtran por fecha programada. La sala de espera
     * tiene su propia captura operativa en America/La_Paz.
     *
     * @param  array{periodo?: string, desde?: ?string, hasta?: ?string, especialidad?: ?string, medico?: ?string}  $filters
     */
    public function snapshot(array $filters = []): array
    {
        try {
            $this->roles->require('ROL004');
            $db = $this->database->connection('clinical');
            $today = new DateTimeImmutable((string) $db->scalar('SELECT fecha_clinica()'));
            $preset = $filters['periodo'] ?? 'mes';
            $from = match ($preset) {
                'hoy' => $today,
                'semana' => $today->modify('monday this week'),
                'personalizado' => new DateTimeImmutable($filters['desde']),
                default => $today->modify('first day of this month'),
            };
            $to = $preset === 'personalizado' ? new DateTimeImmutable($filters['hasta']) : $today;
            $result = $db->selectOne(<<<'SQL'
                WITH limites AS (
                    SELECT ?::date AS desde, ?::date AS hasta, ?::date AS hoy
                ), citas_periodo AS MATERIALIZED (
                    SELECT c.cod_pac, c.cod_esp, c.fecha, c.canal_reserva,
                        CASE WHEN c.estado='En Espera' AND c.hora_inicio_atencion IS NOT NULL
                             THEN 'En Atención' ELSE c.estado END AS estado
                    FROM citas c CROSS JOIN limites l
                    WHERE c.fecha BETWEEN l.desde AND l.hasta
                )
                SELECT json_build_object(
                    'totals', json_build_object(
                        'doctors', (SELECT count(*) FROM medicos m JOIN usuarios u USING(cod_usu)
                            JOIN roles r USING(cod_rol)
                            WHERE m.estado='Activo' AND u.estado='Activo' AND r.estado='Activo' AND u.cod_rol='ROL003'),
                        'secretaries', (SELECT count(*) FROM secretarias s JOIN usuarios u USING(cod_usu)
                            JOIN roles r USING(cod_rol)
                            WHERE s.estado='Activo' AND u.estado='Activo' AND r.estado='Activo' AND u.cod_rol='ROL002'),
                        'specialties', (SELECT count(*) FROM especialidades WHERE estado='Activo'),
                        'patients', (SELECT count(*) FROM pacientes),
                        'newPatients', (SELECT count(*) FROM pacientes p WHERE
                            p.creado_en >= (l.desde::timestamp AT TIME ZONE 'America/La_Paz') AND
                            p.creado_en < ((l.hasta+1)::timestamp AT TIME ZONE 'America/La_Paz')),
                        'appointments', (SELECT count(*) FROM citas_periodo),
                        'attendedPatients', (SELECT count(DISTINCT cod_pac) FROM citas_periodo WHERE estado='Atendida'),
                        'todayAppointments', (SELECT count(*) FROM citas WHERE fecha=l.hoy
                            AND estado IN ('Confirmada','En Espera','Atendida','No Asistió')),
                        'todayAttended', (SELECT count(DISTINCT cod_pac) FROM citas WHERE estado='Atendida'
                            AND fecha_atencion >= (l.hoy::timestamp AT TIME ZONE 'America/La_Paz')
                            AND fecha_atencion < ((l.hoy+1)::timestamp AT TIME ZONE 'America/La_Paz'))
                    ),
                    'daily', (SELECT coalesce(json_agg(d ORDER BY d.date), '[]'::json) FROM (
                        SELECT fecha AS date, count(DISTINCT cod_pac) AS value
                        FROM citas_periodo WHERE estado='Atendida' GROUP BY fecha
                    ) d),
                    'specialties', (SELECT coalesce(json_agg(s ORDER BY s.value DESC, s.label), '[]'::json) FROM (
                        SELECT e.nombre_especialidad AS label, count(*) AS value
                        FROM citas_periodo c JOIN especialidades e USING(cod_esp)
                        WHERE c.estado <> 'Reprogramada' GROUP BY e.cod_esp, e.nombre_especialidad
                    ) s),
                    'states', (SELECT coalesce(json_object_agg(estado, total), '{}'::json) FROM (
                        SELECT estado, count(*) AS total FROM citas_periodo GROUP BY estado
                    ) s),
                    'channels', (SELECT coalesce(json_object_agg(canal_reserva, total), '{}'::json) FROM (
                        SELECT canal_reserva, count(*) AS total FROM citas_periodo GROUP BY canal_reserva
                    ) s),
                    'referrals', (SELECT coalesce(json_object_agg(estado, total), '{}'::json) FROM (
                        SELECT d.estado, count(*) AS total FROM derivaciones d WHERE
                            d.fecha_derivacion >= (l.desde::timestamp AT TIME ZONE 'America/La_Paz') AND
                            d.fecha_derivacion < ((l.hasta+1)::timestamp AT TIME ZONE 'America/La_Paz')
                        GROUP BY d.estado
                    ) s),
                    'updatedAt', to_char(now() AT TIME ZONE 'America/La_Paz', 'DD/MM/YYYY HH24:MI')
                ) AS payload FROM limites l
                SQL, [$from->format('Y-m-d'), $to->format('Y-m-d'), $today->format('Y-m-d')]);

            $data = json_decode($result->payload, true, flags: JSON_THROW_ON_ERROR);
            $daily = array_column($data['daily'], 'value', 'date');
            $data['daily'] = [];
            for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
                $date = $day->format('Y-m-d');
                $data['daily'][] = ['date' => $date, 'label' => $day->format('d/m'), 'value' => $daily[$date] ?? 0];
            }

            $data['states'] = $this->distribution($data['states'], [
                'Confirmada' => 'Confirmadas', 'En Espera' => 'En espera', 'Atendida' => 'Atendidas',
                'Cancelada' => 'Canceladas', 'No Asistió' => 'No asistió',
                'Afectada/Pendiente Reprogramación' => 'Pendientes de reprogramar', 'Reprogramada' => 'Reprogramadas',
                'En Atención' => 'En atención',
            ]);
            $data['channels'] = $this->distribution($data['channels'], [
                'Web' => 'Web', 'Ventanilla' => 'Ventanilla', 'Derivacion' => 'Derivación', 'Admin' => 'Administración',
            ]);
            $data['referrals'] = $this->distribution($data['referrals'], [
                'Pendiente' => 'Pendientes', 'Agendada' => 'Agendadas', 'Completada' => 'Completadas', 'Cancelada' => 'Canceladas',
            ]);
            $data['period'] = [
                'preset' => $preset, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
                'today' => $today->format('d/m/Y'), 'label' => $from->format('d/m/Y').' – '.$to->format('d/m/Y'),
            ];
            $data['queues'] = $this->queues->resumenDashboard([
                'fecha_inicio' => $data['period']['from'],
                'fecha_fin' => $data['period']['to'],
                'especialidad' => $filters['especialidad'] ?? null,
                'medico' => $filters['medico'] ?? null,
            ]);
            $data['totals']['todayWaiting'] = $data['queues']['espera_actual']['global']['total'];

            return $data;
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }

    /** @return list<array{label: string, value: int}> */
    private function distribution(array $values, array $labels): array
    {
        $rows = [];
        foreach ($labels as $state => $label) {
            $rows[] = ['label' => $label, 'value' => (int) ($values[$state] ?? 0)];
        }

        return $rows;
    }
}
