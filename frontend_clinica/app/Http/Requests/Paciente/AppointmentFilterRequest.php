<?php

namespace App\Http\Requests\Paciente;

final class AppointmentFilterRequest extends PacienteRequest
{
    public function rules(): array
    {
        return [
            'alcance' => ['nullable', 'string', 'in:proximas,pasadas,todas'],
            'estado' => [
                'nullable',
                'string',
                'in:Confirmada,En Espera,Atendida,Cancelada,No Asistió,Afectada/Pendiente Reprogramación,Reprogramada',
            ],
            'desde' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['bail', 'nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'page' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
