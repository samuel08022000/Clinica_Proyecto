<?php

namespace App\Http\Requests\Administracion;

use DateTimeImmutable;
use Illuminate\Validation\Validator;

class DashboardRequest extends ColaAnalisisRequest
{
    protected $redirectRoute = 'administracion.home';

    public function rules(): array
    {
        return array_intersect_key(parent::rules(), array_flip(['especialidad', 'medico'])) + [
            'vista' => ['sometimes', 'required', 'string', 'in:hoy,analisis,gestion'],
            'periodo' => ['sometimes', 'required', 'string', 'in:hoy,semana,mes,personalizado'],
            'desde' => ['bail', 'exclude_unless:periodo,personalizado', 'required', 'date_format:Y-m-d'],
            'hasta' => ['bail', 'exclude_unless:periodo,personalizado', 'required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('periodo') !== 'personalizado') {
                return;
            }

            $from = new DateTimeImmutable($this->string('desde')->toString());
            $to = new DateTimeImmutable($this->string('hasta')->toString());
            if ($from->diff($to)->days > 365) {
                $validator->errors()->add('hasta', 'Selecciona un período de hasta 366 días.');
            }
        }];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'required' => 'Completa :attribute para consultar el período.',
            'after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
        ]);
    }

    public function attributes(): array
    {
        return ['periodo' => 'período', 'desde' => 'fecha inicial', 'hasta' => 'fecha final'] + parent::attributes();
    }
}
