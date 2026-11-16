<?php

namespace App\Http\Requests\Administracion;

class EstadoRequest extends AdminRequest
{
    public function rules(): array
    {
        return ['estado' => ['required', 'in:Activo,Inactivo'], 'confirmacion' => ['accepted']];
    }
}
