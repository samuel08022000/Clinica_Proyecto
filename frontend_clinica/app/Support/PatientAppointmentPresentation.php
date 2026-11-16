<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class PatientAppointmentPresentation
{
    /** @return array<string, mixed> */
    public static function decorate(array $appointment): array
    {
        $start = CarbonImmutable::parse(
            $appointment['fecha'].' '.$appointment['horaInicio'],
            'America/La_Paz',
        )->locale('es');
        $end = CarbonImmutable::parse(
            $appointment['fecha'].' '.$appointment['horaFin'],
            'America/La_Paz',
        );
        $state = (string) $appointment['estado'];
        $expired = (bool) ($appointment['esVencida'] ?? false);

        return $appointment + [
            'inicio' => $start,
            'fechaTexto' => ucfirst($start->isoFormat('dddd D [de] MMMM')),
            'horaTexto' => $start->format('H:i').' – '.$end->format('H:i'),
            'esProxima' => in_array($state, ['Confirmada', 'En Espera'], true) && ! $expired,
            'cancelable' => (bool) ($appointment['puedeCancelar'] ?? ($state === 'Confirmada' && ! $expired)),
            'reprogramable' => (bool) ($appointment['puedeReprogramar'] ?? ($state === 'Confirmada' && ! $expired)),
            'requiereReprogramacion' => $state === 'Afectada/Pendiente Reprogramación',
            'estadoTexto' => self::stateText($state),
            'estadoClase' => self::stateClass($state),
        ];
    }

    private static function stateText(string $state): string
    {
        return match ($state) {
            'En Espera' => 'En espera',
            'No Asistió' => 'No asistió',
            'Afectada/Pendiente Reprogramación' => 'Requiere reprogramación',
            default => $state,
        };
    }

    private static function stateClass(string $state): string
    {
        return match ($state) {
            'Confirmada' => 'confirmada',
            'En Espera' => 'espera',
            'Atendida' => 'atendida',
            'Cancelada' => 'cancelada',
            'No Asistió' => 'noasistio',
            'Afectada/Pendiente Reprogramación' => 'afectada',
            'Reprogramada' => 'reprogramada',
            default => 'atendida',
        };
    }
}
