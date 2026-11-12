<?php

namespace App\Http\Requests\Administracion;

class PersonalRequest extends AdminRequest
{
    public function rules(): array
    {
        $medical = $this->routeIs('administracion.medicos.*');
        $create = $this->isMethod('POST');
        $rules = [
            'nombreCompleto' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'email', 'max:254'], 'ci' => ['nullable', 'string', 'max:30'],
        ];
        if ($medical) {
            $rules += [
                'matriculaProfesional' => ['required', 'string', 'max:80'],
                'resumenCurricular' => ['nullable', 'string', 'max:10000'],
                'duracionConsultaMin' => ['nullable', 'integer', 'in:20,30,45,60'],
                'codigosEspecialidades' => ['required', 'array', 'min:1'],
                'codigosEspecialidades.*' => ['required', 'string', 'max:20', 'distinct'],
            ];
        } else {
            $rules['telefono'] = ['nullable', 'string', 'max:25'];
        }
        if ($create || $medical) {
            $rules['password'] = $this->passwordRules($create, ! $medical);
        }

        return $rules;
    }
}
