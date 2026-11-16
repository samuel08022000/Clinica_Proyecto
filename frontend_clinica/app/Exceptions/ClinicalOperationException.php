<?php

namespace App\Exceptions;

use App\Http\Middleware\AdministrativeResponses;
use App\Http\Middleware\MedicoResponses;
use App\Http\Middleware\PatientResponses;
use App\Http\Middleware\ReceptionResponses;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Error de dominio seguro: nunca transporta SQL, bindings ni secretos. */
class ClinicalOperationException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode = 'OPERACION_CLINICA',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): Response
    {
        if ($request->is('paciente/*')) {
            return PatientResponses::renderFailure($request, $this);
        }

        if ($request->is('medico/*')) {
            return MedicoResponses::renderFailure($request, $this);
        }

        if ($request->is('secretaria/*')) {
            return ReceptionResponses::renderFailure($request, $this);
        }

        return AdministrativeResponses::renderFailure($request, $this);
    }
}
