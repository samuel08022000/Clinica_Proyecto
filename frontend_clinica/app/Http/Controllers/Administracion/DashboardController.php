<?php

namespace App\Http\Controllers\Administracion;

use App\Exceptions\ClinicalOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\DashboardRequest;
use App\Services\Administration\DashboardService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, DashboardService $service): View
    {
        $filters = $request->validated();
        try {
            $dashboard = $service->snapshot($filters);
        } catch (ClinicalOperationException $exception) {
            if ($exception->status < 500) {
                throw $exception;
            }
            $dashboard = null;
        }

        return view('administracion.dashboard', compact('dashboard', 'filters'));
    }
}
