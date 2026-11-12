<?php

namespace App\Http\Controllers\Secretaria;

use App\Exceptions\QuarkusApiException;
use App\Http\Requests\Secretaria\CheckinRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CheckinController extends ReceptionController
{
    public function index(): View
    {
        return view('secretaria.checkin.index');
    }

    public function store(CheckinRequest $request): RedirectResponse
    {
        try {
            $attendance = $this->write('checkin', $request->safe()->only('tokenQr'), 'En Espera');
        } catch (QuarkusApiException $exception) {
            if (! in_array($exception->status, [400, 409, 422, 502, 503], true)) {
                throw $exception;
            }

            return redirect()->route('secretaria.checkin.index')->with('api_status', $exception->status)
                ->withErrors(['tokenQr' => match (true) {
                    $exception->status >= 500 => 'No se pudo confirmar el resultado. Consulta la asistencia antes de volver a escanear.',
                    $exception->status === 409 => 'La cita no admite registro de llegada o ya fue acreditada. Comprueba su fecha, estado y asistencia.',
                    default => 'El QR no pudo validarse. Comprueba el contenido y la fecha de la cita.',
                }]);
        }

        return redirect()->route('secretaria.citas.asistencia', $attendance['codCit'])->with('success', 'Check-in registrado. La llegada fue acreditada.');
    }

    public function attendance(string $cita): View
    {
        return view('secretaria.checkin.attendance', ['attendance' => $this->read('citas/'.$cita.'/asistencia')]);
    }

    public function accreditation(string $cita): View
    {
        return view('secretaria.checkin.accreditation', ['accreditation' => $this->read('citas/'.$cita.'/acreditacion')]);
    }
}
