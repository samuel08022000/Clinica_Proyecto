<?php

namespace App\Http\Controllers\Secretaria;

use App\Http\Requests\Secretaria\ReferralBrowseRequest;
use Illuminate\View\View;

final class DerivacionController extends ReceptionController
{
    public function index(ReferralBrowseRequest $request): View
    {
        $filters = $request->filters();

        return view('secretaria.derivaciones.index', [
            'page' => $this->read('derivaciones', $filters),
            'filters' => $filters,
        ]);
    }

    public function show(string $derivacion): View
    {
        return view('secretaria.derivaciones.show', [
            'derivacion' => $this->read('derivaciones/'.$derivacion),
        ]);
    }
}
