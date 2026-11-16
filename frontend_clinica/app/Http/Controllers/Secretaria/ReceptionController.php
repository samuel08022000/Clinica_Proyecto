<?php

namespace App\Http\Controllers\Secretaria;

use App\Exceptions\ClinicalOperationException;
use App\Http\Controllers\Controller;
use App\Services\Reception\ReceptionService;

abstract class ReceptionController extends Controller
{
    public function __construct(protected readonly ReceptionService $backend) {}

    protected function read(string $path, array $query = []): array
    {
        return $this->backend->request('GET', $path, $query);
    }

    protected function write(string $path, array $data, string $expectedState): array
    {
        $result = $this->backend->request('POST', $path, $data);
        $code = $result['codCit'] ?? null;
        if (! is_string($code) || ! preg_match('/\A[A-Za-z0-9]{1,64}\z/', $code) || ($result['estado'] ?? null) !== $expectedState) {
            throw new ClinicalOperationException(502, 'El servicio local no confirmó el resultado esperado. Consulta la cita y su asistencia antes de reintentar.');
        }

        return $result;
    }
}
