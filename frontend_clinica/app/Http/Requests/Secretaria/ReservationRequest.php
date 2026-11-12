<?php

namespace App\Http\Requests\Secretaria;

class ReservationRequest extends ReceptionRequest
{
    public function rules(): array
    {
        return ['codPac' => ['required', ...self::identifier()], 'codSlot' => ['required', ...self::identifier()],
            'codEsp' => ['nullable', ...self::identifier()], 'codMed' => ['nullable', ...self::identifier()],
            'fecha' => ['nullable', 'date_format:Y-m-d'], 'confirmacion' => ['accepted']];
    }
}
