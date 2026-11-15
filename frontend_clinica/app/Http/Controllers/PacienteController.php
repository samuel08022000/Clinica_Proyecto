<?php

namespace App\Http\Controllers;

use App\Contracts\PacienteApi;
use App\Exceptions\PacienteApiException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PacienteController extends Controller
{
    private const ESTADOS_ACTIVOS = ['Confirmada', 'En Espera', 'Afectada/Pendiente Reprogramación'];

    /** Estados desde los que el paciente puede cancelar (si faltan 24 h o más). */
    private const ESTADOS_CANCELABLES = ['Confirmada', 'Afectada/Pendiente Reprogramación'];

    public function __construct(private readonly PacienteApi $api) {}

    public function home(Request $request): View
    {
        $error = null;
        try {
            $citas = $this->decorar($this->api->citas());
        } catch (PacienteApiException $e) {
            $citas = collect();
            $error = $e->userMessage();
        }

        $proximas = $citas->filter(fn ($c) => $c['esProxima'])->sortBy('inicio')->values();

        return view('paciente.home', [
            'nombre' => (string) $request->session()->get('nombre_usuario'),
            'proxima' => $proximas->first(),
            'totalProximas' => $proximas->count(),
            'totalAtendidas' => $citas->where('estado', 'Atendida')->count(),
            'errorServicio' => $error,
        ]);
    }

    public function citas(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['proximas', 'pasadas', 'canceladas'], true)
            ? $request->query('tab') : 'proximas';

        $error = null;
        try {
            $todas = $this->decorar($this->api->citas());
        } catch (PacienteApiException $e) {
            $todas = collect();
            $error = $e->userMessage();
        }

        $lista = match ($tab) {
            'proximas' => $todas->filter(fn ($c) => $c['esProxima'])->sortBy('inicio'),
            'canceladas' => $todas->where('estado', 'Cancelada')->sortByDesc('inicio'),
            default => $todas->filter(fn ($c) => ! $c['esProxima'] && $c['estado'] !== 'Cancelada')->sortByDesc('inicio'),
        };

        return view('paciente.citas', [
            'tab' => $tab,
            'citas' => $lista->values(),
            'conteos' => [
                'proximas' => $todas->filter(fn ($c) => $c['esProxima'])->count(),
                'pasadas' => $todas->filter(fn ($c) => ! $c['esProxima'] && $c['estado'] !== 'Cancelada')->count(),
                'canceladas' => $todas->where('estado', 'Cancelada')->count(),
            ],
            'errorServicio' => $error,
        ]);
    }

    public function nuevaCita(Request $request): View|RedirectResponse
    {
        $datos = $request->validate([
            'esp' => ['nullable', 'string', 'max:30'],
            'med' => ['nullable', 'string', 'max:30'],
            'fecha' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(14)->format('Y-m-d')],
        ], [
            'fecha.after_or_equal' => 'Elige una fecha desde hoy en adelante.',
            'fecha.before_or_equal' => 'Solo puedes agendar desde hoy hasta dentro de 14 días.',
            'fecha.date_format' => 'La fecha no es válida.',
        ]);

        $esp = $datos['esp'] ?? null;
        $med = $datos['med'] ?? null;
        $fecha = $datos['fecha'] ?? null;

        $especialidades = $medicos = $cupos = [];
        $error = null;
        try {
            $especialidades = $this->api->especialidades();
            if ($esp) {
                $medicos = $this->api->medicos($esp);
                if ($fecha) {
                    $cupos = $this->api->disponibilidad($esp, $med, $fecha);
                }
            }
        } catch (PacienteApiException $e) {
            $error = $e->userMessage();
        }

        return view('paciente.nueva-cita', [
            'especialidades' => $especialidades,
            'medicos' => $medicos,
            'cupos' => collect($cupos)->groupBy('nombreMedico'),
            'esp' => $esp,
            'med' => $med,
            'fecha' => $fecha,
            'errorServicio' => $error,
            'fechaMin' => now()->format('Y-m-d'),
            'fechaMax' => now()->addDays(14)->format('Y-m-d'),
        ]);
    }

    public function guardarCita(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'codSlot' => ['required', 'string', 'max:100'],
        ], [
            'codSlot.required' => 'Elige un horario para tu cita.',
        ]);

        try {
            $this->api->crearCita($datos['codSlot']);
        } catch (PacienteApiException $e) {
            return back()->withInput()->withErrors(['cita' => $e->userMessage()]);
        }

        return redirect()->route('paciente.citas')->with('success', 'Tu cita fue confirmada. La puedes ver en "Próximas".');
    }

    public function cancelarCita(Request $request, string $codCit): RedirectResponse
    {
        if (! preg_match('/^[A-Za-z0-9_-]{3,40}$/', $codCit)) {
            abort(404);
        }

        try {
            $this->api->cancelarCita($codCit);
        } catch (PacienteApiException $e) {
            return redirect()->route('paciente.citas')->withErrors(['cita' => $e->userMessage()]);
        }

        return redirect()->route('paciente.citas', ['tab' => 'canceladas'])->with('success', 'Tu cita fue cancelada.');
    }

    public function perfil(): View
    {
        $error = null;
        $perfil = [];
        try {
            $perfil = $this->api->perfil();
        } catch (PacienteApiException $e) {
            $error = $e->userMessage();
        }

        return view('paciente.perfil', ['perfil' => $perfil, 'errorServicio' => $error]);
    }

    public function actualizarPerfil(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'telefono' => ['required', 'string', 'max:25', 'regex:/^\+?[0-9 ()-]{8,25}$/'],
            'ciudad_residencia' => ['required', 'string', 'min:3', 'max:100'],
        ], [
            'telefono.regex' => 'Ingresa un teléfono válido, por ejemplo +591 70000000.',
        ]);

        try {
            $this->api->actualizarPerfil([
                'telefono' => trim($datos['telefono']),
                'ciudadResidencia' => trim($datos['ciudad_residencia']),
            ]);
        } catch (PacienteApiException $e) {
            return back()->withInput()->withErrors(['perfil' => $e->userMessage()]);
        }

        return redirect()->route('paciente.perfil')->with('success', 'Tus datos de contacto fueron actualizados.');
    }

    /** Agrega campos de presentación y reglas de negocio visibles (próxima, cancelable). */
    private function decorar(array $citas)
    {
        return collect($citas)->map(function (array $c) {
            $inicio = Carbon::parse($c['fecha'].' '.$c['horaInicio'])->locale('es');
            $activa = in_array($c['estado'], self::ESTADOS_ACTIVOS, true);

            return $c + [
                'inicio' => $inicio,
                'fechaTexto' => ucfirst($inicio->isoFormat('dddd D [de] MMMM')),
                'horaTexto' => $inicio->format('H:i').' – '.Carbon::parse($c['fecha'].' '.$c['horaFin'])->format('H:i'),
                'esProxima' => $activa && ($inicio->gte(now()) || $c['estado'] === 'En Espera'),
                'cancelable' => in_array($c['estado'], self::ESTADOS_CANCELABLES, true) && $inicio->gte(now()->addDay()),
                'requiereReprogramacion' => $c['estado'] === 'Afectada/Pendiente Reprogramación',
                'estadoTexto' => match ($c['estado']) {
                    'Confirmada' => 'Confirmada',
                    'En Espera' => 'En espera',
                    'Atendida' => 'Atendida',
                    'Cancelada' => 'Cancelada',
                    'No Asistió' => 'No asistió',
                    'Afectada/Pendiente Reprogramación' => 'Requiere reprogramación',
                    'Reprogramada' => 'Reprogramada',
                    default => $c['estado'],
                },
                'estadoClase' => match ($c['estado']) {
                    'Confirmada' => 'confirmada',
                    'En Espera' => 'espera',
                    'Atendida' => 'atendida',
                    'Cancelada' => 'cancelada',
                    'No Asistió' => 'noasistio',
                    'Afectada/Pendiente Reprogramación' => 'afectada',
                    'Reprogramada' => 'reprogramada',
                    default => 'atendida',
                },
            ];
        });
    }
}
