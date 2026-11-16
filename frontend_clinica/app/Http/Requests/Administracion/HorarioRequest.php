<?php

namespace App\Http\Requests\Administracion;

class HorarioRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'diaSemana' => ['required', 'integer', 'between:1,7'],
            'horaInicio' => ['required', 'date_format:H:i'],
            'horaFin' => ['required', 'date_format:H:i', 'after:horaInicio'],
            'pausaInicio' => ['nullable', 'required_with:pausaFin', 'date_format:H:i', 'after_or_equal:horaInicio', 'before:horaFin'],
            'pausaFin' => ['nullable', 'required_with:pausaInicio', 'date_format:H:i', 'after:pausaInicio', 'before_or_equal:horaFin'],
        ];
    }
}
