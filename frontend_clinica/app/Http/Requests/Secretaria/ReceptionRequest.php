<?php

namespace App\Http\Requests\Secretaria;

use App\Http\Middleware\ReceptionResponses;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ReceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->get('cod_rol') === 'ROL002';
    }

    protected function failedValidation(Validator $validator): never
    {
        $response = $this->isMethod('GET')
            ? response()->view('secretaria.error', ['status' => 422, 'message' => $validator->errors()->first(), 'returnUrl' => $this->url()], 422)
            : back()->withErrors($validator)->withInput(ReceptionResponses::safeInput($this));
        throw new HttpResponseException(ReceptionResponses::privateResponse($response));
    }

    public static function identifier(): array
    {
        return ['string', 'max:64', 'regex:/\A[A-Za-z0-9]+\z/'];
    }

    public function attributes(): array
    {
        return ['q' => 'búsqueda', 'codPac' => 'paciente', 'codEsp' => 'especialidad', 'codMed' => 'médico', 'codSlot' => 'turno', 'tokenQr' => 'contenido QR', 'fecha' => 'fecha', 'desde' => 'fecha inicial', 'hasta' => 'fecha final', 'motivo' => 'motivo', 'confirmacion' => 'confirmación'];
    }

    public function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'accepted' => 'Debes confirmar la operación.', 'max.string' => 'El campo :attribute admite hasta :max caracteres.', 'regex' => 'El campo :attribute no tiene un formato válido.', 'date_format' => 'Usa una fecha válida con formato AAAA-MM-DD.', 'integer' => 'El campo :attribute debe ser entero.', 'between.numeric' => 'El campo :attribute debe estar entre :min y :max.', 'in' => 'Selecciona un valor permitido para :attribute.', 'after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.', 'prohibited' => 'Utiliza una fecha o un rango; no combines ambos.'];
    }
}
