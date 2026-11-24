<?php

namespace App\Services\Administration;

use App\Exceptions\ClinicalOperationException;
use App\Services\Auth\SessionService;
use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Clinical\ClinicalSqlException;
use App\Services\Clinical\ClinicalTransaction;
use App\Support\ClinicalLog;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Throwable;

/** Casos de uso de Administración; conserva los contratos que consumen las vistas Blade. */
class AdministrationService
{
    public function __construct(
        private DatabaseManager $database,
        private ClinicalTransaction $transactions,
        private ClinicalRoleGuard $roles,
        private SessionService $sessions,
    ) {}

    public function request(string $method, string $path, array $data = []): array
    {
        $method = strtoupper($method);
        $path = trim($path, '/');

        try {
            $this->roles->require('ROL004');

            return $this->dispatch($method, $path, $data);
        } catch (QueryException $error) {
            $safeError = ClinicalSqlException::fromQuery($error);
            ClinicalLog::failure('admin.operation', $safeError);
            throw $safeError;
        } catch (Throwable $error) {
            ClinicalLog::failure('admin.operation', $error);
            throw $error;
        }
    }

    private function dispatch(string $method, string $path, array $data): array
    {
        if ($method === 'GET' && $path === 'personal/medicos') {
            return $this->doctors($data['estado'] ?? null);
        }
        if ($method === 'GET' && preg_match('#^personal/medicos/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->doctor($m[1]);
        }
        if ($method === 'POST' && $path === 'personal/medicos') {
            return $this->createDoctor($data);
        }
        if ($method === 'PUT' && preg_match('#^personal/medicos/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->updateDoctor($m[1], $data);
        }
        if ($method === 'PUT' && preg_match('#^personal/medicos/([A-Za-z0-9]+)/estado$#', $path, $m)) {
            return $this->doctorState($m[1], $data);
        }

        if ($method === 'GET' && $path === 'personal/secretarias') {
            return $this->secretaries($data['estado'] ?? null);
        }
        if ($method === 'GET' && preg_match('#^personal/secretarias/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->secretary($m[1]);
        }
        if ($method === 'POST' && $path === 'personal/secretarias') {
            return $this->createSecretary($data);
        }
        if ($method === 'PUT' && preg_match('#^personal/secretarias/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->updateSecretary($m[1], $data);
        }
        if ($method === 'PUT' && preg_match('#^personal/secretarias/([A-Za-z0-9]+)/estado$#', $path, $m)) {
            return $this->secretaryState($m[1], $data);
        }
        if ($method === 'PUT' && preg_match('#^personal/secretarias/([A-Za-z0-9]+)/password$#', $path, $m)) {
            return $this->secretaryPassword($m[1], $data);
        }

        if ($method === 'GET' && $path === 'personal/especialidades') {
            return $this->specialties($data['estado'] ?? 'Todos');
        }
        if ($method === 'GET' && preg_match('#^personal/especialidades/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->specialty($m[1]);
        }
        if ($method === 'GET' && preg_match('#^personal/especialidades/([A-Za-z0-9]+)/medicos$#', $path, $m)) {
            return $this->specialtyDoctors($m[1]);
        }
        if ($method === 'POST' && $path === 'personal/especialidades') {
            return $this->createSpecialty($data);
        }
        if ($method === 'PUT' && preg_match('#^personal/especialidades/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->updateSpecialty($m[1], $data);
        }
        if ($method === 'PUT' && preg_match('#^personal/especialidades/([A-Za-z0-9]+)/estado$#', $path, $m)) {
            return $this->specialtyState($m[1], $data);
        }

        if ($method === 'GET' && preg_match('#^administracion/medicos/([A-Za-z0-9]+)/horarios$#', $path, $m)) {
            return $this->schedules($m[1]);
        }
        if ($method === 'POST' && preg_match('#^administracion/medicos/([A-Za-z0-9]+)/horarios$#', $path, $m)) {
            return $this->createSchedule($m[1], $data);
        }
        if ($method === 'PUT' && preg_match('#^administracion/horarios/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->updateSchedule($m[1], $data);
        }
        if ($method === 'PUT' && preg_match('#^administracion/horarios/([A-Za-z0-9]+)/estado$#', $path, $m)) {
            return $this->scheduleState($m[1], $data);
        }
        if ($method === 'PUT' && preg_match('#^administracion/medicos/([A-Za-z0-9]+)/duracion$#', $path, $m)) {
            return $this->doctorDuration($m[1], $data);
        }

        if ($method === 'GET' && preg_match('#^administracion/medicos/([A-Za-z0-9]+)/bloqueos$#', $path, $m)) {
            return $this->blocks($m[1], $data);
        }
        if ($method === 'POST' && preg_match('#^administracion/medicos/([A-Za-z0-9]+)/bloqueos$#', $path, $m)) {
            return $this->createBlock($m[1], $data);
        }
        if ($method === 'GET' && preg_match('#^administracion/bloqueos/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->block($m[1]);
        }
        if ($method === 'GET' && preg_match('#^administracion/bloqueos/([A-Za-z0-9]+)/citas-afectadas$#', $path, $m)) {
            return $this->affectedAppointments($m[1]);
        }

        if ($method === 'GET' && $path === 'administracion/pacientes') {
            return $this->patients($data);
        }
        if ($method === 'GET' && preg_match('#^administracion/pacientes/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->patient($m[1]);
        }
        if ($method === 'GET' && preg_match('#^administracion/pacientes/([A-Za-z0-9]+)/incidencias$#', $path, $m)) {
            return $this->incidents($m[1], $data, false);
        }
        if ($method === 'GET' && preg_match('#^administracion/pacientes/([A-Za-z0-9]+)/sanciones$#', $path, $m)) {
            return $this->incidents($m[1], $data, true);
        }
        if ($method === 'GET' && preg_match('#^administracion/pacientes/([A-Za-z0-9]+)/sanciones/([A-Za-z0-9]+)$#', $path, $m)) {
            return $this->sanction($m[1], $m[2]);
        }
        if ($method === 'POST' && preg_match('#^administracion/pacientes/([A-Za-z0-9]+)/sanciones$#', $path, $m)) {
            return $this->createSanction($m[1], $data);
        }

        throw new ClinicalOperationException(404, 'La operación administrativa solicitada no existe.', 'NO_ENCONTRADO');
    }

    private function db(): Connection
    {
        return $this->database->connection('clinical');
    }

    private static function rows(array $rows): array
    {
        return array_map(fn ($r) => (array) $r, $rows);
    }

    private static function notFound(string $message): never
    {
        throw new ClinicalOperationException(404, $message, 'NO_ENCONTRADO');
    }

    private function doctors(?string $state): array
    {
        $bindings = [];
        $where = '';
        if ($state !== null && $state !== '') {
            $where = ' WHERE m.estado = ?';
            $bindings[] = $state;
        }
        $rows = self::rows($this->db()->select('SELECT m.cod_med AS "codMed",m.cod_usu AS "codUsu",u.nombre_completo AS "nombreCompleto",u.email,m.ci,m.matricula_profesional AS "matriculaProfesional",m.resumen_curricular AS "resumenCurricular",m.duracion_consulta_min AS "duracionConsultaMin",m.estado FROM medicos m JOIN usuarios u ON u.cod_usu=m.cod_usu'.$where.' ORDER BY m.cod_med', $bindings));
        if ($rows === []) {
            return [];
        }
        $links = self::rows($this->db()->select('SELECT me.cod_med AS "codMed",me.cod_esp AS "codEsp",e.nombre_especialidad AS "nombreEspecialidad",me.duracion_consulta_min AS "duracionConsultaMin",me.estado FROM medico_especialidades me JOIN especialidades e ON e.cod_esp=me.cod_esp ORDER BY me.cod_med,me.cod_esp'));
        foreach ($rows as &$row) {
            $row['especialidades'] = array_values(array_filter($links, fn ($link) => $link['codMed'] === $row['codMed']));
        }
        unset($row);
        foreach ($rows as &$row) {
            foreach ($row['especialidades'] as &$link) {
                unset($link['codMed']);
            }
        }

        return $rows;
    }

    private function doctor(string $code): array
    {
        $row = collect($this->doctors(null))->firstWhere('codMed', $code);

        return $row ?? self::notFound('El médico solicitado no existe.');
    }

    private function createDoctor(array $data): array
    {
        $code = $this->transactions->run(function (Connection $db) use ($data) {
            $this->assertAdmin($db);
            $user = $this->insertUser($db, $data, 'ROL003');
            $row = $db->selectOne('INSERT INTO medicos(cod_usu,ci,matricula_profesional,resumen_curricular,duracion_consulta_min,estado) VALUES (?,NULLIF(?,\'\'),?,NULLIF(?,\'\'),?,\'Activo\') RETURNING cod_med', [$user, $data['ci'] ?? '', trim($data['matriculaProfesional']), $data['resumenCurricular'] ?? '', $data['duracionConsultaMin'] ?? null]);
            $this->syncDoctorSpecialties($db, $row->cod_med, $data['codigosEspecialidades'] ?? [], (int) ($data['duracionConsultaMin'] ?? 30), []);

            return $row->cod_med;
        }, 'admin.doctor.created', ['action' => 'create']);

        return $this->doctor($code);
    }

    private function updateDoctor(string $code, array $data): array
    {
        $revoke = null;
        $this->transactions->run(function (Connection $db) use ($code, $data, &$revoke) {
            $this->assertAdmin($db);
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$code]);
            $row = $db->selectOne('SELECT m.cod_usu,m.duracion_consulta_min FROM medicos m WHERE cod_med=? FOR UPDATE', [$code]);
            if (! $row) {
                self::notFound('El médico solicitado no existe.');
            }
            $password = filled($data['password'] ?? null) ? Hash::make($data['password']) : null;
            $db->update('UPDATE usuarios SET nombre_completo=?,email=?,password_hash=COALESCE(?,password_hash),actualizado_en=now() WHERE cod_usu=?', [trim($data['nombreCompleto']), mb_strtolower(trim($data['email'])), $password, $row->cod_usu]);
            $db->update('UPDATE medicos SET ci=NULLIF(?,\'\'),matricula_profesional=?,resumen_curricular=NULLIF(?,\'\'),duracion_consulta_min=? WHERE cod_med=?', [$data['ci'] ?? '', trim($data['matriculaProfesional']), $data['resumenCurricular'] ?? '', $data['duracionConsultaMin'] ?? null, $code]);
            $active = $db->table('medico_especialidades')->where('cod_med', $code)->where('estado', 'Activo')->pluck('cod_esp')->all();
            $this->syncDoctorSpecialties($db, $code, $data['codigosEspecialidades'] ?? $active, (int) ($data['duracionConsultaMin'] ?? 30), $active);
            if ((int) $row->duracion_consulta_min !== (int) ($data['duracionConsultaMin'] ?? 0)) {
                $db->update('UPDATE medico_especialidades SET duracion_consulta_min=? WHERE cod_med=? AND estado=\'Activo\'', [$data['duracionConsultaMin'], $code]);
            }
            if ($password) {
                $revoke = $row->cod_usu;
            }
        }, 'admin.doctor.updated', ['entity_id' => $code, 'action' => 'update']);
        if ($revoke) {
            $this->sessions->revokeAllForUser($revoke);
        }

        return $this->doctor($code);
    }

    private function doctorState(string $code, array $data): array
    {
        $user = $this->transactions->run(function (Connection $db) use ($code, $data) {
            $this->assertAdmin($db);
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$code]);
            $row = $db->selectOne('SELECT cod_usu FROM medicos WHERE cod_med=? FOR UPDATE', [$code]);
            if (! $row) {
                self::notFound('El médico solicitado no existe.');
            }
            $db->update('UPDATE medicos SET estado=? WHERE cod_med=?', [$data['estado'], $code]);
            $db->update('UPDATE usuarios SET estado=?,actualizado_en=now() WHERE cod_usu=?', [$data['estado'], $row->cod_usu]);

            return $row->cod_usu;
        }, 'admin.staff.status_changed', ['entity_id' => $code, 'action' => 'status']);
        if (($data['estado'] ?? null) === 'Inactivo') {
            $this->sessions->revokeAllForUser($user);
        }

        return $this->doctor($code);
    }

    private function secretaries(?string $state): array
    {
        $sql = 'SELECT s.cod_sec AS "codSec",u.cod_usu AS "codUsu",u.nombre_completo AS "nombreCompleto",u.email,s.ci,s.telefono,s.estado FROM secretarias s JOIN usuarios u ON u.cod_usu=s.cod_usu WHERE u.cod_rol=\'ROL002\'';
        $bindings = [];
        if ($state) {
            $sql .= ' AND s.estado=?';
            $bindings[] = $state;
        }

        return self::rows($this->db()->select($sql.' ORDER BY s.cod_sec', $bindings));
    }

    private function secretary(string $code): array
    {
        $row = collect($this->secretaries(null))->firstWhere('codSec', $code);

        return $row ?? self::notFound('La secretaria solicitada no existe.');
    }

    private function createSecretary(array $data): array
    {
        $code = $this->transactions->run(function (Connection $db) use ($data) {
            $this->assertAdmin($db);
            $user = $this->insertUser($db, $data, 'ROL002');

            return $db->selectOne('INSERT INTO secretarias(cod_usu,ci,telefono,estado) VALUES (?,NULLIF(?,\'\'),NULLIF(?,\'\'),\'Activo\') RETURNING cod_sec', [$user, $data['ci'] ?? '', $data['telefono'] ?? ''])->cod_sec;
        }, 'admin.secretary.created', ['action' => 'create']);

        return $this->secretary($code);
    }

    private function updateSecretary(string $code, array $data): array
    {
        $revoke = null;
        $this->transactions->run(function (Connection $db) use ($code, $data, &$revoke) {
            $this->assertAdmin($db);
            $row = $db->selectOne('SELECT cod_usu FROM secretarias WHERE cod_sec=? FOR UPDATE', [$code]);
            if (! $row) {
                self::notFound('La secretaria solicitada no existe.');
            }
            $password = filled($data['password'] ?? null) ? Hash::make($data['password']) : null;
            $db->update('UPDATE usuarios SET nombre_completo=?,email=?,password_hash=COALESCE(?,password_hash),actualizado_en=now() WHERE cod_usu=?', [trim($data['nombreCompleto']), mb_strtolower(trim($data['email'])), $password, $row->cod_usu]);
            $db->update('UPDATE secretarias SET ci=NULLIF(?,\'\'),telefono=NULLIF(?,\'\') WHERE cod_sec=?', [$data['ci'] ?? '', $data['telefono'] ?? '', $code]);
            if ($password) {
                $revoke = $row->cod_usu;
            }
        }, 'admin.secretary.updated', ['entity_id' => $code, 'action' => 'update']);
        if ($revoke) {
            $this->sessions->revokeAllForUser($revoke);
        }

        return $this->secretary($code);
    }

    private function secretaryState(string $code, array $data): array
    {
        $user = $this->transactions->run(function (Connection $db) use ($code, $data) {
            $this->assertAdmin($db);
            $row = $db->selectOne('SELECT cod_usu FROM secretarias WHERE cod_sec=? FOR UPDATE', [$code]);
            if (! $row) {
                self::notFound('La secretaria solicitada no existe.');
            }
            $db->update('UPDATE secretarias SET estado=? WHERE cod_sec=?', [$data['estado'], $code]);
            $db->update('UPDATE usuarios SET estado=?,actualizado_en=now() WHERE cod_usu=?', [$data['estado'], $row->cod_usu]);

            return $row->cod_usu;
        }, 'admin.staff.status_changed', ['entity_id' => $code, 'action' => 'status']);
        if (($data['estado'] ?? null) === 'Inactivo') {
            $this->sessions->revokeAllForUser($user);
        }

        return $this->secretary($code);
    }

    private function secretaryPassword(string $code, array $data): array
    {
        $user = $this->transactions->run(function (Connection $db) use ($code, $data) {
            $this->assertAdmin($db);
            $row = $db->selectOne('SELECT cod_usu FROM secretarias WHERE cod_sec=? FOR UPDATE', [$code]);
            if (! $row) {
                self::notFound('La secretaria solicitada no existe.');
            }
            $db->update('UPDATE usuarios SET password_hash=?,actualizado_en=now() WHERE cod_usu=?', [Hash::make($data['password']), $row->cod_usu]);

            return $row->cod_usu;
        }, 'admin.secretary.password_changed', ['entity_id' => $code, 'action' => 'password']);
        $this->sessions->revokeAllForUser($user);

        return $this->secretary($code);
    }

    private function insertUser(Connection $db, array $data, string $role): string
    {
        return $db->selectOne('INSERT INTO usuarios(nombre_completo,email,password_hash,cod_rol,estado) VALUES (?,?,?, ?,\'Activo\') RETURNING cod_usu', [trim($data['nombreCompleto']), mb_strtolower(trim($data['email'])), Hash::make($data['password']), $role])->cod_usu;
    }

    private function syncDoctorSpecialties(Connection $db, string $doctor, array $codes, int $duration, array $current): void
    {
        $codes = array_values(array_unique(array_filter($codes)));
        if ($codes === []) {
            throw new ClinicalOperationException(400, 'Debe seleccionar al menos una especialidad.', 'VALIDACION');
        }
        foreach ($codes as $code) {
            $specialty = $db->selectOne('SELECT estado FROM especialidades WHERE cod_esp=?', [$code]);
            if (! $specialty || ($specialty->estado !== 'Activo' && ! in_array($code, $current, true))) {
                throw new ClinicalOperationException(400, 'La especialidad seleccionada no está habilitada.', 'VALIDACION');
            }
            $db->statement('INSERT INTO medico_especialidades(cod_med,cod_esp,duracion_consulta_min,estado) VALUES (?,?,?,\'Activo\') ON CONFLICT(cod_med,cod_esp) DO UPDATE SET estado=\'Activo\',duracion_consulta_min=EXCLUDED.duracion_consulta_min', [$doctor, $code, $duration]);
        }
        foreach (array_diff($current, $codes) as $code) {
            $db->update('UPDATE medico_especialidades SET estado=\'Inactivo\' WHERE cod_med=? AND cod_esp=?', [$doctor, $code]);
        }
    }

    private function specialties(string $state): array
    {
        $sql = 'SELECT cod_esp AS "codEsp",nombre_especialidad AS "nombreEspecialidad",descripcion,duracion_consulta_min AS "duracionConsultaMin",estado FROM especialidades';
        $bindings = [];
        if ($state !== 'Todos' && $state !== '') {
            $sql .= ' WHERE estado=?';
            $bindings[] = $state;
        }

        return self::rows($this->db()->select($sql.' ORDER BY nombre_especialidad,cod_esp', $bindings));
    }

    private function specialty(string $code): array
    {
        $row = collect($this->specialties('Todos'))->firstWhere('codEsp', $code);

        return $row ?? self::notFound('La especialidad solicitada no existe.');
    }

    private function specialtyDoctors(string $code): array
    {
        $this->specialty($code);

        return self::rows($this->db()->select('SELECT m.cod_med AS "codMed",u.nombre_completo AS "nombreCompleto",m.estado AS "estadoMedico",me.estado AS "estadoVinculo",me.duracion_consulta_min AS "duracionConsultaMin" FROM medico_especialidades me JOIN medicos m ON m.cod_med=me.cod_med JOIN usuarios u ON u.cod_usu=m.cod_usu WHERE me.cod_esp=? ORDER BY m.cod_med', [$code]));
    }

    private function createSpecialty(array $data): array
    {
        $code = $this->transactions->run(function (Connection $db) use ($data) {
            $this->assertAdmin($db);
            $name = trim($data['nombreEspecialidad']);
            $db->selectOne("SELECT pg_advisory_xact_lock(hashtextextended('especialidades:' || lower(?),0))", [$name]);

            return $db->selectOne('INSERT INTO especialidades(nombre_especialidad,descripcion,duracion_consulta_min,estado) VALUES (?,NULLIF(?,\'\'),?,\'Activo\') RETURNING cod_esp', [$name, $data['descripcion'] ?? '', $data['duracionConsultaMin']])->cod_esp;
        }, 'admin.specialty.changed', ['action' => 'create']);

        return $this->specialty($code);
    }

    private function updateSpecialty(string $code, array $data): array
    {
        $this->transactions->run(function (Connection $db) use ($code, $data) {
            $this->assertAdmin($db);
            $row = $db->selectOne('SELECT cod_esp FROM especialidades WHERE cod_esp=? FOR UPDATE', [$code]);
            if (! $row) {
                self::notFound('La especialidad solicitada no existe.');
            }
            $db->update('UPDATE especialidades SET nombre_especialidad=?,descripcion=NULLIF(?,\'\') WHERE cod_esp=?', [trim($data['nombreEspecialidad']), $data['descripcion'] ?? '', $code]);
        }, 'admin.specialty.changed', ['entity_id' => $code, 'action' => 'update']);

        return $this->specialty($code);
    }

    private function specialtyState(string $code, array $data): array
    {
        $this->transactions->run(function (Connection $db) use ($code, $data) {
            $this->assertAdmin($db);
            if (! $db->update('UPDATE especialidades SET estado=? WHERE cod_esp=?', [$data['estado'], $code])) {
                self::notFound('La especialidad solicitada no existe.');
            }
        }, 'admin.specialty.changed', ['entity_id' => $code, 'action' => 'status']);

        return $this->specialty($code);
    }

    private function schedules(string $doctor): array
    {
        $this->doctor($doctor);

        return self::rows($this->db()->select('SELECT cod_hor AS "codHor",cod_med AS "codMed",dia_semana AS "diaSemana",to_char(hora_inicio,\'HH24:MI\') AS "horaInicio",to_char(hora_fin,\'HH24:MI\') AS "horaFin",to_char(pausa_inicio,\'HH24:MI\') AS "pausaInicio",to_char(pausa_fin,\'HH24:MI\') AS "pausaFin",estado FROM horarios WHERE cod_med=? ORDER BY dia_semana,hora_inicio,cod_hor', [$doctor]));
    }

    private function createSchedule(string $doctor, array $data): array
    {
        $code = $this->transactions->run(function (Connection $db) use ($doctor, $data) {
            $this->assertAdmin($db);
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$doctor]);
            if (! $db->table('medicos')->where('cod_med', $doctor)->where('estado', 'Activo')->exists()) {
                throw new ClinicalOperationException(409, 'El médico no está activo.', 'MEDICO_INACTIVO');
            }

            return $db->selectOne('INSERT INTO horarios(cod_med,dia_semana,hora_inicio,hora_fin,pausa_inicio,pausa_fin,estado) VALUES (?,?,?,?,NULLIF(?,\'\')::time,NULLIF(?,\'\')::time,\'Activo\') RETURNING cod_hor', [$doctor, $data['diaSemana'], $data['horaInicio'], $data['horaFin'], $data['pausaInicio'] ?? '', $data['pausaFin'] ?? ''])->cod_hor;
        }, 'admin.schedule.changed', ['action' => 'create']);

        return collect($this->schedules($doctor))->firstWhere('codHor', $code);
    }

    private function updateSchedule(string $code, array $data): array
    {
        $doctor = $this->transactions->run(function (Connection $db) use ($code, $data) {
            $this->assertAdmin($db);
            $row = $db->selectOne('SELECT cod_med FROM horarios WHERE cod_hor=? FOR UPDATE', [$code]);
            if (! $row) {
                self::notFound('El horario solicitado no existe.');
            }
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$row->cod_med]);
            $db->update('UPDATE horarios SET dia_semana=?,hora_inicio=?,hora_fin=?,pausa_inicio=NULLIF(?,\'\')::time,pausa_fin=NULLIF(?,\'\')::time WHERE cod_hor=?', [$data['diaSemana'], $data['horaInicio'], $data['horaFin'], $data['pausaInicio'] ?? '', $data['pausaFin'] ?? '', $code]);

            return $row->cod_med;
        }, 'admin.schedule.changed', ['entity_id' => $code, 'action' => 'update']);

        return collect($this->schedules($doctor))->firstWhere('codHor', $code);
    }

    private function scheduleState(string $code, array $data): array
    {
        $doctor = $this->transactions->run(function (Connection $db) use ($code, $data) {
            $this->assertAdmin($db);
            $row = $db->selectOne('SELECT cod_med FROM horarios WHERE cod_hor=? FOR UPDATE', [$code]);
            if (! $row) {
                self::notFound('El horario solicitado no existe.');
            }
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$row->cod_med]);
            $db->update('UPDATE horarios SET estado=? WHERE cod_hor=?', [$data['estado'], $code]);

            return $row->cod_med;
        }, 'admin.schedule.changed', ['entity_id' => $code, 'action' => 'status']);

