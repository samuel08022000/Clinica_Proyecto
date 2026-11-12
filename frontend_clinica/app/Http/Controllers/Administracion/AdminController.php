<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Services\QuarkusApiClient;
use Illuminate\Http\RedirectResponse;

abstract class AdminController extends Controller
{
    public function __construct(protected readonly QuarkusApiClient $api) {}

    protected function save(string $method, string $endpoint, array $data, string $route, array $parameters = []): RedirectResponse
    {
        $this->api->administrative($method, $endpoint, $data);

        return redirect()->route($route, $parameters)->with('success', 'La operación se completó correctamente.');
    }
}
