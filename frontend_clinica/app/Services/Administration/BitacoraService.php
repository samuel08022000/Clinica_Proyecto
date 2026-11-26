<?php

namespace App\Services\Administration;

use App\Exceptions\ClinicalOperationException;
use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Clinical\ClinicalSqlException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

class BitacoraService
{
    public const SECTIONS = ['pacientes' => 'Pacientes', 'secretarias' => 'Secretarios', 'medicos' => 'Médicos'];

    public const ENTITIES = [
        'usuarios' => 'Cuenta de usuario', 'pacientes' => 'Perfil de paciente', 'secretarias' => 'Perfil de secretario',
        'medicos' => 'Perfil de médico', 'citas' => 'Cita', 'especialidades' => 'Especialidad',
        'medico_especialidades' => 'Vínculo de especialidad', 'horarios' => 'Horario', 'bloqueos_agenda' => 'Bloqueo de agenda',
        'derivaciones' => 'Derivación', 'analisis' => 'Análisis', 'incidencias_sanciones' => 'Incidencia o sanción',
        'bloqueos_citas_afectadas' => 'Cita afectada por bloqueo',
    ];

    public const MAX_EXPORT_ROWS = 2000;

    private const FIELDS = [
        'nombre_completo' => 'Nombre', 'estado' => 'Estado', 'estado_cuenta' => 'Estado de cuenta',
        'cod_rol' => 'Rol', 'email_verificado' => 'Correo verificado', 'telefono_verificado' => 'Teléfono verificado',
        'bloqueado_hasta' => 'Bloqueo hasta', 'fecha' => 'Fecha', 'hora_inicio' => 'Inicio', 'hora_fin' => 'Fin',
        'dia_semana' => 'Día de semana', 'pausa_inicio' => 'Inicio de pausa', 'pausa_fin' => 'Fin de pausa',
        'duracion_consulta_min' => 'Duración de consulta (min)', 'canal_reserva' => 'Canal de reserva',
        'cod_esp' => 'Especialidad', 'cod_med' => 'Médico', 'cod_sec' => 'Secretario',
        'hora_llegada_checkin' => 'Llegada registrada', 'checkin_por_usu' => 'Llegada registrada por',
        'hora_inicio_atencion' => 'Inicio de atención', 'fecha_atencion' => 'Atención finalizada', 'atendida_por_usu' => 'Atendida por',
        'cancelado_por_usu' => 'Cancelada por', 'tipo_incidencia' => 'Tipo de incidencia',
        'fecha_fin_sancion' => 'Fin de sanción', 'cod_esp_destino' => 'Especialidad de destino',
        'cod_med_destino' => 'Médico de destino', 'cod_cit_hija' => 'Cita de destino', 'cod_cit_padre' => 'Cita de origen',
    ];

    public function __construct(private DatabaseManager $database, private ClinicalRoleGuard $roles) {}

