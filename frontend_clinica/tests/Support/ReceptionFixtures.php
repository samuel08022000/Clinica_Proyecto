<?php

namespace Tests\Support;

use App\Support\AppointmentPresentation;

/** Synthetic records for isolated tests only, never a production fallback. */
final class ReceptionFixtures
{
    public static function authorization(string $role = 'ROL002'): array
    {
        return ['data' => ['user' => ['id' => 'USU000002', 'name' => 'Ángela Secretaria', 'email' => 'angela@example.test',
            'role' => ['code' => $role, 'name' => 'Secretario', 'slug' => 'secretaria'], 'accountStatus' => 'Activo'], 'expiresAt' => '2026-11-13T00:00:00Z']];
    }

    public static function patient(array $overrides = []): array
    {
        return array_replace(['codPac' => 'PAC000001', 'nombreCompleto' => 'Elena Vargas', 'ci' => '1234567', 'email' => 'elena@example.test', 'telefono' => '70000000', 'ciudadResidencia' => 'La Paz', 'estadoCuenta' => 'Activo', 'estadoUsuario' => 'Activo', 'emailVerificado' => true, 'bloqueadoHasta' => null, 'motivoInhabilitacion' => null, 'habilitadoParaReservar' => true], $overrides);
    }

    public static function appointment(array $overrides = []): array
    {
        return array_replace(['codCit' => 'CIT00000001', 'codSlot' => 'SLT00000001', 'codPac' => 'PAC000001', 'nombrePaciente' => 'Elena Vargas', 'codMed' => 'MED000001', 'nombreMedico' => 'Lucía Flores', 'codEsp' => 'ESP001', 'nombreEspecialidad' => 'Medicina interna', 'fecha' => '2026-11-12', 'horaInicio' => '09:00:00', 'horaFin' => '09:30:00', 'estado' => 'Confirmada', 'canalReserva' => 'Ventanilla', 'codSec' => 'SEC000001', 'qrDisponible' => true], $overrides);
    }

    public static function attendance(array $overrides = []): array
    {
        return array_replace(['codCit' => 'CIT00000001', 'nombrePaciente' => 'Elena Vargas', 'fecha' => '2026-11-12', 'estado' => 'Confirmada', 'horaLlegadaCheckin' => null, 'checkinPorUsu' => null, 'checkinRegistrado' => false], $overrides);
    }

    public static function slots(): array
    {
        return [array_intersect_key(self::appointment(['horaInicio' => '16:00:00', 'horaFin' => '16:30:00']), array_flip(['codSlot', 'codMed', 'nombreMedico', 'codEsp', 'nombreEspecialidad', 'fecha', 'horaInicio', 'horaFin']))];
    }

    public static function page(array $overrides = []): array
    {
        $rows = [];
        foreach (array_slice(AppointmentPresentation::STATES, 0, 3) as $i => $state) {
            $rows[] = array_diff_key(self::appointment(['codCit' => 'CIT0000000'.($i + 1), 'horaInicio' => sprintf('%02d:00:00', 9 + $i), 'horaFin' => sprintf('%02d:30:00', 9 + $i), 'estado' => $state]), array_flip(['codSlot', 'codSec', 'qrDisponible']))
                + ['motivoCancelacion' => null, 'canceladoPorUsu' => null, 'canceladoEn' => null, 'horaLlegadaCheckin' => $state === 'Confirmada' ? null : '2026-11-12T09:50:00-04:00', 'checkinPorUsu' => $state === 'Confirmada' ? null : 'USU000002', 'fechaAtencion' => null, 'codIncInasistencia' => null, 'inasistenciaRegistradaEn' => null, 'inasistenciaPorUsu' => null, 'motivoInasistencia' => null];
        }

        return array_replace(['citas' => $rows, 'page' => 0, 'size' => 20, 'hayMas' => false], $overrides);
    }

    public static function get(string $path): array
    {
        return match ($path) {
            '/secretaria/pacientes' => ['pacientes' => [array_intersect_key(self::patient(), array_flip(['codPac', 'nombreCompleto', 'ci', 'email', 'estadoCuenta']))]],
            '/secretaria/pacientes/PAC000001' => self::patient(),
            '/secretaria/especialidades' => [['codEsp' => 'ESP001', 'nombreEspecialidad' => 'Medicina interna', 'duracionConsultaMin' => 30]],
            '/secretaria/medicos' => [['codMed' => 'MED000001', 'nombreCompleto' => 'Lucía Flores', 'duracionConsultaMin' => 30]],
            '/secretaria/disponibilidad' => self::slots(),
            '/secretaria/citas', '/secretaria/pacientes/PAC000001/historial' => self::page(),
            '/secretaria/citas/CIT00000001' => self::appointment(),
            '/secretaria/citas/CIT00000001/asistencia' => self::attendance(),
            '/secretaria/citas/CIT00000001/acreditacion' => ['codCit' => 'CIT00000001', 'nombrePaciente' => 'Elena Vargas', 'fecha' => '2026-11-12', 'estado' => 'Confirmada', 'qrValido' => true, 'admiteCheckin' => true],
            default => throw new \LogicException('Unexpected test endpoint '.$path),
        };
    }
}
