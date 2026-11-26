<?php

namespace App\Http\Controllers\Secretaria;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends ReceptionController
{
    public function __invoke(Request $request): View
    {
        $referrals = $this->read('derivaciones', ['estado' => 'Pendiente', 'page' => 0, 'size' => 3]);
        if ($request->header('X-Clinical-Refresh') === '1') {
            return view('secretaria.partials.referral-notices', compact('referrals'));
        }

        // Omitir fechas delega "hoy" a public.fecha_clinica() en PostgreSQL.
        $page = $this->read('citas', ['page' => 0, 'size' => 20]);

        return view('secretaria.dashboard', compact('page', 'referrals'));
    }
}
