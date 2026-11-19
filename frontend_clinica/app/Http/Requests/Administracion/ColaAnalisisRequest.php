<?php

namespace App\Http\Requests\Administracion;

use DateTimeImmutable;
use Illuminate\Validation\Validator;

class ColaAnalisisRequest extends AdminRequest
{
    private const MAX_RANGO_DIAS = 366;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $fechaFinRules = ['bail', 'nullable', 'date_format:Y-m-d'];
        if (is_string($this->input('fecha_inicio')) && $this->input('fecha_inicio') !== '') {
            $fechaFinRules[] = 'after_or_equal:fecha_inicio';
        }

        return [
            'fecha_inicio' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'fecha_fin' => $fechaFinRules,
            'especialidad' => ['bail', 'nullable', 'string', 'regex:/^[A-Za-z0-9]+$/', 'max:20'],
            'medico' => ['bail', 'nullable', 'string', 'regex:/^[A-Za-z0-9]+$/', 'max:20'],
            'sim_lambda' => ['bail', 'nullable', 'numeric', 'min:0', 'max:500'],
            'sim_duracion' => ['bail', 'nullable', 'integer', 'min:5', 'max:180'],
            'sim_servidores' => ['bail', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $inicio = $this->input('fecha_inicio');
                $fin = $this->input('fecha_fin');
                if (! is_string($inicio) || $inicio === '' || ! is_string($fin) || $fin === '') {
                    return;
                }

                $desde = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
                $hasta = DateTimeImmutable::createFromFormat('!Y-m-d', $fin);
                if (! $desde || ! $hasta) {
                    return;
                }

                $dias = (int) $desde->diff($hasta)->days;
                if ($dias > self::MAX_RANGO_DIAS) {
                    $validator->errors()->add(
                        'fecha_fin',
                        'El periodo de análisis no puede superar los '.self::MAX_RANGO_DIAS.' días.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'fecha_inicio' => 'fecha de inicio',
            'fecha_fin' => 'fecha de fin',
            'especialidad' => 'especialidad',
            'medico' => 'médico',
            'sim_lambda' => 'tasa de llegada (λ)',
            'sim_duracion' => 'duración de consulta',
            'sim_servidores' => 'número de médicos activos (c)',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'after_or_equal' => 'El campo :attribute debe ser igual o posterior a :date.',
            'numeric' => 'El campo :attribute debe ser un número válido.',
            'min.numeric' => 'El campo :attribute debe ser al menos :min.',
            'max.numeric' => 'El campo :attribute no puede superar :max.',
            'regex' => 'El formato de :attribute no es válido.',
        ]);
    }
}
