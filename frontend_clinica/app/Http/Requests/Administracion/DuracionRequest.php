<?php

namespace App\Http\Requests\Administracion;

class DuracionRequest extends AdminRequest
{
    public function rules(): array
    {
        return ['duracionConsultaMin' => ['required', 'integer', 'in:20,30,45,60'], 'confirmacion' => ['accepted']];
    }
}
