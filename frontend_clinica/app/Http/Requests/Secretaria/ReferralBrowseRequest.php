<?php

namespace App\Http\Requests\Secretaria;

use Illuminate\Validation\Rule;

final class ReferralBrowseRequest extends ReceptionRequest
{
    public function rules(): array
    {
        return [
            'estado' => ['nullable', Rule::in(['Pendiente', 'Agendada', 'Completada', 'Cancelada'])],
            'page' => ['nullable', 'integer', 'between:0,10000'],
            'size' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    public function filters(): array
    {
        return [
            'estado' => $this->input('estado', 'Pendiente'),
            'page' => (int) $this->input('page', 0),
            'size' => (int) $this->input('size', 20),
        ];
    }
}
