<?php

namespace App\Http\Requests\Administracion;

use Illuminate\Foundation\Http\FormRequest;

abstract class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->get('cod_rol') === 'ROL004';
    }

    public function attributes(): array
    {
        return [
            'nombreCompleto' => 'nombre completo', 'email' => 'correo', 'ci' => 'CI',
            'password' => 'contraseña', 'telefono' => 'teléfono', 'matriculaProfesional' => 'matrícula profesional',
            'codigosEspecialidades' => 'especialidades', 'nombreEspecialidad' => 'nombre de especialidad',
            'duracionConsultaMin' => 'duración de consulta', 'diaSemana' => 'día de la semana',
            'horaInicio' => 'hora de inicio', 'horaFin' => 'hora de fin',
            'pausaInicio' => 'inicio de descanso', 'pausaFin' => 'fin de descanso',
            'fechaBloqueo' => 'fecha del bloqueo', 'motivo' => 'motivo', 'dias' => 'días',
            'confirmacion' => 'confirmación',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'required_with' => 'Completa :attribute y su pareja de horario.',
            'email' => 'Introduce un correo válido.', 'in' => 'Selecciona un valor permitido para :attribute.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'between' => 'El campo :attribute debe estar entre :min y :max.',
            'min' => 'El campo :attribute requiere al menos :min caracteres o elementos.',
            'max' => 'El campo :attribute supera el máximo permitido (:max).',
            'after' => 'El campo :attribute debe ser posterior a :date.',
            'before' => 'El campo :attribute debe ser anterior a :date.',
            'after_or_equal' => 'El campo :attribute debe estar dentro del horario de atención.',
            'before_or_equal' => 'El campo :attribute debe estar dentro del horario de atención.',
            'date_format' => 'Introduce :attribute con el formato correcto.',
            'accepted' => 'Confirma la operación antes de continuar.',
            'confirmed' => 'Las contraseñas no coinciden.',
            'array' => 'Selecciona las especialidades de la lista.',
            'string' => 'El campo :attribute debe contener texto.',
            'distinct' => 'No repitas la misma especialidad.',
        ];
    }

    protected function passwordRules(bool $required, bool $strong = false): array
    {
        $rules = [$required ? 'required' : 'nullable', 'string', 'min:'.($strong ? '10' : '8'), 'confirmed',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('La contraseña no puede superar 72 bytes.');
                }
            }];
        if ($strong) {
            $rules[] = function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (! preg_match('/[A-Z]/', $value) || ! preg_match('/[a-z]/', $value) || ! preg_match('/[0-9]/', $value))) {
                    $fail('Incluye mayúsculas, minúsculas y números en la contraseña.');
                }
            };
        }

        return $rules;
    }
}
