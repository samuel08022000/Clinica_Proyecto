<?php

namespace App\Http\Controllers\Administracion;

use App\Exceptions\QuarkusApiException;
use Illuminate\Contracts\View\View;

class DashboardController extends AdminController
{
    public function __invoke(): View
    {
        $counts = [];
        foreach (['medicos', 'secretarias', 'especialidades'] as $catalog) {
            try {
                // These contracts are unpaginated catalogs, not an expensive walk through pages.
                $counts[$catalog] = count($this->api->administrative('GET', 'personal/'.$catalog, ['estado' => 'Activo']));
            } catch (QuarkusApiException $exception) {
                if ($exception->status < 500) {
                    throw $exception;
                }
                $counts[$catalog] = null;
            }
        }

        return view('administracion.dashboard', compact('counts'));
    }
}
