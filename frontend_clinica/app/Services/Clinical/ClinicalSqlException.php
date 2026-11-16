<?php

namespace App\Services\Clinical;

use App\Exceptions\ClinicalOperationException;
use Illuminate\Database\QueryException;

/** No conserva SQL, bindings, secretos ni la excepción original en logs HTTP. */
final class ClinicalSqlException extends ClinicalOperationException
{
    public function __construct(public readonly string $sqlState)
    {
        [$status, $code, $message] = match ($sqlState) {
            '23503' => [409, 'REFERENCIA_INVALIDA', 'La operación referencia un registro inexistente o relacionado.'],
            '23505', '23P01', 'P4090', 'P4091', 'P4092', 'P4093', 'P4094', '40001', '40P01' => [409, 'CONFLICTO', 'La operación entra en conflicto con el estado actual o con otra operación.'],
            '23514', 'P4000', 'P4001', 'P4002', 'P4003' => [400, 'VALIDACION', 'La operación no cumple las reglas clínicas.'],
            '42501', 'P4030', 'P4031', 'P4032', 'P4033' => [403, 'PROHIBIDO', 'La operación no está autorizada.'],
            'P4040', 'P4041', 'P4042', 'P4043' => [404, 'NO_ENCONTRADO', 'El registro solicitado no existe.'],
            'P5001' => [503, 'QR_NO_DISPONIBLE', 'La validación QR no está disponible temporalmente.'],
            default => [500, 'ERROR_CLINICO', 'No se pudo completar la operación clínica.'],
        };
        parent::__construct($status, $message, $code);
    }

    public static function fromQuery(QueryException $error): self
    {
        $state = (string) ($error->errorInfo[0] ?? $error->getCode());

        return new self(preg_match('/^[A-Z0-9]{5}$/', $state) ? $state : 'HY000');
    }
}
