<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Display affordances only. Every transition is still authorized by Laravel services and PostgreSQL. */
final class AppointmentPresentation
{
    public const STATES = ['Confirmada', 'En Espera', 'En Atención', 'Atendida', 'Cancelada', 'No Asistió', 'Afectada/Pendiente Reprogramación', 'Reprogramada'];

    public static function operationalState(array $appointment): string
    {
        return $appointment['estado'] === 'En Espera' && filled($appointment['horaInicioAtencion'] ?? null)
            ? 'En Atención' : $appointment['estado'];
    }

    public static function canCancel(array $appointment, array $attendance): bool
    {
        return in_array($appointment['estado'], ['Confirmada', 'Afectada/Pendiente Reprogramación'], true)
            && ! ($attendance['checkinRegistrado'] ?? filled($attendance['horaLlegadaCheckin'] ?? null));
    }

    public static function canMarkAbsence(array $appointment, array $attendance): bool
    {
        return $appointment['estado'] === 'Confirmada'
            && self::canCancel($appointment, $attendance)
            && CarbonImmutable::parse($appointment['fecha'].' '.$appointment['horaFin'], 'America/La_Paz')->isPast();
    }
}
