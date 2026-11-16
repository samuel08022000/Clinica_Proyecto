<?php

namespace App\Http\Requests\Medico;

use Illuminate\Validation\Rule;

final class AnalysisResultRequest extends MedicoRequest
{
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::in(['En Proceso', 'Completado'])],
            'resultado' => [
                'nullable',
                'string',
                'max:20000',
                'required_if:estado,Completado',
                'prohibited_unless:estado,Completado',
            ],
            'confirmacion' => ['required', 'accepted'],
        ];
    }

    protected function errorFormContext(): array
    {
        $analysisId = (string) $this->route('analisis');

        return [
            'target' => $analysisId,
            'fieldIds' => [
                'resultado' => 'resultado-'.$analysisId,
                'confirmacion' => 'confirmacion-'.$analysisId,
            ],
        ];
    }
}
