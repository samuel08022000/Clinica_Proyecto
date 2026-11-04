<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class QuarkusApiClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.quarkus.base_url'), '/');
    }

    protected function client(): PendingRequest
    {
        return Http::acceptJson()
            ->contentType('application/json')
            ->timeout((int) config('services.quarkus.timeout', 8))
            ->connectTimeout((int) config('services.quarkus.connect_timeout', 3))
            ->withHeaders([
                'X-Actor-Cod-Usu' => session('cod_usu', ''),
                'X-Actor-Rol' => session('cod_rol', ''),
            ]);
    }

    public function get(string $endpoint, array $query = []): Response
    {
        return $this->client()->get(
            "{$this->baseUrl}/".ltrim($endpoint, '/'),
            array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '')
        );
    }

    public function post(string $endpoint, array $data = []): Response
    {
        return $this->client()->post("{$this->baseUrl}/".ltrim($endpoint, '/'), $data);
    }

    public function put(string $endpoint, array $data = []): Response
    {
        return $this->client()->put("{$this->baseUrl}/".ltrim($endpoint, '/'), $data);
    }

    public function patch(string $endpoint, array $data = []): Response
    {
        return $this->client()->patch("{$this->baseUrl}/".ltrim($endpoint, '/'), $data);
    }
}
