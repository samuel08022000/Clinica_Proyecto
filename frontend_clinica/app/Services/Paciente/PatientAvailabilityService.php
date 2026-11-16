<?php

namespace App\Services\Paciente;

use App\Contracts\PatientAvailabilityProvider;
use App\Exceptions\ClinicalOperationException;
use App\Services\Clinical\ClinicalRoleGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/** Consulta pública/autenticada de especialidades, médicos activos y disponibilidad D..D+14 para el paciente. */
final class PatientAvailabilityService implements PatientAvailabilityProvider
{
    public function __construct(
        private DatabaseManager $database,
        private ClinicalRoleGuard $roles,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function specialties(): array
    {
        return PatientSupport::read($this->database, function (Connection $db): array {
            return PatientSupport::rows($db->select(
                'SELECT cod_esp AS "codEsp", nombre_especialidad AS "nombreEspecialidad", '
                .'duracion_consulta_min AS "duracionConsultaMin" '
                ."FROM especialidades WHERE estado='Activo' ORDER BY nombre_especialidad, cod_esp",
            ));
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function doctors(string $specialtyCode): array
    {
        $specialty = PatientSupport::identifier($specialtyCode, 'codEsp');

        return PatientSupport::read($this->database, function (Connection $db) use ($specialty): array {
            if (! $db->table('especialidades')->where('cod_esp', $specialty)->where('estado', 'Activo')->exists()) {
                PatientSupport::missing('La especialidad solicitada no existe o no está habilitada.');
            }

            return PatientSupport::rows($db->select(
                <<<'SQL'
                SELECT m.cod_med AS "codMed", u.nombre_completo AS "nombreCompleto",
                       COALESCE(me.duracion_consulta_min, m.duracion_consulta_min, e.duracion_consulta_min) AS "duracionConsultaMin"
                FROM medico_especialidades me
                JOIN medicos m ON m.cod_med=me.cod_med
                JOIN especialidades e ON e.cod_esp=me.cod_esp
                JOIN usuarios u ON u.cod_usu=m.cod_usu
                JOIN roles r ON r.cod_rol=u.cod_rol
                WHERE e.cod_esp=? AND e.estado='Activo' AND me.estado='Activo'
                  AND m.estado='Activo' AND u.estado='Activo' AND u.cod_rol='ROL003' AND r.estado='Activo'
                ORDER BY u.nombre_completo, m.cod_med
                SQL,
                [$specialty],
            ));
        });
    }

    public function slots(array $filters): array
    {
        $user = $this->roles->require('ROL001');
        $specialty = PatientSupport::identifier((string) ($filters['codEsp'] ?? ''), 'codEsp');
        $date = PatientSupport::date((string) ($filters['fecha'] ?? ''), 'fecha');
        $doctor = filled($filters['codMed'] ?? null)
            ? PatientSupport::identifier((string) $filters['codMed'], 'codMed') : null;

        return PatientSupport::read($this->database, function (Connection $db) use ($user, $specialty, $date, $doctor): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            $today = (string) $db->scalar('SELECT public.fecha_clinica()');
            $maxDate = date('Y-m-d', strtotime($today.' +14 days'));
            if ($date < $today || $date > $maxDate) {
                throw new ClinicalOperationException(400, 'La fecha debe estar dentro de la ventana de reserva (hoy hasta D+14).', 'FECHA_INVALIDA');
            }

            $sql = <<<'SQL'
                SELECT cod_slot AS "codSlot", cod_med AS "codMed", nombre_medico AS "nombreMedico",
                       cod_esp AS "codEsp", nombre_especialidad AS "nombreEspecialidad",
                       fecha, hora_inicio AS "horaInicio", hora_fin AS "horaFin",
                       estado_disponibilidad AS "estadoDisponibilidad"
                FROM v_agenda_disponibilidad
                WHERE cod_esp=? AND fecha=? AND estado_disponibilidad='Disponible'
                SQL;
            $bindings = [$specialty, $date];
            if ($doctor !== null) {
                $sql .= ' AND cod_med=?';
                $bindings[] = $doctor;
            }
            $sql .= <<<'SQL'
                 AND NOT EXISTS (
                    SELECT 1 FROM citas c
                    WHERE c.cod_pac=? AND c.estado NOT IN ('Cancelada', 'Reprogramada')
                      AND tsrange(c.fecha + c.hora_inicio, c.fecha + c.hora_fin, '[)')
                          && tsrange(v_agenda_disponibilidad.fecha + v_agenda_disponibilidad.hora_inicio,
                                     v_agenda_disponibilidad.fecha + v_agenda_disponibilidad.hora_fin, '[)')
                )
                ORDER BY hora_inicio, cod_med, cod_slot
                SQL;
            $bindings[] = $patient['codPac'];

            $slots = PatientSupport::rows($db->select($sql, $bindings));

            return [
                'fecha' => $date,
                'codEsp' => $specialty,
                'codMed' => $doctor,
                'habilitadoParaReservar' => $patient['habilitadoParaReservar'],
                'slots' => $slots,
            ];
        });
    }
}
