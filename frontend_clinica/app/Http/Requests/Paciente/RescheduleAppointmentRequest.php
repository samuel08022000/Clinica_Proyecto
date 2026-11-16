<?php

namespace App\Http\Requests\Paciente;

final class RescheduleAppointmentRequest extends PacienteRequest
{
    public function rules(): array
    {
        return [
            'codSlotNuevo' => ['required', ...self::identifier()],
            'motivo' => ['bail', 'required', 'string', 'max:2000', 'regex:/\S/'],
            'confirmacion' => ['accepted'],
        ];
    }

    protected function errorFormContext(): array
    {
        return ['target' => 'paciente_reschedule'];
    }
}
