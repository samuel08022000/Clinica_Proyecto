<?php

namespace App\Http\Requests\Secretaria;

class ReservationRequest extends ReceptionRequest
{
    public function rules(): array
    {
        return ['codPac' => ['nullable', 'required_without:codDer', ...self::identifier()], 'codSlot' => ['required', ...self::identifier()],
            'codEsp' => ['nullable', ...self::identifier()], 'codMed' => ['nullable', ...self::identifier()],
            'codDer' => ['nullable', ...self::identifier()],
            'fecha' => ['nullable', 'date_format:Y-m-d'], 'confirmacion' => ['accepted']];
    }
}
