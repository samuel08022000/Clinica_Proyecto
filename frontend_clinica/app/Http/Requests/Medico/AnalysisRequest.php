<?php

namespace App\Http\Requests\Medico;

final class AnalysisRequest extends MedicoRequest
{
    public function rules(): array
    {
        return [
            'tipoAnalisis' => ['required', 'string', 'max:150'],
            'confirmacion' => ['required', 'accepted'],
        ];
    }

    protected function errorFormContext(): array
    {
        return [
            'target' => 'solicitud',
            'fieldIds' => ['confirmacion' => 'confirmacion-solicitud'],
        ];
    }
}
