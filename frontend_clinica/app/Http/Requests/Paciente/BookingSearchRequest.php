<?php

namespace App\Http\Requests\Paciente;

final class BookingSearchRequest extends PacienteRequest
{
    public function rules(): array
    {
        return [
            'codEsp' => ['nullable', ...self::identifier()],
            'codMed' => ['nullable', ...self::identifier()],
            'fecha' => ['bail', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
