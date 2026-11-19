<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\ColaAnalisisRequest;
use App\Services\Administration\ColaMetricaService;
use Illuminate\Contracts\View\View;

class ColaController extends Controller
{
    public function __construct(
        private readonly ColaMetricaService $metricas,
    ) {}

    public function index(ColaAnalisisRequest $request): View
    {
        $datos = $this->metricas->obtenerDatosPanel($request->validated());

        return view('administracion.colas.index', $datos);
    }
}