        return collect($this->schedules($doctor))->firstWhere('codHor', $code);
    }

    private function doctorDuration(string $doctor, array $data): array
    {
        $this->transactions->run(function (Connection $db) use ($doctor, $data) {
            $this->assertAdmin($db);
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$doctor]);
            if (! $db->update('UPDATE medicos SET duracion_consulta_min=? WHERE cod_med=?', [$data['duracionConsultaMin'], $doctor])) {
                self::notFound('El médico solicitado no existe.');
            }
            $db->update('UPDATE medico_especialidades SET duracion_consulta_min=? WHERE cod_med=? AND estado=\'Activo\'', [$data['duracionConsultaMin'], $doctor]);
        }, 'admin.schedule.changed', ['entity_id' => $doctor, 'action' => 'duration']);

        return $this->doctor($doctor);
    }

    private function blocks(string $doctor, array $filters): array
    {
        $sql = 'SELECT b.cod_blo AS "codBlo",b.cod_med AS "codMed",b.cod_usu_admin AS "codUsuAdmin",b.fecha_bloqueo AS "fechaBloqueo",to_char(b.hora_inicio,\'HH24:MI\') AS "horaInicio",to_char(b.hora_fin,\'HH24:MI\') AS "horaFin",b.motivo,b.creado_en AS "creadoEn",(SELECT count(*) FROM bloqueos_citas_afectadas a WHERE a.cod_blo=b.cod_blo) AS "cantidadCitasAfectadas",b.trazabilidad_completa AS "trazabilidadCompleta" FROM bloqueos_agenda b WHERE b.cod_med=?';
        $bindings = [$doctor];
        if (filled($filters['desde'] ?? null)) {
            $sql .= ' AND b.fecha_bloqueo>=?';
            $bindings[] = $filters['desde'];
        }
        if (filled($filters['hasta'] ?? null)) {
            $sql .= ' AND b.fecha_bloqueo<=?';
            $bindings[] = $filters['hasta'];
        }

        return self::rows($this->db()->select($sql.' ORDER BY b.fecha_bloqueo DESC,b.cod_blo DESC', $bindings));
    }

    private function createBlock(string $doctor, array $data): array
    {
        $actor = $this->roles->require('ROL004')->cod_usu;
        $code = $this->transactions->run(function (Connection $db) use ($doctor, $data, $actor) {
            $this->assertAdmin($db);
            $db->selectOne('SELECT bloquear_agenda_medico(?)', [$doctor]);

            return $db->selectOne('INSERT INTO bloqueos_agenda(cod_med,cod_usu_admin,fecha_bloqueo,hora_inicio,hora_fin,motivo) VALUES (?,?,?,NULLIF(?,\'\')::time,NULLIF(?,\'\')::time,?) RETURNING cod_blo', [$doctor, $actor, $data['fechaBloqueo'], $data['horaInicio'] ?? '', $data['horaFin'] ?? '', trim($data['motivo'])])->cod_blo;
        }, 'admin.block.created', ['action' => 'create']);

        return $this->block($code);
    }

    private function block(string $code): array
    {
        $row = $this->db()->selectOne('SELECT b.cod_blo AS "codBlo",b.cod_med AS "codMed",b.cod_usu_admin AS "codUsuAdmin",b.fecha_bloqueo AS "fechaBloqueo",to_char(b.hora_inicio,\'HH24:MI\') AS "horaInicio",to_char(b.hora_fin,\'HH24:MI\') AS "horaFin",b.motivo,b.creado_en AS "creadoEn",(SELECT count(*) FROM bloqueos_citas_afectadas a WHERE a.cod_blo=b.cod_blo) AS "cantidadCitasAfectadas",b.trazabilidad_completa AS "trazabilidadCompleta" FROM bloqueos_agenda b WHERE b.cod_blo=?', [$code]);

        return $row ? (array) $row : self::notFound('El bloqueo solicitado no existe.');
    }

    private function affectedAppointments(string $code): array
    {
        $block = $this->block($code);
        $rows = self::rows($this->db()->select('SELECT c.cod_cit AS "codCit",c.cod_pac AS "codPac",c.cod_med AS "codMed",c.cod_esp AS "codEsp",c.fecha,c.hora_inicio AS "horaInicio",c.hora_fin AS "horaFin",c.estado FROM bloqueos_citas_afectadas a JOIN citas c ON c.cod_cit=a.cod_cit WHERE a.cod_blo=? ORDER BY c.fecha,c.hora_inicio,c.cod_cit', [$code]));

        return ['codBlo' => $code, 'trazabilidadCompleta' => (bool) $block['trazabilidadCompleta'], 'citas' => $rows];
    }

    private const PATIENT_SQL = 'SELECT p.cod_pac AS "codPac",u.nombre_completo AS "nombreCompleto",p.ci,u.email,p.telefono,p.ciudad_residencia AS "ciudadResidencia",estado_reserva_paciente(p.cod_pac) AS "estadoCuenta",u.estado AS "estadoUsuario",p.email_verificado AS "emailVerificado",p.bloqueado_hasta AS "bloqueadoHasta",(u.estado=\'Activo\' AND r.estado=\'Activo\' AND estado_reserva_paciente(p.cod_pac)=\'Activo\' AND p.email_verificado) AS "habilitadoParaReservar",CASE WHEN u.estado<>\'Activo\' THEN \'USUARIO_INACTIVO\' WHEN r.estado<>\'Activo\' THEN \'ROL_INACTIVO\' WHEN estado_reserva_paciente(p.cod_pac)=\'Bloqueado_Temporal\' THEN \'PACIENTE_BLOQUEADO\' WHEN estado_reserva_paciente(p.cod_pac)<>\'Activo\' THEN \'PACIENTE_INACTIVO\' WHEN p.email_verificado=false THEN \'EMAIL_NO_VERIFICADO\' ELSE NULL END AS "motivoInhabilitacion" FROM pacientes p JOIN usuarios u ON u.cod_usu=p.cod_usu JOIN roles r ON r.cod_rol=u.cod_rol WHERE u.cod_rol=\'ROL001\'';

    private function patients(array $filters): array
    {
        $page = (int) ($filters['page'] ?? 0);
        $size = (int) ($filters['size'] ?? 20);
        $sql = self::PATIENT_SQL;
        $bindings = [];
        if (filled($filters['q'] ?? null)) {
            $sql .= ' AND (lower(u.nombre_completo) LIKE ? OR lower(u.email) LIKE ? OR lower(p.ci) LIKE ?)';
            $q = '%'.mb_strtolower(trim($filters['q'])).'%';
            array_push($bindings, $q, $q, $q);
        }
        if (filled($filters['estado'] ?? null)) {
            $sql .= ' AND estado_reserva_paciente(p.cod_pac)=?';
            $bindings[] = $filters['estado'];
        }
        $bindings[] = $size + 1;
        $bindings[] = $page * $size;
        $rows = self::rows($this->db()->select($sql.' ORDER BY u.nombre_completo,p.cod_pac LIMIT ? OFFSET ?', $bindings));
        $more = count($rows) > $size;

        return ['pacientes' => array_slice($rows, 0, $size), 'page' => $page, 'size' => $size, 'hayMas' => $more];
    }

    private function patient(string $code): array
    {
        $row = $this->db()->selectOne(self::PATIENT_SQL.' AND p.cod_pac=?', [$code]);

        return $row ? (array) $row : self::notFound('El paciente solicitado no existe.');
    }

    private function incidents(string $patient, array $filters, bool $sanctions): array
    {
        $this->patient($patient);
        $page = (int) ($filters['page'] ?? 0);
        $size = (int) ($filters['size'] ?? 20);
        $sql = 'SELECT i.cod_inc AS "codInc",i.cod_pac AS "codPac",i.cod_cit AS "codCit",i.tipo_incidencia AS "tipoIncidencia",i.motivo,i.registrado_por_usu AS "registradoPorUsu",i.fecha_fin_sancion AS "fechaFinSancion",i.fecha_registro AS "fechaRegistro",(i.tipo_incidencia=\'SANCION_BLOQUEO\' AND i.fecha_fin_sancion>now()) AS vigente FROM incidencias_sanciones i WHERE i.cod_pac=?';
        $bindings = [$patient];
        if ($sanctions) {
            $sql .= " AND i.tipo_incidencia='SANCION_BLOQUEO'";
        } elseif (filled($filters['tipo'] ?? null)) {
            $sql .= ' AND i.tipo_incidencia=?';
            $bindings[] = $filters['tipo'];
        }
        if ($sanctions && array_key_exists('vigente', $filters) && $filters['vigente'] !== null) {
            $sql .= filter_var($filters['vigente'], FILTER_VALIDATE_BOOLEAN) ? ' AND i.fecha_fin_sancion>now()' : ' AND i.fecha_fin_sancion<=now()';
        }
        array_push($bindings, $size + 1, $page * $size);
        $rows = self::rows($this->db()->select($sql.' ORDER BY i.fecha_registro DESC,i.cod_inc DESC LIMIT ? OFFSET ?', $bindings));
        $more = count($rows) > $size;

        return ['incidencias' => array_slice($rows, 0, $size), 'page' => $page, 'size' => $size, 'hayMas' => $more];
    }

    private function sanction(string $patient, string $code): array
    {
        $row = $this->db()->selectOne('SELECT cod_inc AS "codInc",cod_pac AS "codPac",cod_cit AS "codCit",tipo_incidencia AS "tipoIncidencia",motivo,registrado_por_usu AS "registradoPorUsu",fecha_fin_sancion AS "fechaFinSancion",fecha_registro AS "fechaRegistro",(fecha_fin_sancion>now()) AS vigente FROM incidencias_sanciones WHERE cod_pac=? AND cod_inc=? AND tipo_incidencia=\'SANCION_BLOQUEO\'', [$patient, $code]);

        return $row ? (array) $row : self::notFound('La sanción solicitada no existe.');
    }

    private function createSanction(string $patient, array $data): array
    {
        $actor = $this->roles->require('ROL004')->cod_usu;
        $code = $this->transactions->run(function (Connection $db) use ($patient, $data, $actor) {
            $this->assertAdmin($db);

            return $db->scalar('SELECT registrar_sancion(?,?,?,?)', [$patient, $actor, trim($data['motivo']), (int) $data['dias']]);
        }, 'admin.sanction.changed', ['action' => 'create']);

        return $this->sanction($patient, $code);
    }

    private function assertAdmin(Connection $db): void
    {
        $actor = $this->roles->require('ROL004')->cod_usu;
        if (! $db->table('usuarios')->where('cod_usu', $actor)->where('cod_rol', 'ROL004')->where('estado', 'Activo')->exists()) {
            throw new ClinicalOperationException(403, 'Administrador no autorizado.', 'PROHIBIDO');
        }
    }
}
