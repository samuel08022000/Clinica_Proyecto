<?php

namespace App\Http\Requests\Administracion;

class EspecialidadRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'nombreEspecialidad' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:10000'],
        ] + ($this->isMethod('POST') ? ['duracionConsultaMin' => ['required', 'integer', 'in:20,30,45,60']] : []);
    }
}
