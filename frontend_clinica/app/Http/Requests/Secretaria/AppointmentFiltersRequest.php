<?php

namespace App\Http\Requests\Secretaria;

use App\Support\AppointmentPresentation;
use Illuminate\Validation\Rule;

class AppointmentFiltersRequest extends ReceptionRequest
{
    public function rules(): array
    {
        $rules = [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('desde'), 'after_or_equal:desde')],
            'estado' => ['nullable', Rule::in(AppointmentPresentation::STATES)],
            'page' => ['nullable', 'integer', 'between:0,10000'],
            'size' => ['nullable', 'integer', 'between:1,50'],
        ];
        if (! $this->routeIs('secretaria.historial.show')) {
            $rules += ['fecha' => ['nullable', 'date_format:Y-m-d', Rule::prohibitedIf($this->filled('desde') || $this->filled('hasta'))],
                'codPac' => ['nullable', ...self::identifier()], 'codMed' => ['nullable', ...self::identifier()]];
        }

        return $rules;
    }

    public function filters(): array
    {
        return array_replace(array_filter($this->validated(), fn ($value) => $value !== null && $value !== ''),
            ['page' => (int) ($this->input('page') ?? 0), 'size' => (int) ($this->input('size') ?? 20)]);
    }
}
