<?php

namespace App\Http\Requests\Medico;

final class ReferralRequest extends MedicoRequest
{
    public function rules(): array
    {
        return [
            'codEspDestino' => ['required', ...self::identifier()],
            'codMedDestino' => ['nullable', ...self::identifier()],
            'motivo' => ['required', 'string', 'max:2000'],
            'confirmacion' => ['required', 'accepted'],
        ];
    }
}