    /** Consulta inmutable; cuenta y página comparten la misma lectura de PostgreSQL. */
    public function listing(string $section, array $filters = [], bool $export = false): array
    {
        if (! isset(self::SECTIONS[$section])) {
            throw new ClinicalOperationException(404, 'El apartado de bitácora no existe.', 'NO_ENCONTRADO');
        }

        try {
            $this->roles->require('ROL004');
            $db = $this->database->connection('clinical');
            $today = new DateTimeImmutable((string) $db->scalar('SELECT fecha_clinica()'));
            $from = $filters['desde'] ?? $today->modify('first day of this month')->format('Y-m-d');
            $to = $filters['hasta'] ?? $today->format('Y-m-d');
            $relation = $filters['relacion'] ?? 'relacionados';
            $page = $export ? 0 : (int) ($filters['page'] ?? 0);
            $limit = $export ? self::MAX_EXPORT_ROWS + 1 : 25;
            [$table, $code, $association] = $this->association($section);
            if ($relation === 'realizados') {
                $association = 'p.cod_usu = b.cod_usu';
            }
            $bindings = [];
            $personFilter = '';
            if (filled($filters['persona'] ?? null)) {
                $personFilter = " AND p.$code = ?";
                $bindings[] = $filters['persona'];
            }
            array_push($bindings, $from, $to);
            $where = [];
            foreach (['accion' => 'accion', 'entidad' => 'nombre_tabla'] as $filter => $column) {
                if (filled($filters[$filter] ?? null)) {
                    $where[] = "$column = ?";
                    $bindings[] = $filters[$filter];
                }
            }
            if (filled($filters['q'] ?? null)) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';
                $where[] = <<<'SQL'
                    (cod_bit ILIKE ? OR clave_registro ILIKE ? OR responsable ILIKE ? OR EXISTS (
                        SELECT 1 FROM json_array_elements(personas) AS person
                        WHERE person->>'name' ILIKE ? OR person->>'code' ILIKE ?
                    ))
                    SQL;
                array_push($bindings, ...array_fill(0, 5, $term));
            }
            $predicate = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $safeKeys = "'".implode("','", array_keys(self::FIELDS))."'";
            array_push($bindings, $limit, $page * $limit);
            $result = $db->selectOne(<<<SQL
                WITH registros AS MATERIALIZED (
                    SELECT b.*, actor.nombre_completo AS responsable, personas.items AS personas
                    FROM bitacora b
                    LEFT JOIN usuarios actor ON actor.cod_usu = b.cod_usu
                    LEFT JOIN citas c ON b.nombre_tabla IN ('analisis','derivaciones','bloqueos_citas_afectadas')
                        AND c.cod_cit = coalesce(b.datos_nuevos->>'cod_cit', b.datos_anteriores->>'cod_cit',
                            b.datos_nuevos->>'cod_cit_origen', b.datos_anteriores->>'cod_cit_origen')
                    JOIN LATERAL (
                        SELECT json_agg(json_build_object('code',p.$code,'name',u.nombre_completo)
                            ORDER BY u.nombre_completo,p.$code) AS items
                        FROM $table p JOIN usuarios u ON u.cod_usu=p.cod_usu
                        WHERE ($association) $personFilter
                    ) personas ON personas.items IS NOT NULL
                    WHERE b.registrado_en >= (?::date::timestamp AT TIME ZONE 'America/La_Paz')
                        AND b.registrado_en < ((?::date+1)::timestamp AT TIME ZONE 'America/La_Paz')
                ), filtrados AS (
                    SELECT * FROM registros $predicate
                ), pagina AS (
                    SELECT * FROM filtrados ORDER BY registrado_en DESC,secuencial DESC LIMIT ? OFFSET ?
                )
                SELECT (SELECT count(*) FROM filtrados) AS total,
                    coalesce((SELECT json_agg(json_build_object(
                        'id',cod_bit,'date',to_char(registrado_en AT TIME ZONE 'America/La_Paz','DD/MM/YYYY HH24:MI:SS'),
                        'actorCode',cod_usu,'actor',responsable,'people',personas,
                        'action',accion,'table',nombre_tabla,'reference',clave_registro,
                        'before',(SELECT coalesce(jsonb_object_agg(key,value),'{}'::jsonb)
                            FROM jsonb_each(coalesce(datos_anteriores,'{}'::jsonb)) WHERE key IN ($safeKeys)),
                        'after',(SELECT coalesce(jsonb_object_agg(key,value),'{}'::jsonb)
                            FROM jsonb_each(coalesce(datos_nuevos,'{}'::jsonb)) WHERE key IN ($safeKeys)))
                        ORDER BY registrado_en DESC,secuencial DESC) FROM pagina),'[]'::json) AS records,
                    to_char(now() AT TIME ZONE 'America/La_Paz','DD/MM/YYYY HH24:MI:SS') AS generated_at
                SQL, $bindings);

            $total = (int) $result->total;
            if ($export && $total > self::MAX_EXPORT_ROWS) {
                throw new ClinicalOperationException(422, 'El reporte supera los '.self::MAX_EXPORT_ROWS.' registros. Reduce el período o selecciona una persona para exportar todos los resultados.', 'REPORTE_EXTENSO');
            }

            return [
                'section' => $section, 'title' => self::SECTIONS[$section],
                'filters' => array_filter(array_replace($filters, ['desde' => $from, 'hasta' => $to, 'relacion' => $relation]), fn ($value) => $value !== null && $value !== ''),
                'records' => array_map($this->present(...), json_decode($result->records, true, flags: JSON_THROW_ON_ERROR)),
                'total' => $total, 'page' => $page, 'hayMas' => ($page + 1) * $limit < $total,
                'generatedAt' => $result->generated_at,
            ];
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }

