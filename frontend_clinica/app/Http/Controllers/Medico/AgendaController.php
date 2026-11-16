<?php

namespace App\Http\Controllers\Medico;

use App\Exceptions\ClinicalOperationException;
use App\Http\Controllers\Controller;
use App\Services\Medico\MedicoAtencionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AgendaController extends Controller
{
    public function __construct(private MedicoAtencionService $service) {}

    public function index(Request $request): View
    {
        $date = $request->has('fecha') ? $this->queryString($request, 'fecha') : null;

        return view('medico.agenda.index', $this->service->agenda($date));
    }

    public function history(Request $request): View
    {
        $filters = [];
        foreach (['desde', 'hasta', 'page', 'size'] as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $this->queryString($request, $key);
            }
        }

        return view('medico.historial.index', ['page' => $this->service->history($filters), 'filters' => $filters]);
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);
        if (! is_string($value)) {
            throw new ClinicalOperationException(400, "El filtro $key no es válido.", 'VALIDACION');
        }

        return $value;
    }
}
