<?php

namespace App\Http\Requests\Administracion;

use App\Services\Administration\BitacoraService;
use DateTimeImmutable;
use Illuminate\Validation\Validator;

class BitacoraRequest extends AdminRequest
{
    protected function getRedirectUrl(): string
    {
        return route('administracion.bitacoras.show', ['seccion' => $this->route('seccion')]);
    }

    public function rules(): array
    {
        $prefix = match ($this->route('seccion')) {
            'pacientes' => 'PAC', 'medicos' => 'MED', 'secretarias' => 'SEC', default => 'INVALID',
        };

        return [
            'desde' => ['bail', 'nullable', 'required_with:hasta', 'date_format:Y-m-d'],
            'hasta' => ['bail', 'nullable', 'required_with:desde', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'relacion' => ['nullable', 'string', 'in:relacionados,realizados'],
            'persona' => ['nullable', 'string', 'max:20', 'regex:/^'.$prefix.'[0-9]+$/D'],
            'accion' => ['nullable', 'string', 'in:INSERT,UPDATE,DELETE'],
            'entidad' => ['nullable', 'string', 'in:'.implode(',', array_keys(BitacoraService::ENTITIES))],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('desde') || ! $this->filled('hasta')) {
                return;
            }
            $from = new DateTimeImmutable($this->string('desde')->toString());
            $to = new DateTimeImmutable($this->string('hasta')->toString());
            if ($from->diff($to)->days > 365) {
                $validator->errors()->add('hasta', 'Selecciona un período de hasta 366 días.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['desde' => 'fecha inicial', 'hasta' => 'fecha final', 'relacion' => 'tipo de actividad',
            'persona' => 'código del perfil', 'accion' => 'acción', 'entidad' => 'registro', 'q' => 'búsqueda', 'page' => 'página'];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'required_with' => 'Completa ambas fechas para consultar el período.',
            'after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
            'regex' => 'Introduce un código correspondiente al apartado seleccionado.',
        ]);
    }
}
