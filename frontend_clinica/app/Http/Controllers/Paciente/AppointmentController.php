<?php

namespace App\Http\Controllers\Paciente;

use App\Contracts\PatientAppointmentManager;
use App\Contracts\PatientAvailabilityProvider;
use App\Exceptions\ClinicalOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Paciente\BookingSearchRequest;
use App\Http\Requests\Paciente\CancelAppointmentRequest;
use App\Http\Requests\Paciente\RescheduleAppointmentRequest;
use App\Http\Requests\Paciente\ReserveRequest;
use App\Services\Notifications\AppointmentNotificationService;
use App\Support\PatientAppointmentPresentation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

final class AppointmentController extends Controller
{
    public function __construct(
        private PatientAppointmentManager $appointments,
        private PatientAvailabilityProvider $availability,
        private AppointmentNotificationService $notifications,
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab');
        if (! is_string($tab) || ! in_array($tab, ['proximas', 'pasadas', 'canceladas'], true)) {
            $tab = 'proximas';
        }

        $pages = [
            'proximas' => $this->appointments->list(['alcance' => 'proximas', 'page' => 0, 'size' => 50]),
            'pasadas' => $this->appointments->list(['alcance' => 'pasadas', 'page' => 0, 'size' => 50]),
            'canceladas' => $this->appointments->list([
                'alcance' => 'todas',
                'estado' => 'Cancelada',
                'page' => 0,
                'size' => 50,
            ]),
        ];
        $pages['pasadas']['citas'] = array_values(array_filter(
            $pages['pasadas']['citas'],
            static fn (array $appointment): bool => $appointment['estado'] !== 'Cancelada',
        ));

        return view('paciente.citas', [
            'tab' => $tab,
            'citas' => collect($pages[$tab]['citas'])
                ->map(static fn (array $appointment): array => PatientAppointmentPresentation::decorate($appointment)),
            'conteos' => collect($pages)->mapWithKeys(static fn (array $page, string $key): array => [
                $key => count($page['citas']).($page['hayMas'] ? '+' : ''),
            ])->all(),
        ]);
    }

    public function create(BookingSearchRequest $request): View
    {
        $filters = $request->validated();
        $specialtyCode = $filters['codEsp'] ?? null;
        $doctorCode = $filters['codMed'] ?? null;
        $date = $filters['fecha'] ?? null;
        $doctors = [];
        $slots = collect();

        if ($specialtyCode !== null) {
            $doctors = $this->availability->doctors($specialtyCode);
            if ($date !== null) {
                $result = $this->availability->slots(array_filter([
                    'codEsp' => $specialtyCode,
                    'codMed' => $doctorCode,
                    'fecha' => $date,
                ], static fn (mixed $value): bool => $value !== null));
                $slots = collect($result['slots'])->groupBy('nombreMedico');
            }
        }

        return view('paciente.nueva-cita', $this->bookingViewData(
            $specialtyCode,
            $doctorCode,
            $date,
            $doctors,
            $slots,
        ));
    }

    public function store(ReserveRequest $request): RedirectResponse
    {
        $appointment = $this->appointments->reserve($request->validated('codSlot'));

        return redirect()->route('paciente.citas.show', $appointment['codCit'])
            ->with('success', 'Tu cita fue confirmada. El correo QR está pendiente de procesamiento; tu pase está disponible aquí.');
    }

    public function show(string $cita): View
    {
        $detail = PatientAppointmentPresentation::decorate($this->appointments->detail($cita));
        $qrBase64 = null;

        if (! empty($detail['puedeVerPaseQr'])) {
            try {
                $pass = $this->appointments->checkInPass($cita);
                $qrPng = $this->notifications->qrPng($pass['tokenQr']);
                $qrBase64 = base64_encode($qrPng);
            } catch (Throwable $e) {
                Log::warning('No se pudo renderizar el QR de la cita.', ['cita' => $cita]);
            }
        }

        return view('paciente.detalle', [
            'cita' => $detail,
            'qrBase64' => $qrBase64,
        ]);
    }

    public function cancel(CancelAppointmentRequest $request, string $cita): RedirectResponse
    {
        $this->appointments->cancel($cita, $request->validated('motivo'));

        return redirect()->route('paciente.citas', ['tab' => 'canceladas'])
            ->with('success', 'Tu cita fue cancelada.');
    }

    public function editReschedule(BookingSearchRequest $request, string $cita): View
    {
        $appointment = $this->appointments->detail($cita);
        if (! $appointment['puedeReprogramar']) {
            throw new ClinicalOperationException(
                409,
                'La cita no puede reprogramarse en su estado actual.',
                'CONFLICTO',
            );
        }

        $filters = $request->validated();
        $doctorCode = $filters['codMed'] ?? null;
        $date = $filters['fecha'] ?? null;
        $slots = collect();
        if ($date !== null) {
            $result = $this->availability->slots(array_filter([
                'codEsp' => $appointment['codEsp'],
                'codMed' => $doctorCode,
                'fecha' => $date,
            ], static fn (mixed $value): bool => $value !== null));
            $slots = collect($result['slots'])->groupBy('nombreMedico');
        }

        return view('paciente.reprogramar', [
            'cita' => PatientAppointmentPresentation::decorate($appointment),
            'medicos' => $this->mapDoctors($this->availability->doctors($appointment['codEsp'])),
            'cupos' => $slots,
            'med' => $doctorCode,
            'fecha' => $date,
            ...$this->dateRange(),
        ]);
    }

    public function reschedule(RescheduleAppointmentRequest $request, string $cita): RedirectResponse
    {
        $validated = $request->validated();
        $appointment = $this->appointments->reschedule(
            $cita,
            $validated['codSlotNuevo'],
            $validated['motivo'],
        );

        return redirect()->route('paciente.citas.show', $appointment['codCit'])
            ->with('success', 'Tu cita fue reprogramada. El nuevo pase está disponible aquí y su correo está pendiente de procesamiento.');
    }

    /** @return array<string, mixed> */
    private function bookingViewData(
        ?string $specialtyCode,
        ?string $doctorCode,
        ?string $date,
        array $doctors,
        Collection $slots,
    ): array {
        return [
            'especialidades' => array_map(static fn (array $specialty): array => [
                'codEsp' => $specialty['codEsp'],
                'nombre' => $specialty['nombreEspecialidad'],
            ], $this->availability->specialties()),
            'medicos' => $this->mapDoctors($doctors),
            'cupos' => $slots,
            'esp' => $specialtyCode,
            'med' => $doctorCode,
            'fecha' => $date,
            ...$this->dateRange(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function mapDoctors(array $doctors): array
    {
        return array_map(static fn (array $doctor): array => [
            'codMed' => $doctor['codMed'],
            'nombre' => $doctor['nombreCompleto'],
        ], $doctors);
    }

    /** @return array{fechaMin: string, fechaMax: string} */
    private function dateRange(): array
    {
        $today = CarbonImmutable::now('America/La_Paz');

        return [
            'fechaMin' => $today->format('Y-m-d'),
            'fechaMax' => $today->addDays(14)->format('Y-m-d'),
        ];
    }
}
