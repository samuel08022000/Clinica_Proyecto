<?php

namespace App\Http\Requests\Administracion;

class BloqueoRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'fechaBloqueo' => ['required', 'date_format:Y-m-d'],
            'horaInicio' => ['nullable', 'required_with:horaFin', 'date_format:H:i'],
            'horaFin' => ['nullable', 'required_with:horaInicio', 'date_format:H:i', 'after:horaInicio'],
            'motivo' => ['required', 'string', 'max:2000'], 'confirmacion' => ['accepted'],
        ];
    }
}
