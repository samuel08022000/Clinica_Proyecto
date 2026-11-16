<?php

namespace App\Http\Controllers\Secretaria;

use App\Exceptions\QuarkusApiException;
use App\Http\Controllers\Controller;
use App\Services\QuarkusApiClient;

abstract class ReceptionController extends Controller
{
    public function __construct(protected readonly QuarkusApiClient $api) {}

    protected function read(string $path, array $query = []): array
    {
        return $this->api->administrative('GET', 'secretaria/'.$path, $query);
    }

    protected function write(string $path, array $data, string $expectedState): array
    {
        $result = $this->api->administrative('POST', 'secretaria/'.$path, $data);
        $code = $result['codCit'] ?? null;
        if (! is_string($code) || ! preg_match('/\A[A-Za-z0-9]{1,64}\z/', $code) || ($result['estado'] ?? null) !== $expectedState) {
            throw new QuarkusApiException(502, 'El servicio no confirmó el resultado esperado. Consulta la cita y su asistencia antes de reintentar.');
        }

        return $result;
    }
}
