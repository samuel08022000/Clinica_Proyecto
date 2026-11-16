<?php

namespace App\Http\Requests\Medico;

use App\Http\Middleware\MedicoResponses;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class MedicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->get('cod_rol') === 'ROL003';
    }

    protected function failedValidation(Validator $validator): never
    {
        $response = back()->withErrors($validator)->withInput(MedicoResponses::safeInput($this));
        $context = $this->errorFormContext();
        if (isset($context['target'])) {
            $response->with('medico_form_target', $context['target']);
        }
        if (isset($context['fieldIds'])) {
            $response->with('error_field_ids', $context['fieldIds']);
        }

        throw new HttpResponseException(MedicoResponses::privateResponse($response));
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
        return ['string', 'max:64', 'regex:/\A[A-Za-z0-9]+\z/'];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'accepted' => 'Debes confirmar la operación.',
            'max.string' => 'El campo :attribute admite hasta :max caracteres.',
            'regex' => 'El campo :attribute no tiene un formato válido.',
            'in' => 'Selecciona un valor permitido para :attribute.',
            'required_if' => 'El resultado es obligatorio al completar el análisis.',
            'prohibited_unless' => 'El resultado solo se registra al completar el análisis.',
        ];
    }
}