    private function association(string $section): array
    {
        return match ($section) {
            'pacientes' => ['pacientes', 'cod_pac', <<<'SQL'
                (b.nombre_tabla='pacientes' AND b.clave_registro=p.cod_pac)
                OR (b.nombre_tabla='usuarios' AND b.clave_registro=p.cod_usu)
                OR (b.nombre_tabla IN ('citas','incidencias_sanciones') AND p.cod_pac IN (b.datos_nuevos->>'cod_pac',b.datos_anteriores->>'cod_pac'))
                OR c.cod_pac=p.cod_pac
                SQL],
            'medicos' => ['medicos', 'cod_med', <<<'SQL'
                (b.nombre_tabla='medicos' AND b.clave_registro=p.cod_med)
                OR (b.nombre_tabla='usuarios' AND b.clave_registro=p.cod_usu)
                OR (b.nombre_tabla IN ('citas','medico_especialidades','horarios','bloqueos_agenda','derivaciones')
                    AND p.cod_med IN (b.datos_nuevos->>'cod_med',b.datos_anteriores->>'cod_med',
                        b.datos_nuevos->>'cod_med_emisor',b.datos_anteriores->>'cod_med_emisor',
                        b.datos_nuevos->>'cod_med_destino',b.datos_anteriores->>'cod_med_destino'))
                OR c.cod_med=p.cod_med
                SQL],
            'secretarias' => ['secretarias', 'cod_sec', <<<'SQL'
                (b.nombre_tabla='secretarias' AND b.clave_registro=p.cod_sec)
                OR (b.nombre_tabla='usuarios' AND b.clave_registro=p.cod_usu)
                OR (b.nombre_tabla='citas' AND (p.cod_sec IN (b.datos_nuevos->>'cod_sec',b.datos_anteriores->>'cod_sec')
                    OR p.cod_usu IN (b.datos_nuevos->>'checkin_por_usu',b.datos_anteriores->>'checkin_por_usu',
                        b.datos_nuevos->>'cancelado_por_usu',b.datos_anteriores->>'cancelado_por_usu')))
                SQL],
        };
    }

    private function present(array $row): array
    {
        $changes = [];
        foreach (self::FIELDS as $field => $label) {
            $before = $row['before'][$field] ?? null;
            $after = $row['after'][$field] ?? null;
            if ($before !== $after) {
                $changes[] = ['field' => $label, 'before' => $this->value($before, $field), 'after' => $this->value($after, $field)];
            }
        }
        unset($row['before'], $row['after']);
        $row['changes'] = $changes;
        $row['entity'] = self::ENTITIES[$row['table']] ?? 'Registro';
        $row['actionLabel'] = match ($row['action']) {
            'INSERT' => 'Registro', 'UPDATE' => 'Actualización', 'DELETE' => 'Eliminación', default => 'Evento',
        };
        $row['actor'] = $row['actor'] ?? 'Sin responsable registrado';

        return $row;
    }

    private function value(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            return 'Sin valor';
        }
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }
        if (! is_scalar($value)) {
            return 'Valor no disponible';
        }
        if (is_string($value) && in_array($field, ['bloqueado_hasta', 'hora_llegada_checkin', 'hora_inicio_atencion', 'fecha_atencion', 'fecha_fin_sancion'], true)) {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('America/La_Paz'))->format('d/m/Y H:i');
        }

        return (string) $value;
    }
}
