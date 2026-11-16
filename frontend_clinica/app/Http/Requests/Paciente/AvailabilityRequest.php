<?php

namespace App\Http\Requests\Paciente;

final class AvailabilityRequest extends PacienteRequest
{
    public function rules(): array
    {
        return [
            'codEsp' => ['required', ...self::identifier()],
            'fecha' => ['bail', 'required', 'date_format:Y-m-d'],
            'codMed' => ['nullable', ...self::identifier()],
        ];
    }
}
