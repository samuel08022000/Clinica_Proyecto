<?php

namespace App\Services\Paciente;

use App\Contracts\PatientAppointmentManager;
use App\Exceptions\ClinicalOperationException;
use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Clinical\ClinicalTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/** Operaciones transaccionales y de consulta de citas propias del paciente autenticado (ROL001). */
final class PatientAppointmentService implements PatientAppointmentManager
{
    private const DETAIL_SQL = <<<'SQL'
        SELECT c.cod_cit AS "codCit", c.cod_slot AS "codSlot", c.cod_pac AS "codPac",
               c.cod_med AS "codMed", um.nombre_completo AS "nombreMedico",
               c.cod_esp AS "codEsp", e.nombre_especialidad AS "nombreEspecialidad",
               c.fecha, c.hora_inicio AS "horaInicio", c.hora_fin AS "horaFin",
               c.estado, c.canal_reserva AS "canalReserva",
               c.motivo_cancelacion AS "motivoCancelacion",
               c.motivo_reprogramacion AS "motivoReprogramacion",
               c.cancelado_en AS "canceladoEn",
               c.hora_llegada_checkin AS "horaLlegadaCheckin",
               c.hora_inicio_atencion AS "horaInicioAtencion",
               c.fecha_atencion AS "fechaAtencion",
               c.cod_cit_padre AS "codCitPadre",
               c.creado_en AS "creadoEn",
               (SELECT n.estado FROM notificaciones n WHERE n.cod_cit=c.cod_cit AND n.canal='Email' AND n.tipo_alerta='Confirmacion' ORDER BY n.cod_not DESC LIMIT 1) AS "correoEstado",
               (SELECT n.intentos FROM notificaciones n WHERE n.cod_cit=c.cod_cit AND n.canal='Email' AND n.tipo_alerta='Confirmacion' ORDER BY n.cod_not DESC LIMIT 1) AS "correoIntentos",
               (c.token_qr IS NOT NULL) AS "qrDisponible",
               ((c.fecha + c.hora_fin) <= ahora_clinica()) AS "esVencida"
        FROM citas c
        JOIN medicos m ON m.cod_med=c.cod_med
        JOIN usuarios um ON um.cod_usu=m.cod_usu
        JOIN especialidades e ON e.cod_esp=c.cod_esp
        WHERE c.cod_cit=? AND c.cod_pac=?
        SQL;

    public function __construct(
        private DatabaseManager $database,
        private ClinicalTransaction $transactions,
        private ClinicalRoleGuard $roles,
    ) {}

