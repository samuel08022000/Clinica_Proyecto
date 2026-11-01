<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class QuarkusApiClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('QUARKUS_API_URL', 'http://localhost:8080/api/v1'), '/');
    }

    protected function client()
    {
        return Http::acceptJson()
            ->contentType('application/json')
            ->withHeaders([
                'X-Actor-Cod-Usu' => session('cod_usu', ''),
                'X-Actor-Rol'     => session('cod_rol', ''),
            ]);
    }

    public function get(string $endpoint, array $query = []): Response
    {
        return $this->client()->get("{$this->baseUrl}/" . ltrim($endpoint, '/'), array_filter($query, fn($v) => $v !== null && $v !== ''));
    }

    public function post(string $endpoint, array $data = []): Response
    {
        return $this->client()->post("{$this->baseUrl}/" . ltrim($endpoint, '/'), $data);
    }

    public function put(string $endpoint, array $data = []): Response
    {
        return $this->client()->put("{$this->baseUrl}/" . ltrim($endpoint, '/'), $data);
    }

    public function patch(string $endpoint, array $data = []): Response
    {
        return $this->client()->patch("{$this->baseUrl}/" . ltrim($endpoint, '/'), $data);
    }
}