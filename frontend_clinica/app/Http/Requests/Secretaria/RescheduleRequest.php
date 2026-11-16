<?php

namespace App\Http\Requests\Secretaria;

class RescheduleRequest extends ReceptionRequest
{
    public function rules(): array
    {
        return [
            'codSlot' => ['required', ...self::identifier()],
            'motivo' => ['required', 'string', 'max:2000'],
            'confirmacion' => ['accepted'],
        ];
    }
}
