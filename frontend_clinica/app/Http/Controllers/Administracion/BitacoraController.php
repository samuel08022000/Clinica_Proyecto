<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\BitacoraRequest;
use App\Services\Administration\AdministrationService;
use App\Services\Administration\AdministrativePdfService;
use App\Services\Administration\BitacoraService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BitacoraController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('administracion.bitacoras.show', ['seccion' => 'pacientes']);
    }

    public function show(BitacoraRequest $request, BitacoraService $service, string $seccion): View
    {
        return view('administracion.bitacoras.index', $service->listing($seccion, $request->validated()));
    }

    public function pdf(BitacoraRequest $request, BitacoraService $service, AdministrativePdfService $pdf, string $seccion): Response
    {
        return $pdf->audit($service->listing($seccion, $request->validated(), true), $request->user()->nombre_completo);
    }

    public function profilePdf(Request $request, AdministrationService $service, AdministrativePdfService $pdf, string $seccion, string $codigo): Response
    {
        $prefix = match ($seccion) {
            'pacientes' => 'PAC', 'medicos' => 'MED', 'secretarias' => 'SEC'
        };
        abort_unless(preg_match('/^'.$prefix.'[0-9]+$/D', $codigo), 404);
        $path = $seccion === 'pacientes' ? 'administracion/pacientes/' : 'personal/'.$seccion.'/';
        $record = $service->request('GET', $path.$codigo);

        return $pdf->profile($seccion, $record, $request->user()->nombre_completo);
    }
}
