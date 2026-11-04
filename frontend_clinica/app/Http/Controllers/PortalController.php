<?php

namespace App\Http\Controllers;

use App\Services\QuarkusApiClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;

class PortalController extends Controller
{
    public function __construct(private readonly QuarkusApiClient $apiClient) {}

    public function index(): View
    {
        $specialties = [];
        $specialtiesState = 'not-configured';
        $specialtiesMessage = 'La API de especialidades todavía no está expuesta por Quarkus.';
        $endpoint = config('clinic.endpoints.specialties');

        if (filled($endpoint)) {
            try {
                $response = $this->apiClient->get($endpoint);

                if ($response->successful()) {
                    $payload = $response->json();
                    $items = is_array($payload) && array_is_list($payload)
                        ? $payload
                        : data_get($payload, 'data', []);

                    if (is_array($items)) {
                        $specialties = collect($items)
                            ->filter(fn (mixed $item): bool => is_array($item))
                            ->map(fn (array $item): array => [
                                'code' => $item['codEsp'] ?? null,
                                'name' => $item['nombreEspecialidad'] ?? null,
                                'description' => $item['descripcion'] ?? null,
                                'duration' => $item['duracionConsultaMin'] ?? null,
                            ])
                            ->filter(fn (array $item): bool => filled($item['name']))
                            ->values()
                            ->all();
                        $specialtiesState = $specialties === [] ? 'empty' : 'ready';
                        $specialtiesMessage = $specialties === []
                            ? 'No hay especialidades activas disponibles en este momento.'
                            : null;
                    } else {
                        $specialtiesState = 'error';
                        $specialtiesMessage = 'La API respondió con un formato que el portal no reconoce.';
                    }
                } else {
                    $specialtiesState = 'error';
                    $specialtiesMessage = 'No fue posible consultar las especialidades. Intenta nuevamente.';
                }
            } catch (ConnectionException) {
                $specialtiesState = 'error';
                $specialtiesMessage = 'El servicio de especialidades no está disponible en este momento.';
            }
        }

        return view('welcome', [
            'clinic' => config('clinic'),
            'specialties' => $specialties,
            'specialtiesState' => $specialtiesState,
            'specialtiesMessage' => $specialtiesMessage,
        ]);
    }
}
