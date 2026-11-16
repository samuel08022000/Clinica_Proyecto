<?php

namespace App\Services\Paciente;

use App\Contracts\PacienteApi;
use App\Exceptions\PacienteApiException;
use Illuminate\Support\Carbon;

/**
 * Implementación SIMULADA. Guarda todo en la sesión del navegador.
 * Sirve para desarrollar y demostrar el módulo sin backend. No usar en producción.
 */
class MockPacienteApi implements PacienteApi
{
    private const ESPECIALIDADES = [
        ['codEsp' => 'ESP001', 'nombre' => 'Medicina General'],
        ['codEsp' => 'ESP002', 'nombre' => 'Pediatría'],
        ['codEsp' => 'ESP003', 'nombre' => 'Cardiología'],
        ['codEsp' => 'ESP004', 'nombre' => 'Dermatología'],
    ];

    private const MEDICOS = [
        ['codMed' => 'MED001', 'nombre' => 'Dra. Lucía Fernández', 'codEsp' => 'ESP001'],
        ['codMed' => 'MED002', 'nombre' => 'Dr. Marcos Salinas', 'codEsp' => 'ESP001'],
        ['codMed' => 'MED003', 'nombre' => 'Dra. Carla Quiroga', 'codEsp' => 'ESP002'],
        ['codMed' => 'MED004', 'nombre' => 'Dr. Andrés Vargas', 'codEsp' => 'ESP003'],
        ['codMed' => 'MED005', 'nombre' => 'Dra. Paola Rojas', 'codEsp' => 'ESP004'],
    ];

