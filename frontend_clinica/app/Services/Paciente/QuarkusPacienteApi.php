<?php

namespace App\Services\Paciente;

use App\Contracts\PacienteApi;
use App\Exceptions\PacienteApiException;
use App\Services\QuarkusApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Implementación REAL. Requiere los endpoints /api/v1/paciente/... descritos en
 * PACIENTE_API_CONTRATO.md. La URL base (services.quarkus.base_url) ya incluye /api/v1,
 * igual que con auth/login.
 */
class QuarkusPacienteApi implements PacienteApi
{
    public function __construct(private readonly QuarkusApiClient $http) {}

    public function especialidades(): array
    {
        return $this->lista($this->send(fn () => $this->http->get('paciente/especialidades')));
    }

    public function medicos(?string $codEsp = null): array
    {
        return $this->lista($this->send(fn () => $this->http->get('paciente/medicos', ['codEsp' => $codEsp])));
    }

    public function disponibilidad(string $codEsp, ?string $codMed, string $fecha): array
    {
        return $this->lista($this->send(fn () => $this->http->get('paciente/disponibilidad', [
            'codEsp' => $codEsp, 'codMed' => $codMed, 'fecha' => $fecha,
        ])));
    }

    public function citas(): array
    {
        return $this->lista($this->send(fn () => $this->http->get('paciente/citas')));
    }

    public function crearCita(string $codSlot): array
    {
        return (array) $this->datos($this->send(fn () => $this->http->post('paciente/citas', [
            'codSlot' => $codSlot,
        ])));
    }

    public function cancelarCita(string $codCit): void
    {
        $this->send(fn () => $this->http->put('paciente/citas/'.rawurlencode($codCit).'/cancelar'));
    }

    public function perfil(): array
    {
        return (array) $this->datos($this->send(fn () => $this->http->get('paciente/perfil')));
    }

    public function actualizarPerfil(array $datos): array
    {
        return (array) $this->datos($this->send(fn () => $this->http->put('paciente/perfil', [
            'telefono' => $datos['telefono'] ?? null,
            'ciudadResidencia' => $datos['ciudadResidencia'] ?? null,
        ])));
    }

    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new PacienteApiException('SERVICIO_NO_DISPONIBLE');
        }

        if (! $response->successful()) {
            $json = $response->json();
            // Acepta { error: { code, message } } (auth) y { ok:false, error:"CODIGO", mensaje } (administración).
            $error = data_get($json, 'error');
            $code = is_string($error) ? $error : (string) (data_get($json, 'error.code') ?? 'ERROR');
            $message = (string) (data_get($json, 'mensaje') ?? data_get($json, 'error.message') ?? '');
            throw new PacienteApiException($code, $message, $response->status());
        }

        return $response;
    }

    /** Acepta tanto { "data": ... } (como auth) como el JSON directo (como administración). */
    private function datos(Response $response): mixed
    {
        $json = $response->json();

        return is_array($json) && array_key_exists('data', $json) ? $json['data'] : $json;
    }

    private function lista(Response $response): array
    {
        $datos = $this->datos($response);

        return is_array($datos) ? array_values($datos) : [];
    }
}
