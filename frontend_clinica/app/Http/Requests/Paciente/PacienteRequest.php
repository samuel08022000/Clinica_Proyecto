<?php

namespace App\Http\Requests\Paciente;

use App\Http\Middleware\PatientResponses;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class PacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->get('cod_rol') === 'ROL001';
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(PatientResponses::privateResponse(
                response()->json([
                    'error' => 'VALIDACION',
                    'message' => 'Revisa los datos enviados.',
                    'errors' => $validator->errors()->toArray(),
                ], 422),
            ));
        }

        $response = back()->withErrors($validator)->withInput(PatientResponses::safeInput($this));
        $context = $this->errorFormContext();
        if (isset($context['target'])) {
            $response->with('paciente_form_target', $context['target']);
        }
        if (isset($context['fieldIds'])) {
            $response->with('error_field_ids', $context['fieldIds']);
        }

        throw new HttpResponseException(PatientResponses::privateResponse($response));
    }

    /**
     * @return array{target?: string, fieldIds?: array<string, string>}
     */
    protected function errorFormContext(): array
    {
        return [];
    }

    protected static function identifier(): array
    {
        return ['bail', 'string', 'max:64', 'regex:/\A[A-Za-z0-9]+\z/'];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'accepted' => 'Debes confirmar la operación.',
            'max.string' => 'El campo :attribute admite hasta :max caracteres.',
            'regex' => 'El campo :attribute no tiene un formato válido.',
            'in' => 'Selecciona un valor permitido para :attribute.',
            'date_format' => 'El campo :attribute debe tener formato AAAA-MM-DD.',
            'after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
        ];
    }
}
