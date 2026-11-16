<?php

namespace App\Http\Requests\Paciente;

final class ReserveRequest extends PacienteRequest
{
    public function rules(): array
    {
        return [
            'codSlot' => ['required', ...self::identifier()],
        ];
    }

    protected function errorFormContext(): array
    {
        return ['target' => 'paciente_reserve'];
    }
}