    public function list(array $filters = []): array
    {
        $user = $this->roles->require('ROL001');

        return PatientSupport::read($this->database, function (Connection $db) use ($user, $filters): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            $page = PatientSupport::page($filters['page'] ?? '0', 0, 10000, 'page');
            $size = PatientSupport::page($filters['size'] ?? '20', 1, 50, 'size');
            $scope = filled($filters['alcance'] ?? null) ? (string) $filters['alcance'] : 'todas';
            if (! in_array($scope, ['proximas', 'pasadas', 'todas'], true)) {
                throw new ClinicalOperationException(400, 'El alcance indicado no es válido.', 'VALIDACION');
            }
            $from = filled($filters['desde'] ?? null) ? PatientSupport::date((string) $filters['desde'], 'desde') : null;
            $to = filled($filters['hasta'] ?? null) ? PatientSupport::date((string) $filters['hasta'], 'hasta') : null;
            if ($from !== null && $to !== null && $from > $to) {
                throw new ClinicalOperationException(400, 'La fecha inicial no puede ser posterior a la final.', 'FECHA_INVALIDA');
            }

            $sql = <<<'SQL'
                SELECT h.cod_cit AS "codCit", c.cod_slot AS "codSlot", h.cod_pac AS "codPac",
                       h.cod_med AS "codMed", h.nombre_medico AS "nombreMedico",
                       h.cod_esp AS "codEsp", h.nombre_especialidad AS "nombreEspecialidad",
                       h.fecha, h.hora_inicio AS "horaInicio", h.hora_fin AS "horaFin",
                       h.estado, h.canal_reserva AS "canalReserva",
                       c.motivo_cancelacion AS "motivoCancelacion",
                       c.motivo_reprogramacion AS "motivoReprogramacion",
                       h.cancelado_en AS "canceladoEn",
                       h.hora_llegada_checkin AS "horaLlegadaCheckin",
                       c.hora_inicio_atencion AS "horaInicioAtencion",
                       h.fecha_atencion AS "fechaAtencion",
                       c.cod_cit_padre AS "codCitPadre",
                       h.creado_en AS "creadoEn",
                       (c.token_qr IS NOT NULL) AS "qrDisponible",
                       ((h.fecha + h.hora_fin) <= ahora_clinica()) AS "esVencida"
                FROM v_historial_citas h
                JOIN citas c ON c.cod_cit=h.cod_cit
                WHERE h.cod_pac=?
                SQL;
            $bindings = [$patient['codPac']];

            if ($scope === 'proximas') {
                $sql .= " AND (h.estado IN ('En Espera', 'Afectada/Pendiente Reprogramación') OR (h.estado='Confirmada' AND (h.fecha + h.hora_fin) > ahora_clinica()))";
            } elseif ($scope === 'pasadas') {
                $sql .= " AND h.estado NOT IN ('En Espera', 'Afectada/Pendiente Reprogramación') AND (h.estado<>'Confirmada' OR (h.fecha + h.hora_fin) <= ahora_clinica())";
            }

            if (filled($filters['estado'] ?? null)) {
                $state = PatientSupport::text($filters['estado'], 40, 'estado');
                $sql .= match ($state) {
                    'En Espera' => ' AND h.estado=? AND c.hora_inicio_atencion IS NULL',
                    'En Atención' => ' AND h.estado=? AND c.hora_inicio_atencion IS NOT NULL',
                    default => ' AND h.estado=?',
                };
                $bindings[] = $state === 'En Atención' ? 'En Espera' : $state;
            }
            if ($from !== null) {
                $sql .= ' AND h.fecha>=?';
                $bindings[] = $from;
            }
            if ($to !== null) {
                $sql .= ' AND h.fecha<=?';
                $bindings[] = $to;
            }

            $order = $scope === 'proximas'
                ? ' ORDER BY h.fecha ASC, h.hora_inicio ASC, h.cod_cit ASC'
                : ' ORDER BY h.fecha DESC, h.hora_inicio DESC, h.cod_cit DESC';
            array_push($bindings, $size + 1, $page * $size);
            $rows = PatientSupport::rows($db->select($sql.$order.' LIMIT ? OFFSET ?', $bindings));

            return [
                'citas' => array_slice($rows, 0, $size),
                'alcance' => $scope,
                'page' => $page,
                'size' => $size,
                'hayMas' => count($rows) > $size,
            ];
        });
    }

    public function detail(string $appointmentCode): array
    {
        $code = PatientSupport::identifier($appointmentCode, 'codCit');
        $user = $this->roles->require('ROL001');

        return PatientSupport::read($this->database, function (Connection $db) use ($user, $code): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);

            return $this->fetchOwnedDetail($db, $patient, $code);
        });
    }

    public function checkInPass(string $appointmentCode): array
    {
        $code = PatientSupport::identifier($appointmentCode, 'codCit');
        $user = $this->roles->require('ROL001');

        return PatientSupport::read($this->database, function (Connection $db) use ($user, $code): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            $row = $db->selectOne(
                <<<'SQL'
                SELECT c.cod_cit AS "codCit", c.cod_pac AS "codPac", up.nombre_completo AS "nombrePaciente",
                       um.nombre_completo AS "nombreMedico", e.nombre_especialidad AS "nombreEspecialidad",
                       c.fecha, c.hora_inicio AS "horaInicio", c.hora_fin AS "horaFin",
                       c.estado, c.token_qr AS "tokenQr",
                       ((c.fecha + c.hora_fin) <= ahora_clinica()) AS "esVencida",
                       public.cita_admite_checkin(c.cod_cit) AS "admiteCheckin"
                FROM citas c
                JOIN pacientes p ON p.cod_pac=c.cod_pac
                JOIN usuarios up ON up.cod_usu=p.cod_usu
                JOIN medicos m ON m.cod_med=c.cod_med
                JOIN usuarios um ON um.cod_usu=m.cod_usu
                JOIN especialidades e ON e.cod_esp=c.cod_esp
                WHERE c.cod_cit=? AND c.cod_pac=?
                SQL,
                [$code, $patient['codPac']],
            );
            if (! $row) {
                PatientSupport::missing('La cita solicitada no existe.');
            }
            if ($row->estado !== 'Confirmada') {
                throw new ClinicalOperationException(409, 'Solo las citas confirmadas disponen de pase QR vigente.', 'QR_NO_DISPONIBLE');
            }
            if ($row->esVencida) {
                throw new ClinicalOperationException(409, 'La cita venció y ya no dispone de pase QR.', 'CITA_VENCIDA');
            }
            if (! filled($row->tokenQr)) {
                throw new ClinicalOperationException(409, 'La cita no tiene un código QR emitido.', 'QR_NO_DISPONIBLE');
            }

            return [
                'codCit' => $row->codCit,
                'codPac' => $row->codPac,
                'nombrePaciente' => $row->nombrePaciente,
                'nombreMedico' => $row->nombreMedico,
                'nombreEspecialidad' => $row->nombreEspecialidad,
                'fecha' => $row->fecha,
                'horaInicio' => $row->horaInicio,
                'horaFin' => $row->horaFin,
                'estado' => $row->estado,
                'tokenQr' => $row->tokenQr,
                'admiteCheckin' => (bool) $row->admiteCheckin,
            ];
        });
    }

    public function reserve(string $slotCode): array
    {
        $slot = PatientSupport::identifier($slotCode, 'codSlot');
        $user = $this->roles->require('ROL001');

        return $this->transactions->run(function (Connection $db) use ($user, $slot): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            if (! $patient['habilitadoParaReservar']) {
                throw new ClinicalOperationException(409, 'Tu cuenta tiene una sanción o restricción vigente y no puede reservar citas.', 'PACIENTE_NO_HABILITADO');
            }
            $codCit = (string) $db->scalar(
                "SELECT public.reservar_cita(?, ?, ?, 'Web', NULL)",
                [$patient['codPac'], $slot, $user->cod_usu],
            );

            return $this->fetchOwnedDetail($db, $patient, $codCit);
        }, 'patient.appointment.reserved', []);
    }

    public function cancel(string $appointmentCode, string $reason): array
    {
        $code = PatientSupport::identifier($appointmentCode, 'codCit');
        $motivo = PatientSupport::text($reason, 2000, 'motivo');
        $user = $this->roles->require('ROL001');

        return $this->transactions->run(function (Connection $db) use ($user, $code, $motivo): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            $this->assertMutableConfirmed($db, $code, $patient['codPac'], 'cancelarse');
            $db->scalar('SELECT public.cancelar_cita(?, ?, ?)', [$code, $user->cod_usu, $motivo]);

            return $this->fetchOwnedDetail($db, $patient, $code);
        }, 'patient.appointment.cancelled', ['entity_id' => $code]);
    }

    public function reschedule(string $appointmentCode, string $newSlotCode, string $reason): array
    {
        $code = PatientSupport::identifier($appointmentCode, 'codCit');
        $newSlot = PatientSupport::identifier($newSlotCode, 'codSlotNuevo');
        $motivo = PatientSupport::text($reason, 2000, 'motivo');
        $user = $this->roles->require('ROL001');

        return $this->transactions->run(function (Connection $db) use ($user, $code, $newSlot, $motivo): array {
            $patient = PatientSupport::patient($db, $user->cod_usu);
            if (! $patient['habilitadoParaReservar']) {
                throw new ClinicalOperationException(409, 'Tu cuenta tiene una sanción o restricción vigente y no puede reprogramar citas.', 'PACIENTE_NO_HABILITADO');
            }
            $this->assertMutableConfirmed($db, $code, $patient['codPac'], 'reprogramarse');
            $newCodCit = (string) $db->scalar(
                'SELECT public.reprogramar_cita(?, ?, ?, ?)',
                [$code, $newSlot, $user->cod_usu, $motivo],
            );

            return $this->fetchOwnedDetail($db, $patient, $newCodCit);
        }, 'patient.appointment.rescheduled', ['previous_appointment_id' => $code]);
    }

    private function assertMutableConfirmed(Connection $db, string $code, string $codPac, string $actionVerb): void
    {
        $row = $db->selectOne(
            'SELECT estado, ((fecha + hora_fin) <= ahora_clinica()) AS "esVencida" FROM citas WHERE cod_cit=? AND cod_pac=?',
            [$code, $codPac],
        );
        if (! $row) {
            PatientSupport::missing('La cita solicitada no existe.');
        }
        if ($row->estado === 'Confirmada' && $row->esVencida) {
            throw new ClinicalOperationException(409, "La cita ya venció y no puede $actionVerb.", 'CITA_VENCIDA');
        }
    }

    private function fetchOwnedDetail(Connection $db, array $patient, string $code): array
    {
        $row = $db->selectOne(self::DETAIL_SQL, [$code, $patient['codPac']]);
        if (! $row) {
            PatientSupport::missing('La cita solicitada no existe.');
        }
        $detail = (array) $row;
        $mutable = $detail['estado'] === 'Confirmada' && ! $detail['esVencida'];
        $detail['puedeCancelar'] = $mutable;
        $detail['puedeReprogramar'] = ($mutable || $detail['estado'] === 'Afectada/Pendiente Reprogramación') && $patient['habilitadoParaReservar'];
        $detail['puedeVerPaseQr'] = $mutable && (bool) $detail['qrDisponible'];

        return $detail;
    }
}
