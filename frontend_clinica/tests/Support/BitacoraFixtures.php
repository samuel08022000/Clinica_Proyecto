<?php

namespace Tests\Support;

final class BitacoraFixtures
{
    public static function listing(string $section = 'pacientes', int $count = 3): array
    {
        [$code, $name] = match ($section) {
            'pacientes' => ['PAC000001', 'Elena Vargas'], 'medicos' => ['MED000001', 'Lucía Flores'],
            'secretarias' => ['SEC000001', 'Daniel Rojas'],
        };
        $records = [];
        for ($index = 0; $index < $count; $index++) {
            $records[] = [
                'id' => 'BIT'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT),
                'date' => '20/11/2026 14:30:00', 'actorCode' => $index === 1 ? null : 'USU000004',
                'actor' => $index === 1 ? 'Sin responsable registrado' : 'María Administradora',
                'people' => [['code' => $code, 'name' => $name]],
                'action' => 'UPDATE', 'actionLabel' => 'Actualización', 'table' => 'citas', 'entity' => 'Cita',
                'reference' => 'CIT'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT),
                'changes' => [['field' => 'Estado', 'before' => 'Confirmada', 'after' => 'En Espera'],
                    ['field' => 'Llegada registrada', 'before' => 'Sin valor', 'after' => '20/11/2026 14:30']],
            ];
        }

        return [
            'section' => $section, 'title' => ['pacientes' => 'Pacientes', 'medicos' => 'Médicos', 'secretarias' => 'Secretarios'][$section],
            'filters' => ['desde' => '2026-11-01', 'hasta' => '2026-11-20', 'relacion' => 'relacionados'],
            'records' => $records, 'total' => $count, 'page' => 0, 'hayMas' => false, 'generatedAt' => '20/11/2026 16:30:00',
        ];
    }
}
