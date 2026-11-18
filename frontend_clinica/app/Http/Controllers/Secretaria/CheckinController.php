<?php

namespace App\Http\Controllers\Secretaria;

use App\Exceptions\ClinicalOperationException;
use App\Http\Requests\Secretaria\CheckinRequest;
use App\Services\Notifications\AppointmentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CheckinController extends ReceptionController
{
    public function index(): View
    {
        return view('secretaria.checkin.index');
    }

    public function store(CheckinRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $attendance = $this->write('checkin', $request->safe()->only(['tokenQr', 'codCit']), 'En Espera');
        } catch (ClinicalOperationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $this->checkinMessage($exception), 'error' => $exception->errorCode], $exception->status);
            }
            if (! in_array($exception->status, [400, 409, 422, 502, 503], true)) {
                throw $exception;
            }

            return redirect()->route('secretaria.checkin.index')->with('api_status', $exception->status)
                ->withErrors(['tokenQr' => match (true) {
                    $exception->status >= 500 => 'No se pudo confirmar el resultado. Consulta la asistencia antes de volver a escanear.',
                    default => $this->checkinMessage($exception),
                }]);
        }

        app(AppointmentNotificationService::class)->sendCheckinNotification($attendance['codCit']);
        if ($request->expectsJson()) {
            return response()->json($attendance + ['message' => 'Llegada registrada. El paciente está En Espera.']);
        }

        return redirect()->route('secretaria.citas.asistencia', $attendance['codCit'])->with('success', 'Check-in registrado. La llegada fue acreditada.');
    }

    public function preview(CheckinRequest $request): JsonResponse
    {
        try {
            return response()->json($this->backend->request('POST', 'checkin/validar', $request->safe()->only('tokenQr')));
        } catch (ClinicalOperationException $error) {
            return response()->json(['message' => $this->checkinMessage($error), 'error' => $error->errorCode], $error->status);
        }
    }

    public function attendance(string $cita): View
    {
        return view('secretaria.checkin.attendance', ['attendance' => $this->read('citas/'.$cita.'/asistencia')]);
    }

    private function checkinMessage(ClinicalOperationException $error): string
    {
        return in_array($error->errorCode, ['QR_INVALIDO', 'CHECKIN_DUPLICADO', 'ESTADO_INCOMPATIBLE', 'QR_OTRA_FECHA', 'CHECKIN_NO_DISPONIBLE'], true)
            ? $error->getMessage()
            : 'El QR no pudo validarse. Comprueba la fecha, el estado de la cita y si su llegada ya fue registrada.';
    }

    public function accreditation(string $cita): View
    {
        return view('secretaria.checkin.accreditation', ['accreditation' => $this->read('citas/'.$cita.'/acreditacion')]);
    }
}
