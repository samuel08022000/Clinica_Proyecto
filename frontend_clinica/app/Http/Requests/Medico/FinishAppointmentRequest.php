<?php

namespace App\Http\Requests\Medico;

final class FinishAppointmentRequest extends MedicoRequest
{
    public function rules(): array
    {
        return ['confirmacion' => ['required', 'accepted']];
    }
}
