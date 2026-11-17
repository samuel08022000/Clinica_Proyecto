<?php

namespace App\Services\Reception;

use App\Exceptions\ClinicalOperationException;
use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Clinical\ClinicalSqlException;
use App\Services\Clinical\ClinicalTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/** Casos de uso de recepción. Las reservas y transiciones siguen en PostgreSQL. */
class ReceptionService
{
    public function __construct(
        private DatabaseManager $database,
        private ClinicalTransaction $transactions,
        private ClinicalRoleGuard $roles,
    ) {}

    public function request(string $method, string $path, array $data = []): array
    {
        $this->roles->require('ROL002');
        try {
            return $this->dispatch(strtoupper($method), trim($path, '/'), $data);
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }

    private function dispatch(string $method, string $path, array $data): array
    {
        if ($method === 'GET' && $path === 'pacientes') {
            return $this->searchPatients((string) ($data['q'] ?? ''));
        }
        if ($method === 'GET' && preg_match('#^pacientes/([A-Za-z0-9]+)/historial$#', $path, $m)) {
            return $this->appointments($data + ['codPac' => $m[1]], true);
        }
        if ($method === 'GET' && preg_match('#^pacientes/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->patient($m[1]);
        }
        if ($method === 'GET' && $path === 'especialidades') {
            return $this->specialties();
        }
        if ($method === 'GET' && $path === 'medicos') {
            return $this->doctors((string) ($data['codEsp'] ?? ''));
        }
        if ($method === 'GET' && $path === 'disponibilidad') {
            return $this->availability($data);
        }
        if ($method === 'POST' && $path === 'citas') {
            return $this->reserve($data);
        }
        if ($method === 'GET' && $path === 'citas') {
            return $this->appointments($data, false);
        }
        if ($method === 'GET' && preg_match('#^citas/([A-Za-z0-9]+)/asistencia$#', $path, $m)) {
            return $this->attendance($m[1]);
        }
        if ($method === 'GET' && preg_match('#^citas/([A-Za-z0-9]+)/acreditacion$#', $path, $m)) {
            return $this->accreditation($m[1]);
        }
        if ($method === 'POST' && preg_match('#^citas/([A-Za-z0-9]+)/cancelar$#', $path, $m)) {
            return $this->cancel($m[1], $data);
        }
        if ($method === 'POST' && preg_match('#^citas/([A-Za-z0-9]+)/inasistencia$#', $path, $m)) {
            return $this->absence($m[1]);
        }
        if ($method === 'POST' && preg_match('#^citas/([A-Za-z0-9]+)/reprogramar$#', $path, $m)) {
            return $this->reschedule($m[1], $data);
        }
        if ($method === 'GET' && preg_match('#^citas/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->appointment($m[1]);
        }
        if ($method === 'POST' && $path === 'checkin') {
            return $this->checkin((string) ($data['tokenQr'] ?? ''));
        }
        if ($method === 'GET' && $path === 'derivaciones') {
            return $this->referrals($data);
        }
        if ($method === 'GET' && preg_match('#^derivaciones/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->referral($m[1]);
        }
        throw new ClinicalOperationException(404, 'La operación de recepción solicitada no existe.', 'NO_ENCONTRADO');
    }

    private function db(): Connection
    {
        return $this->database->connection('clinical');
    }

    private static function rows(array $rows): array
    {
        return array_map(fn ($r) => (array) $r, $rows);
    }

    private static function missing(string $message): never
    {
        throw new ClinicalOperationException(404, $message, 'NO_ENCONTRADO');
    }

    private const PATIENT_SQL = 'SELECT p.cod_pac AS "codPac",u.nombre_completo AS "nombreCompleto",p.ci,u.email,p.telefono,p.ciudad_residencia AS "ciudadResidencia",estado_reserva_paciente(p.cod_pac) AS "estadoCuenta",u.estado AS "estadoUsuario",p.email_verificado AS "emailVerificado",p.bloqueado_hasta AS "bloqueadoHasta",CASE WHEN u.estado<>\'Activo\' THEN \'USUARIO_INACTIVO\' WHEN r.estado<>\'Activo\' THEN \'ROL_INACTIVO\' WHEN estado_reserva_paciente(p.cod_pac)=\'Bloqueado_Temporal\' THEN \'PACIENTE_BLOQUEADO\' WHEN estado_reserva_paciente(p.cod_pac)<>\'Activo\' THEN \'PACIENTE_INACTIVO\' WHEN p.email_verificado=false THEN \'EMAIL_NO_VERIFICADO\' ELSE NULL END AS "motivoInhabilitacion" FROM pacientes p JOIN usuarios u ON u.cod_usu=p.cod_usu JOIN roles r ON r.cod_rol=u.cod_rol WHERE u.cod_rol=\'ROL001\'';

    private function searchPatients(string $query): array
    {
        $query = trim($query);
        if (preg_match_all('/[\p{L}\p{N}]/u', $query) < 3) {
            throw new ClinicalOperationException(400, 'La búsqueda requiere al menos tres letras o números.', 'VALIDACION');
        }
        $pattern = '%'.mb_strtolower($query).'%';
        $rows = self::rows($this->db()->select('SELECT p.cod_pac AS "codPac",u.nombre_completo AS "nombreCompleto",p.ci,u.email,estado_reserva_paciente(p.cod_pac) AS "estadoCuenta" FROM pacientes p JOIN usuarios u ON u.cod_usu=p.cod_usu WHERE u.cod_rol=\'ROL001\' AND (lower(u.nombre_completo) LIKE ? OR lower(u.email) LIKE ? OR lower(p.ci) LIKE ? OR p.cod_pac=?) ORDER BY u.nombre_completo,p.cod_pac LIMIT 20', [$pattern, $pattern, $pattern, strtoupper($query)]));

        return ['pacientes' => $rows];
    }

    private function patient(string $code): array
    {
        $row = $this->db()->selectOne(self::PATIENT_SQL.' AND p.cod_pac=?', [$code]);
        if (! $row) {
            self::missing('El paciente solicitado no existe.');
        }
        $result = (array) $row;
        $result['habilitadoParaReservar'] = $result['motivoInhabilitacion'] === null;

        return $result;
    }

    private function specialties(): array
    {
        return self::rows($this->db()->select('SELECT cod_esp AS "codEsp",nombre_especialidad AS "nombreEspecialidad",duracion_consulta_min AS "duracionConsultaMin" FROM especialidades WHERE estado=\'Activo\' ORDER BY nombre_especialidad,cod_esp'));
    }

    private function doctors(string $specialty): array
    {
        if ($specialty === '') {
            throw new ClinicalOperationException(400, 'Debes seleccionar una especialidad.', 'VALIDACION');
        }

        return self::rows($this->db()->select('SELECT m.cod_med AS "codMed",u.nombre_completo AS "nombreCompleto",COALESCE(me.duracion_consulta_min,m.duracion_consulta_min,e.duracion_consulta_min) AS "duracionConsultaMin" FROM medico_especialidades me JOIN medicos m ON m.cod_med=me.cod_med JOIN especialidades e ON e.cod_esp=me.cod_esp JOIN usuarios u ON u.cod_usu=m.cod_usu JOIN roles r ON r.cod_rol=u.cod_rol WHERE e.cod_esp=? AND e.estado=\'Activo\' AND me.estado=\'Activo\' AND m.estado=\'Activo\' AND u.estado=\'Activo\' AND u.cod_rol=\'ROL003\' AND r.estado=\'Activo\' ORDER BY u.nombre_completo,m.cod_med', [$specialty]));
    }

    private function availability(array $data): array
    {
        foreach (['codEsp', 'fecha', 'codPac'] as $key) {
            if (! filled($data[$key] ?? null)) {
                throw new ClinicalOperationException(400, 'Faltan filtros obligatorios de disponibilidad.', 'VALIDACION');
            }
        }
        $patient = $this->patient($data['codPac']);
        if (! $patient['habilitadoParaReservar']) {
            throw new ClinicalOperationException(409, 'El paciente no está habilitado para reservar.', 'PACIENTE_NO_HABILITADO');
        }
        $today = $this->db()->scalar('SELECT fecha_clinica()');
        if ($data['fecha'] < $today || $data['fecha'] > date('Y-m-d', strtotime($today.' +14 days'))) {
            throw new ClinicalOperationException(400, 'La fecha está fuera de la ventana de reserva.', 'FECHA_INVALIDA');
        }
        $sql = 'SELECT cod_slot AS "codSlot",cod_med AS "codMed",nombre_medico AS "nombreMedico",cod_esp AS "codEsp",nombre_especialidad AS "nombreEspecialidad",fecha,hora_inicio AS "horaInicio",hora_fin AS "horaFin",estado_disponibilidad AS "estadoDisponibilidad" FROM v_agenda_disponibilidad WHERE cod_esp=? AND fecha=? AND estado_disponibilidad=\'Disponible\'';
        $bindings = [$data['codEsp'], $data['fecha']];
        if (filled($data['codMed'] ?? null)) {
            $sql .= ' AND cod_med=?';
            $bindings[] = $data['codMed'];
        }
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM citas c WHERE c.cod_pac=? AND c.estado NOT IN (\'Cancelada\',\'Reprogramada\') AND tsrange(c.fecha+c.hora_inicio,c.fecha+c.hora_fin,\'[)\') && tsrange(v_agenda_disponibilidad.fecha+v_agenda_disponibilidad.hora_inicio,v_agenda_disponibilidad.fecha+v_agenda_disponibilidad.hora_fin,\'[)\')) ORDER BY hora_inicio,cod_med,cod_slot';
        $bindings[] = $data['codPac'];

        return self::rows($this->db()->select($sql, $bindings));
    }

    private function reserve(array $data): array
    {
        $user = $this->roles->require('ROL002');
        $code = $this->transactions->run(function (Connection $db) use ($data, $user) {
            $this->assertSecretary($db, $user->cod_usu);
            $referralCode = filled($data['codDer'] ?? null) ? (string) $data['codDer'] : null;
            $patient = filled($data['codPac'] ?? null) ? (string) $data['codPac'] : null;
            if ($referralCode !== null) {
                $referralPatient = $db->scalar(
                    'SELECT c.cod_pac FROM derivaciones d JOIN citas c ON c.cod_cit=d.cod_cit_origen WHERE d.cod_der=?',
                    [$referralCode],
                );
                if (! is_string($referralPatient)) {
                    self::missing('La derivación solicitada no existe.');
                }
                if ($patient !== null && $patient !== $referralPatient) {
                    throw new ClinicalOperationException(409, 'El paciente no coincide con la derivación.', 'DESTINO_INCOMPATIBLE');
                }
                $patient = $referralPatient;
            }

            return $db->scalar('SELECT reservar_cita(?,?,?,?,?)', [$patient, $data['codSlot'], $user->cod_usu, 'Ventanilla', $referralCode]);
        });

        return $this->appointment($code);
    }

    private const HISTORY_SQL = 'SELECT h.cod_cit AS "codCit",h.cod_pac AS "codPac",up.nombre_completo AS "nombrePaciente",h.cod_med AS "codMed",h.nombre_medico AS "nombreMedico",h.cod_esp AS "codEsp",h.nombre_especialidad AS "nombreEspecialidad",h.fecha,h.hora_inicio AS "horaInicio",h.hora_fin AS "horaFin",h.estado,h.canal_reserva AS "canalReserva",c.motivo_cancelacion AS "motivoCancelacion",c.cancelado_por_usu AS "canceladoPorUsu",h.cancelado_en AS "canceladoEn",h.hora_llegada_checkin AS "horaLlegadaCheckin",c.checkin_por_usu AS "checkinPorUsu",h.fecha_atencion AS "fechaAtencion",i.cod_inc AS "codIncInasistencia",i.fecha_registro AS "inasistenciaRegistradaEn",i.registrado_por_usu AS "inasistenciaPorUsu",i.motivo AS "motivoInasistencia" FROM v_historial_citas h JOIN citas c ON c.cod_cit=h.cod_cit JOIN pacientes p ON p.cod_pac=h.cod_pac JOIN usuarios up ON up.cod_usu=p.cod_usu LEFT JOIN incidencias_sanciones i ON i.cod_cit=h.cod_cit AND i.tipo_incidencia=\'INASISTENCIA_NO_SHOW\' AND i.secuencial=(SELECT max(i2.secuencial) FROM incidencias_sanciones i2 WHERE i2.cod_cit=h.cod_cit AND i2.tipo_incidencia=\'INASISTENCIA_NO_SHOW\') WHERE 1=1';

    private function appointments(array $filters, bool $history): array
    {
        $page = (int) ($filters['page'] ?? 0);
        $size = (int) ($filters['size'] ?? 20);
        $sql = self::HISTORY_SQL;
        $bindings = [];
        foreach (['codPac' => 'h.cod_pac', 'codMed' => 'h.cod_med', 'estado' => 'h.estado'] as $key => $column) {
            if (filled($filters[$key] ?? null)) {
                $sql .= " AND $column=?";
                $bindings[] = $filters[$key];
            }
        }
        if (filled($filters['fecha'] ?? null)) {
            $sql .= ' AND h.fecha=?';
            $bindings[] = $filters['fecha'];
        } elseif (! $history && ! filled($filters['desde'] ?? null) && ! filled($filters['hasta'] ?? null)) {
            $sql .= ' AND h.fecha=fecha_clinica()';
        }
        if (filled($filters['desde'] ?? null)) {
            $sql .= ' AND h.fecha>=?';
            $bindings[] = $filters['desde'];
        }
        if (filled($filters['hasta'] ?? null)) {
            $sql .= ' AND h.fecha<=?';
            $bindings[] = $filters['hasta'];
        }
        array_push($bindings, $size + 1, $page * $size);
        $rows = self::rows($this->db()->select($sql.' ORDER BY h.fecha,h.hora_inicio,h.cod_cit LIMIT ? OFFSET ?', $bindings));
        $more = count($rows) > $size;

        return ['citas' => array_slice($rows, 0, $size), 'page' => $page, 'size' => $size, 'hayMas' => $more];
    }

    private function appointment(string $code): array
    {
        $row = $this->db()->selectOne('SELECT c.cod_cit AS "codCit",c.cod_slot AS "codSlot",c.cod_pac AS "codPac",up.nombre_completo AS "nombrePaciente",c.cod_med AS "codMed",um.nombre_completo AS "nombreMedico",c.cod_esp AS "codEsp",e.nombre_especialidad AS "nombreEspecialidad",c.fecha,c.hora_inicio AS "horaInicio",c.hora_fin AS "horaFin",c.estado,c.canal_reserva AS "canalReserva",c.cod_sec AS "codSec",(c.token_qr IS NOT NULL) AS "qrDisponible" FROM citas c JOIN pacientes p ON p.cod_pac=c.cod_pac JOIN usuarios up ON up.cod_usu=p.cod_usu JOIN medicos m ON m.cod_med=c.cod_med JOIN usuarios um ON um.cod_usu=m.cod_usu JOIN especialidades e ON e.cod_esp=c.cod_esp WHERE c.cod_cit=?', [$code]);

        return $row ? (array) $row : self::missing('La cita solicitada no existe.');
    }

    private function attendance(string $code): array
    {
        $row = $this->db()->selectOne('SELECT c.cod_cit AS "codCit",u.nombre_completo AS "nombrePaciente",c.fecha,c.estado,c.hora_llegada_checkin AS "horaLlegadaCheckin",c.checkin_por_usu AS "checkinPorUsu" FROM citas c JOIN pacientes p ON p.cod_pac=c.cod_pac JOIN usuarios u ON u.cod_usu=p.cod_usu WHERE c.cod_cit=?', [$code]);
        if (! $row) {
            self::missing('La cita solicitada no existe.');
        }
        $result = (array) $row;
        $result['checkinRegistrado'] = $result['horaLlegadaCheckin'] !== null;

        return $result;
    }

    private function accreditation(string $code): array
    {
        $row = $this->db()->selectOne('SELECT c.cod_cit AS "codCit",u.nombre_completo AS "nombrePaciente",c.fecha,c.estado,(q.cod_cit=c.cod_cit AND q.fecha=c.fecha) IS TRUE AS "qrValido",((q.cod_cit=c.cod_cit AND q.fecha=c.fecha) IS TRUE AND cita_admite_checkin(c.cod_cit)) AS "admiteCheckin" FROM citas c JOIN pacientes p ON p.cod_pac=c.cod_pac JOIN usuarios u ON u.cod_usu=p.cod_usu LEFT JOIN LATERAL verificar_token_qr(c.token_qr) q ON true WHERE c.cod_cit=?', [$code]);

        return $row ? (array) $row : self::missing('La cita solicitada no existe.');
    }

    private function checkin(string $token): array
    {
        if (str_contains($token, '/pase-qr/')) {
            $token = basename((string) parse_url($token, PHP_URL_PATH));
        }
        if ($token === '' || strlen($token) > 4096) {
            throw new ClinicalOperationException(400, 'El QR no es válido.', 'QR_INVALIDO');
        }
        $user = $this->roles->require('ROL002');
        $code = $this->transactions->run(function (Connection $db) use ($token, $user) {
            $secretary = $this->assertSecretary($db, $user->cod_usu);

            return $db->scalar('SELECT registrar_checkin(?,?)', [$token, $secretary]);
        });

        return $this->attendance($code);
    }
    private function cancel(string $code, array $data): array
    {
        $user = $this->roles->require('ROL002');
        $this->transactions->run(function (Connection $db) use ($code, $data, $user) {
            $this->assertSecretary($db, $user->cod_usu);
            $db->scalar('SELECT cancelar_cita(?,?,?)', [$code, $user->cod_usu, trim($data['motivo'] ?? '')]);
        });
        $result = $this->historyDetail($code);
        $result['cancelada'] = true;

        return $result;
    }

    private function absence(string $code): array
    {
        $user = $this->roles->require('ROL002');
        $this->transactions->run(function (Connection $db) use ($code, $user) {
            $this->assertSecretary($db, $user->cod_usu);
            $db->scalar('SELECT registrar_inasistencia(?,?)', [$code, $user->cod_usu]);
        });
        $result = $this->historyDetail($code);
        $result['inasistenciaRegistrada'] = true;

        return $result;
    }

    private function reschedule(string $code, array $data): array
    {
        $user = $this->roles->require('ROL002');
        $new = $this->transactions->run(function (Connection $db) use ($code, $data, $user) {
            $this->assertSecretary($db, $user->cod_usu);

            return $db->scalar('SELECT reprogramar_cita(?,?,?,?)', [$code, $data['codSlot'], $user->cod_usu, trim($data['motivo'] ?? 'Reprogramación de cita')]);
        });

        return $this->appointment($new);
    }

    private function historyDetail(string $code): array
    {
        $row = $this->db()->selectOne(self::HISTORY_SQL.' AND h.cod_cit=?', [$code]);

        return $row ? (array) $row : self::missing('La cita solicitada no existe.');
    }

    private const REFERRAL_SQL = 'SELECT d.cod_der AS "codDer",d.cod_cit_origen AS "codCitOrigen",c.cod_pac AS "codPac",up.nombre_completo AS "nombrePaciente",d.cod_med_emisor AS "codMedEmisor",ue.nombre_completo AS "nombreMedicoEmisor",d.cod_esp_destino AS "codEspDestino",e.nombre_especialidad AS "nombreEspecialidadDestino",d.cod_med_destino AS "codMedDestino",ud.nombre_completo AS "nombreMedicoDestino",d.estado,d.fecha_derivacion AS "fechaDerivacion",d.cod_cit_hija AS "codCitHija" FROM derivaciones d JOIN citas c ON c.cod_cit=d.cod_cit_origen JOIN pacientes p ON p.cod_pac=c.cod_pac JOIN usuarios up ON up.cod_usu=p.cod_usu JOIN medicos me ON me.cod_med=d.cod_med_emisor JOIN usuarios ue ON ue.cod_usu=me.cod_usu JOIN especialidades e ON e.cod_esp=d.cod_esp_destino LEFT JOIN medicos md ON md.cod_med=d.cod_med_destino LEFT JOIN usuarios ud ON ud.cod_usu=md.cod_usu WHERE 1=1';

    private function referrals(array $filters): array
    {
        $page = $this->pagination($filters['page'] ?? 0, 0, 10000, 'page');
        $size = $this->pagination($filters['size'] ?? 20, 1, 50, 'size');
        $sql = self::REFERRAL_SQL;
        $bindings = [];
        if (filled($filters['estado'] ?? null)) {
            if (! in_array($filters['estado'], ['Pendiente', 'Agendada', 'Completada', 'Cancelada'], true)) {
                throw new ClinicalOperationException(400, 'El estado de derivación no es válido.', 'VALIDACION');
            }
            $sql .= ' AND d.estado=?';
            $bindings[] = $filters['estado'];
        }
        if (filled($filters['codPac'] ?? null)) {
            $sql .= ' AND c.cod_pac=?';
            $bindings[] = $filters['codPac'];
        }
        array_push($bindings, $size + 1, $page * $size);
        $rows = self::rows($this->db()->select($sql.' ORDER BY d.fecha_derivacion DESC,d.cod_der DESC LIMIT ? OFFSET ?', $bindings));
        $more = count($rows) > $size;

        return ['derivaciones' => array_slice($rows, 0, $size), 'page' => $page, 'size' => $size, 'hayMas' => $more];
    }

    private function referral(string $code): array
    {
        $row = $this->db()->selectOne(self::REFERRAL_SQL.' AND d.cod_der=?', [$code]);

        return $row ? (array) $row : self::missing('La derivación solicitada no existe.');
    }

    private function assertSecretary(Connection $db, string $user): string
    {
        $row = $db->selectOne('SELECT s.cod_sec FROM secretarias s JOIN usuarios u ON u.cod_usu=s.cod_usu JOIN roles r ON r.cod_rol=u.cod_rol WHERE s.cod_usu=? AND s.estado=\'Activo\' AND u.estado=\'Activo\' AND u.cod_rol=\'ROL002\' AND r.estado=\'Activo\'', [$user]);
        if (! $row) {
            throw new ClinicalOperationException(403, 'Secretaría no habilitada.', 'PROHIBIDO');
        }

        return $row->cod_sec;
    }

    private function pagination(mixed $value, int $minimum, int $maximum, string $field): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
            $number = (int) $value;
        } else {
            throw new ClinicalOperationException(400, "$field debe ser un entero válido.", 'VALIDACION');
        }
        if ($number < $minimum || $number > $maximum) {
            throw new ClinicalOperationException(400, "$field debe estar entre $minimum y $maximum.", 'VALIDACION');
        }

        return $number;
    }
}
