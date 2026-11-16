<?php

namespace App\Http\Controllers\Secretaria;

use Illuminate\View\View;

class DashboardController extends ReceptionController
{
    public function __invoke(): View
    {
        // Omitir fechas delega "hoy" a public.fecha_clinica() en PostgreSQL.
        $page = $this->read('citas', ['page' => 0, 'size' => 20]);

        return view('secretaria.dashboard', compact('page'));
    }
}
