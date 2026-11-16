<?php

namespace App\Http\Controllers\Medico;

use App\Http\Controllers\Controller;
use App\Http\Requests\Medico\AnalysisRequest;
use App\Http\Requests\Medico\AnalysisResultRequest;
use App\Services\Medico\MedicoAnalisisService;
use App\Services\Medico\MedicoAtencionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class AnalisisController extends Controller
{
    public function __construct(
        private MedicoAnalisisService $analyses,
        private MedicoAtencionService $appointments,
    ) {}

    public function index(string $cita): View
    {
        return view('medico.analisis.index', [
            'appointment' => $this->appointments->appointment($cita),
            'analisis' => $this->analyses->listing($cita),
        ]);
    }

    public function store(AnalysisRequest $request, string $cita): RedirectResponse
    {
        $analysis = $this->analyses->request($cita, $request->validated());

        return redirect()->route('medico.analisis.index', $cita)
            ->with('success', "La solicitud {$analysis['codAna']} fue registrada sin duplicar estudios existentes.");
    }

    public function update(AnalysisResultRequest $request, string $analisis): RedirectResponse
    {
        $updated = $this->analyses->update($analisis, $request->validated());

        return redirect()->route('medico.analisis.index', $updated['codCit'])
            ->with('success', 'El estado del análisis fue actualizado y auditado.');
    }
}
