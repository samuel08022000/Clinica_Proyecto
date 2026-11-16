<?php

namespace App\Http\Requests\Secretaria;

class ReservationBrowseRequest extends PatientSearchRequest
{
    public function rules(): array
    {
        return parent::rules() + ['codPac' => ['nullable', ...self::identifier()], 'codEsp' => ['nullable', ...self::identifier()],
            'codMed' => ['nullable', ...self::identifier()], 'codSlot' => ['nullable', ...self::identifier()], 'fecha' => ['nullable', 'date_format:Y-m-d']];
    }
}
