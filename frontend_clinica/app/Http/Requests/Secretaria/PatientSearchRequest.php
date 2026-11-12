<?php

namespace App\Http\Requests\Secretaria;

class PatientSearchRequest extends ReceptionRequest
{
    public function rules(): array
    {
        return ['q' => ['bail', 'nullable', 'string', 'max:100', function ($attribute, $value, $fail): void {
            if (preg_match_all('/[\p{L}\p{N}]/u', $value) < 3) {
                $fail('Introduce al menos tres letras o números para buscar.');
            }
        }]];
    }
}