    private const BLOQUES = ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
        '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'];

    public function especialidades(): array
    {
        return self::ESPECIALIDADES;
    }

    public function medicos(?string $codEsp = null): array
    {
        $lista = array_filter(self::MEDICOS, fn ($m) => $codEsp === null || $codEsp === '' || $m['codEsp'] === $codEsp);

        return array_values(array_map(fn ($m) => $m + ['nombreEspecialidad' => $this->nombreEspecialidad($m['codEsp'])], $lista));
    }

    public function disponibilidad(string $codEsp, ?string $codMed, string $fecha): array
    {
        try {
            $dia = Carbon::createFromFormat('Y-m-d', $fecha)->startOfDay();
        } catch (\Throwable) {
            throw new PacienteApiException('FECHA_INVALIDA');
        }

        $hoy = now()->startOfDay();
        if ($dia->lt($hoy) || $dia->gt($hoy->copy()->addDays(14)) || $dia->isSunday()) {
            return [];
        }

        $ocupados = collect($this->citasGuardadas())
            ->where('estado', '!=', 'Cancelada')
            ->map(fn ($c) => $c['codMed'].'|'.$c['fecha'].'|'.substr($c['horaInicio'], 0, 5))
            ->all();

        $cupos = [];
        foreach ($this->medicos($codEsp) as $medico) {
            if ($codMed !== null && $codMed !== '' && $medico['codMed'] !== $codMed) {
                continue;
            }
            foreach (self::BLOQUES as $hora) {
                $inicio = Carbon::parse("$fecha $hora");
                $slot = $medico['codMed'].'|'.$fecha.'|'.$hora;
                // Hoy: solo horarios con al menos 1 hora de anticipación.
                if ($inicio->lt(now()->addHour())) {
                    continue;
                }
                // Simula horarios ya tomados por otros pacientes.
                if (crc32($slot) % 4 === 0 || in_array($slot, $ocupados, true)) {
                    continue;
                }
                $cupos[] = [
                    'codSlot' => $slot,
                    'codMed' => $medico['codMed'],
                    'nombreMedico' => $medico['nombre'],
                    'codEsp' => $medico['codEsp'],
                    'nombreEspecialidad' => $medico['nombreEspecialidad'],
                    'fecha' => $fecha,
                    'horaInicio' => $hora,
                    'horaFin' => $inicio->copy()->addMinutes(30)->format('H:i'),
                ];
            }
        }

        return $cupos;
    }

    public function citas(): array
    {
        return $this->citasGuardadas();
    }

    public function crearCita(string $codSlot): array
    {
        $partes = explode('|', $codSlot);
        if (count($partes) !== 3) {
            throw new PacienteApiException('SLOT_NO_DISPONIBLE');
        }
        [$codMed, $fecha, $hora] = $partes;
        $medico = collect(self::MEDICOS)->firstWhere('codMed', $codMed);
        if ($medico === null) {
            throw new PacienteApiException('SLOT_NO_DISPONIBLE');
        }

        $cupo = collect($this->disponibilidad($medico['codEsp'], $codMed, $fecha))->firstWhere('codSlot', $codSlot);
        if ($cupo === null) {
            throw new PacienteApiException('SLOT_NO_DISPONIBLE');
        }

        $citas = $this->citasGuardadas();
        $mismaHora = collect($citas)->contains(fn ($c) => $c['estado'] !== 'Cancelada'
            && $c['fecha'] === $fecha && substr($c['horaInicio'], 0, 5) === $hora);
        if ($mismaHora) {
            throw new PacienteApiException('CITA_DUPLICADA');
        }

        $cita = [
            'codCit' => 'CIT'.str_pad((string) (count($citas) + 1), 4, '0', STR_PAD_LEFT),
            'codMed' => $cupo['codMed'],
            'nombreMedico' => $cupo['nombreMedico'],
            'codEsp' => $cupo['codEsp'],
            'nombreEspecialidad' => $cupo['nombreEspecialidad'],
            'fecha' => $fecha,
            'horaInicio' => $cupo['horaInicio'],
            'horaFin' => $cupo['horaFin'],
            'estado' => 'Confirmada',
        ];
        $citas[] = $cita;
        session(['mock_citas' => $citas]);

        return $cita;
    }

    public function cancelarCita(string $codCit): void
    {
        $citas = $this->citasGuardadas();
        foreach ($citas as $i => $cita) {
            if ($cita['codCit'] !== $codCit) {
                continue;
            }
            if (! in_array($cita['estado'], ['Confirmada', 'Afectada/Pendiente Reprogramación'], true)) {
                throw new PacienteApiException('CITA_NO_CANCELABLE');
            }
            if (Carbon::parse($cita['fecha'].' '.$cita['horaInicio'])->lt(now()->addDay())) {
                throw new PacienteApiException('CANCELACION_FUERA_DE_PLAZO');
            }
            $citas[$i]['estado'] = 'Cancelada';
            session(['mock_citas' => $citas]);

            return;
        }
        throw new PacienteApiException('CITA_NO_ENCONTRADA');
    }

    public function perfil(): array
    {
        return session('mock_perfil', [
            'nombre' => (string) session('nombre_usuario', 'Paciente'),
            'email' => (string) session('email_usuario', ''),
            'ci' => '1234567',
            'fechaNacimiento' => '1998-05-14',
            'telefono' => '+591 70000000',
            'ciudadResidencia' => 'La Paz',
        ]);
    }

    public function actualizarPerfil(array $datos): array
    {
        $perfil = array_merge($this->perfil(), array_intersect_key($datos, array_flip(['telefono', 'ciudadResidencia'])));
        session(['mock_perfil' => $perfil]);

        return $perfil;
    }

    private function nombreEspecialidad(string $codEsp): string
    {
        return (string) collect(self::ESPECIALIDADES)->firstWhere('codEsp', $codEsp)['nombre'];
    }

    /** Citas de ejemplo la primera vez, para que el panel no se vea vacío. */
    private function citasGuardadas(): array
    {
        if (! session()->has('mock_citas')) {
            $futura = now()->addDays(3);
            while ($futura->isSunday()) {
                $futura->addDay();
            }
            session(['mock_citas' => [
                [
                    'codCit' => 'CIT0001', 'codMed' => 'MED001', 'nombreMedico' => 'Dra. Lucía Fernández',
                    'codEsp' => 'ESP001', 'nombreEspecialidad' => 'Medicina General',
                    'fecha' => $futura->format('Y-m-d'), 'horaInicio' => '09:00', 'horaFin' => '09:30',
                    'estado' => 'Confirmada',
                ],
                [
                    'codCit' => 'CIT0002', 'codMed' => 'MED004', 'nombreMedico' => 'Dr. Andrés Vargas',
                    'codEsp' => 'ESP003', 'nombreEspecialidad' => 'Cardiología',
                    'fecha' => now()->subDays(20)->format('Y-m-d'), 'horaInicio' => '10:30', 'horaFin' => '11:00',
                    'estado' => 'Atendida',
                ],
                [
                    'codCit' => 'CIT0003', 'codMed' => 'MED005', 'nombreMedico' => 'Dra. Paola Rojas',
                    'codEsp' => 'ESP004', 'nombreEspecialidad' => 'Dermatología',
                    'fecha' => now()->subDays(8)->format('Y-m-d'), 'horaInicio' => '15:00', 'horaFin' => '15:30',
                    'estado' => 'Cancelada',
                ],
            ]]);
        }

        return session('mock_citas');
    }
}
