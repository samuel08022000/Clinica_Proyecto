<?php

namespace App\Http\Controllers\Medico;

use App\Http\Controllers\Controller;
use App\Http\Requests\Medico\ReferralRequest;
use App\Services\Medico\MedicoAtencionService;
use App\Services\Medico\MedicoDerivacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DerivacionController extends Controller
{
    public function __construct(
        private MedicoDerivacionService $referrals,
        private MedicoAtencionService $appointments,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->only(['estado', 'page', 'size']);

        return view('medico.derivaciones.index', ['page' => $this->referrals->listing($filters), 'filters' => $filters]);
    }

    public function show(string $derivacion): View
    {
        return view('medico.derivaciones.show', ['derivacion' => $this->referrals->detail($derivacion)]);
    }

    public function create(string $cita): View
    {
        return view('medico.derivaciones.create', [
            'appointment' => $this->appointments->appointment($cita),
            'especialidades' => $this->appointments->specialties(),
            'medicos' => $this->appointments->eligibleDoctors(),
        ]);
    }

    public function store(ReferralRequest $request, string $cita): RedirectResponse
    {
        $referral = $this->referrals->create($cita, $request->validated());

        return redirect()->route('medico.derivaciones.show', $referral['codDer'])
            ->with('success', 'La derivación fue emitida y quedó disponible para Secretaría.');
    }
}
