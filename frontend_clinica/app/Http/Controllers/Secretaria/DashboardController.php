<?php

namespace App\Http\Controllers\Secretaria;

use Illuminate\View\View;

class DashboardController extends ReceptionController
{
    public function __invoke(): View
    {
        // Omitting dates delegates "today" to public.fecha_clinica() in Quarkus.
        $page = $this->read('citas', ['page' => 0, 'size' => 20]);

        return view('secretaria.dashboard', compact('page'));
    }
}
