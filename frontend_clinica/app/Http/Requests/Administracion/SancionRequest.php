<?php

namespace App\Http\Requests\Administracion;

class SancionRequest extends AdminRequest
{
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'max:2000'], 'dias' => ['required', 'integer', 'between:1,365'], 'confirmacion' => ['accepted']];
    }
}
