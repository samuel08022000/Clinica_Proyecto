<?php

namespace App\Http\Requests\Paciente;

final class CancelAppointmentRequest extends PacienteRequest
{
    public function rules(): array
    {
        return [
            'motivo' => ['bail', 'required', 'string', 'max:2000', 'regex:/\S/'],
            'confirmacion' => ['accepted'],
        ];
    }

    protected function errorFormContext(): array
    {
        return ['target' => 'paciente_cancel'];
    }
}
