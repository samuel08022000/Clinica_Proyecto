<?php

namespace Tests\Support;

/** Datos sintéticos exclusivos de pruebas y capturas, nunca del panel real. */
final class DashboardFixtures
{
    public static function snapshot(): array
    {
        $daily = [];
        $values = [4, 5, 3, 7, 6, 8, 3];
        for ($day = 1; $day <= 20; $day++) {
            $daily[] = [
                'date' => sprintf('2026-11-%02d', $day), 'label' => sprintf('%02d/11', $day),
                'value' => $day < 14 ? 0 : $values[$day - 14],
            ];
        }

        return [
            'period' => ['preset' => 'mes', 'from' => '2026-11-01', 'to' => '2026-11-20', 'today' => '20/11/2026', 'label' => '01/11/2026 – 20/11/2026'],
            'updatedAt' => '20/11/2026 16:30',
            'totals' => ['doctors' => 12, 'secretaries' => 4, 'patients' => 328, 'specialties' => 9,
                'newPatients' => 14, 'appointments' => 68, 'attendedPatients' => 30,
                'todayAppointments' => 11, 'todayAttended' => 3, 'todayWaiting' => 2],
            'daily' => $daily,
            'specialties' => [
                ['label' => 'Medicina general', 'value' => 24], ['label' => 'Pediatría', 'value' => 16],
                ['label' => 'Cardiología', 'value' => 10], ['label' => 'Ginecología', 'value' => 8],
                ['label' => 'Dermatología', 'value' => 5], ['label' => 'Traumatología', 'value' => 4],
            ],
            'states' => [
                ['label' => 'Confirmadas', 'value' => 12], ['label' => 'En espera', 'value' => 7],
                ['label' => 'Atendidas', 'value' => 38], ['label' => 'Canceladas', 'value' => 5],
                ['label' => 'No asistió', 'value' => 3], ['label' => 'Pendientes de reprogramar', 'value' => 2],
                ['label' => 'Reprogramadas', 'value' => 1],
            ],
            'channels' => [
                ['label' => 'Web', 'value' => 40], ['label' => 'Ventanilla', 'value' => 20],
                ['label' => 'Derivación', 'value' => 6], ['label' => 'Administración', 'value' => 2],
            ],
            'referrals' => [
                ['label' => 'Pendientes', 'value' => 5], ['label' => 'Agendadas', 'value' => 3],
                ['label' => 'Completadas', 'value' => 2], ['label' => 'Canceladas', 'value' => 1],
            ],
        ];
    }

    public static function empty(): array
    {
        $data = self::snapshot();
        $data['totals'] = array_fill_keys(array_keys($data['totals']), 0);
        $data['specialties'] = [];
        foreach (['daily', 'states', 'channels', 'referrals'] as $key) {
            $data[$key] = array_map(fn (array $row): array => array_replace($row, ['value' => 0]), $data[$key]);
        }

        return $data;
    }
}
