<?php

namespace App\Http\Requests\Secretaria;

class CheckinRequest extends ReceptionRequest
{
    public function rules(): array
    {
        return ['tokenQr' => ['required', 'string', 'max:4096']];
    }
}
