<?php

namespace App\Http\Controllers\Medico;

use App\Http\Controllers\Controller;
use App\Services\Medico\MedicoAtencionService;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(MedicoAtencionService $service): View
    {
        return view('medico.dashboard', $service->dashboard());
    }
}
