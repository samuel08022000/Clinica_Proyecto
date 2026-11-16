<?php

namespace App\Http\Controllers\Paciente;

use App\Contracts\PatientProfileProvider;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class ProfileController extends Controller
{
    public function __construct(private PatientProfileProvider $profiles) {}

    public function show(): View
    {
        $profile = $this->profiles->profile();

        return view('paciente.perfil', ['perfil' => $profile + [
            'nombre' => $profile['nombreCompleto'],
        ]]);
    }
}
