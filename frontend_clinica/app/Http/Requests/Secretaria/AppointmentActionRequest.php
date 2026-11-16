<?php

namespace App\Http\Requests\Secretaria;

class AppointmentActionRequest extends ReceptionRequest
{
    public function rules(): array
    {
        return ['confirmacion' => ['accepted']] + ($this->routeIs('secretaria.citas.cancelar.store') ? ['motivo' => ['required', 'string', 'max:2000']] : []);
    }
}
