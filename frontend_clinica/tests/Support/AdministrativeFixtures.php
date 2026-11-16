<?php

namespace Tests\Support;

/** Synthetic contract fixtures. Never used by application controllers or views. */
final class AdministrativeFixtures
{
    public static function doctor(): array
    {
        return [
            'codMed' => 'MED000001', 'codUsu' => 'USU000003', 'nombreCompleto' => 'Lucía Flores',
            'email' => 'lucia@example.test', 'ci' => '1234567', 'matriculaProfesional' => 'MP-1024',
            'resumenCurricular' => 'Especialista en medicina interna.', 'duracionConsultaMin' => 30, 'estado' => 'Activo',
            'especialidades' => [self::specialty()],
        ];
    }

    public static function secretary(): array
    {
        return ['codSec' => 'SEC000001', 'codUsu' => 'USU000002', 'nombreCompleto' => 'Daniel Rojas',
            'email' => 'daniel@example.test', 'ci' => '7654321', 'telefono' => '70000000', 'estado' => 'Activo'];
    }

    public static function specialty(): array
    {
        return ['codEsp' => 'ESP001', 'nombreEspecialidad' => 'Medicina interna', 'descripcion' => 'Atención integral de adultos.',
            'duracionConsultaMin' => 30, 'estado' => 'Activo'];
    }

    public static function schedule(): array
    {
        return ['codHor' => 'HOR000001', 'codMed' => 'MED000001', 'diaSemana' => 1, 'horaInicio' => '08:00:00',
            'horaFin' => '12:00:00', 'pausaInicio' => '10:00:00', 'pausaFin' => '10:30:00', 'estado' => 'Activo'];
    }

    public static function block(): array
    {
        return ['codBlo' => 'BLO000001', 'codMed' => 'MED000001', 'codUsuAdmin' => 'USU000004',
            'fechaBloqueo' => '2026-12-01', 'horaInicio' => null, 'horaFin' => null, 'motivo' => 'Capacitación médica',
            'creadoEn' => '2026-11-12T10:00:00-04:00', 'cantidadCitasAfectadas' => 1, 'trazabilidadCompleta' => true];
    }

    public static function affected(): array
    {
        return ['codBlo' => 'BLO000001', 'trazabilidadCompleta' => true, 'citas' => [[
            'codCit' => 'CIT00000001', 'codPac' => 'PAC000001', 'codMed' => 'MED000001', 'codEsp' => 'ESP001',
            'fecha' => '2026-12-01', 'horaInicio' => '08:00:00', 'horaFin' => '08:30:00', 'estado' => 'Pendiente Reprogramacion',
        ]]];
    }

    public static function patient(): array
    {
        return ['codPac' => 'PAC000001', 'nombreCompleto' => 'Elena Vargas', 'ci' => '4567890',
            'email' => 'elena@example.test', 'telefono' => '71111111', 'ciudadResidencia' => 'La Paz',
            'estadoCuenta' => 'Activo', 'estadoUsuario' => 'Activo', 'emailVerificado' => true,
            'bloqueadoHasta' => null, 'motivoInhabilitacion' => null, 'habilitadoParaReservar' => true];
    }

    public static function sanction(): array
    {
        return ['codInc' => 'INC00000001', 'codPac' => 'PAC000001', 'codCit' => null, 'tipoIncidencia' => 'SANCION_BLOQUEO',
            'motivo' => 'Inasistencias reiteradas', 'registradoPorUsu' => 'USU000004', 'fechaRegistro' => '2026-10-01T10:00:00-04:00',
            'fechaFinSancion' => '2026-10-08T10:00:00-04:00', 'vigente' => false];
    }

    public static function authorization(string $role = 'ROL004'): array
    {
        return ['data' => ['user' => ['id' => 'USU000004', 'name' => 'María Administradora', 'email' => 'maria@example.test',
            'role' => ['code' => $role, 'name' => 'Administrador', 'slug' => 'administracion'], 'accountStatus' => 'Activo'],
            'expiresAt' => '2030-01-01T00:00:00Z']];
    }

    public static function page(string $key, array $rows): array
    {
        return [$key => $rows, 'page' => 0, 'size' => 20, 'hayMas' => false];
    }

    public static function get(string $path): array
    {
        return match ($path) {
            '/personal/medicos' => [self::doctor()], '/personal/medicos/MED000001' => self::doctor(),
            '/personal/secretarias' => [self::secretary()], '/personal/secretarias/SEC000001' => self::secretary(),
            '/personal/especialidades' => [self::specialty()], '/personal/especialidades/ESP001' => self::specialty(),
            '/personal/especialidades/ESP001/medicos' => [['codMed' => 'MED000001', 'nombreCompleto' => 'Lucía Flores',
                'estadoMedico' => 'Activo', 'estadoVinculo' => 'Activo', 'duracionConsultaMin' => 30]],
            '/administracion/medicos/MED000001/horarios' => [self::schedule()],
            '/administracion/medicos/MED000001/bloqueos' => [self::block()],
            '/administracion/bloqueos/BLO000001' => self::block(), '/administracion/bloqueos/BLO000001/citas-afectadas' => self::affected(),
            '/administracion/pacientes' => self::page('pacientes', [self::patient()]),
            '/administracion/pacientes/PAC000001' => self::patient(),
            '/administracion/pacientes/PAC000001/incidencias', '/administracion/pacientes/PAC000001/sanciones' => self::page('incidencias', [self::sanction()]),
            '/administracion/pacientes/PAC000001/sanciones/INC00000001' => self::sanction(),
            default => throw new \LogicException('Contrato de prueba no definido: '.$path),
        };
    }
}
