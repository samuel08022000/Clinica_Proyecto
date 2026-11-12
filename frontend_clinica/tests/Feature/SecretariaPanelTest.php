<?php

namespace Tests\Feature;

use App\Support\AppointmentPresentation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReceptionFixtures as F;
use Tests\TestCase;

class SecretariaPanelTest extends TestCase
{
    private ?\Closure $override = null;

    private string $role = 'ROL002';

    private ?int $authStatus = null;

    private bool $offline = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-12 15:00:00', 'America/La_Paz'));
        getenv('CLINICA_RECEPTION_SNAPSHOTS') === '1' ? $this->withVite() : $this->withoutVite();
        config(['services.quarkus.base_url' => 'http://localhost:8080/api/v1']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = str_replace('/api/v1', '', parse_url($request->url(), PHP_URL_PATH));
            if ($path === '/auth/authorize') {
                if ($this->offline) {
                    throw new ConnectionException('down');
                }

                return $this->authStatus ? Http::response(['error' => ['code' => $this->authStatus === 403 ? 'FORBIDDEN' : 'INVALID_SESSION']], $this->authStatus) : Http::response(F::authorization($this->role));
            }
            if ($this->override && ($response = ($this->override)($request, $path)) !== null) {
                return $response;
            }
            if ($request->method() === 'GET') {
                return Http::response(F::get($path));
            }

            return match ($path) {
                '/secretaria/citas' => Http::response(F::appointment(), 201),
                '/secretaria/checkin' => Http::response(F::attendance(['estado' => 'En Espera', 'checkinRegistrado' => true, 'horaLlegadaCheckin' => '2026-11-12T09:00:00-04:00', 'checkinPorUsu' => 'USU000002'])),
                '/secretaria/citas/CIT00000001/cancelar' => Http::response(['codCit' => 'CIT00000001', 'estado' => 'Cancelada', 'motivoCancelacion' => 'Solicitud del paciente', 'canceladoPorUsu' => 'USU000002', 'canceladoEn' => '2026-11-12T08:00:00-04:00', 'cancelada' => true]),
                '/secretaria/citas/CIT00000001/inasistencia' => Http::response(['codCit' => 'CIT00000001', 'estado' => 'No Asistió', 'codInc' => 'INC00000001', 'registradoPorUsu' => 'USU000002', 'fechaRegistro' => '2026-11-12T15:00:00-04:00', 'motivo' => 'Sin llegada al finalizar el turno', 'inasistenciaRegistrada' => true]),
                default => throw new \LogicException('Unexpected write '.$path),
            };
        });
    }

    private function secretary(): static
    {
        return $this->withSession(['auth_token' => 'opaque-secretary-test', 'cod_usu' => 'USU000002', 'cod_rol' => 'ROL002']);
    }

    private function snapshot(string $name, TestResponse $response): void
    {
        if (getenv('CLINICA_RECEPTION_SNAPSHOTS') !== '1') {
            return;
        }
        $directory = storage_path('app/private/fase11/snapshots');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/'.$name.'.html', preg_replace('~(?:https?://[^/\s"\x27]+)?/build/~', '/frontend_clinica/public/build/', $response->getContent()));
    }

    public static function screens(): array
    {
        return [
            'dashboard' => ['/secretaria/inicio', 'Panel de Secretaría'],
            'pacientes-inicial' => ['/secretaria/pacientes', 'Busca un paciente'],
            'pacientes' => ['/secretaria/pacientes?q=Elena', 'Elena Vargas'],
            'paciente-detalle' => ['/secretaria/pacientes/PAC000001', 'Perfil administrativo'],
            'reserva-busqueda' => ['/secretaria/reservas/nueva?q=Elena', 'Seleccionar paciente'],
            'reserva-paciente' => ['/secretaria/reservas/nueva?codPac=PAC000001', 'Buscar disponibilidad'],
            'disponibilidad' => ['/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-12', '16:00'],
            'confirmacion' => ['/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-12&codSlot=SLT00000001', 'Confirmar reserva'],
            'citas' => ['/secretaria/citas', 'Gestión de citas'],
            'cita-detalle' => ['/secretaria/citas/CIT00000001', 'Detalle de la reserva'],
            'cancelacion' => ['/secretaria/citas/CIT00000001/cancelar', 'Motivo de cancelación'],
            'inasistencia' => ['/secretaria/citas/CIT00000001/inasistencia', 'Confirmo el registro'],
            'checkin' => ['/secretaria/checkin', 'Contenido del QR'],
            'asistencia' => ['/secretaria/citas/CIT00000001/asistencia', 'Sin registro'],
            'acreditacion' => ['/secretaria/citas/CIT00000001/acreditacion', 'Admite check-in'],
            'historial-busqueda' => ['/secretaria/historial?q=Elena', 'Localiza al paciente'],
            'historial' => ['/secretaria/pacientes/PAC000001/historial', 'Historial administrativo'],
        ];
    }

    #[DataProvider('screens')]
    public function test_screens_render_real_contracts_and_shared_components(string $path, string $text): void
    {
        $response = $this->secretary()->get($path)->assertOk()->assertSee($text)->assertSee('Ángela Secretaria')
            ->assertSee('Proyecto Clínica')->assertSee('<h1', false)->assertSee('id="contenido-principal"', false)
            ->assertDontSee('opaque-secretary-test')->assertDontSee('administracion/')->assertDontSee('@endsection')
            ->assertHeader('Cache-Control', 'no-store, private');
        $name = array_search([$path, $text], self::screens(), true);
        $this->snapshot($name, $response);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer opaque-secretary-test'));
    }

    public function test_dashboard_uses_backend_clinical_default_and_never_claims_a_global_total(): void
    {
        $this->secretary()->get('/secretaria/inicio')->assertOk()->assertSee('3 citas mostradas en esta página');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/secretaria/citas?') && $r->data() === ['page' => 0, 'size' => 20]);
        if (getenv('CLINICA_RECEPTION_SNAPSHOTS') === '1') {
            $endpoints = ['/secretaria/pacientes', '/secretaria/pacientes/PAC000001', '/secretaria/especialidades', '/secretaria/medicos', '/secretaria/disponibilidad', '/secretaria/citas', '/secretaria/citas/CIT00000001', '/secretaria/citas/CIT00000001/asistencia', '/secretaria/citas/CIT00000001/acreditacion', '/secretaria/pacientes/PAC000001/historial'];
            file_put_contents(storage_path('app/private/fase11/browser-fixture.json'), json_encode(['authorization' => F::authorization(), 'data' => array_combine($endpoints, array_map(F::get(...), $endpoints))], JSON_THROW_ON_ERROR));
        }
    }

    public function test_all_secretary_routes_require_session(): void
    {
        foreach (self::screens() as [$path]) {
            $this->get($path)->assertRedirect(route('login'));
        }
        foreach (['/secretaria/reservas', '/secretaria/checkin', '/secretaria/citas/CIT00000001/cancelar', '/secretaria/citas/CIT00000001/inasistencia'] as $path) {
            $this->post($path)->assertRedirect(route('login'));
        }
        Http::assertNothingSent();
    }

    public static function deniedRoles(): array
    {
        return [['ROL001'], ['ROL003'], ['ROL004']];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_roles_cannot_read_or_write_any_reception_module(string $role): void
    {
        $this->role = $role;
        foreach (self::screens() as [$path]) {
            $this->secretary()->get($path)->assertForbidden();
        }
        foreach (['/secretaria/reservas', '/secretaria/checkin', '/secretaria/citas/CIT00000001/cancelar', '/secretaria/citas/CIT00000001/inasistencia'] as $path) {
            $this->post($path)->assertForbidden();
        }
        Http::assertNotSent(fn (Request $r) => ! str_contains($r->url(), '/auth/authorize'));
    }

    public function test_remote_authorization_receives_only_secretary_role_and_denial_preserves_session(): void
    {
        $this->authStatus = 403;
        $this->secretary()->get('/secretaria/inicio')->assertForbidden()->assertSessionHas('auth_token');
        Http::assertSent(fn (Request $r) => $r['roles'] === 'ROL002');
    }

    public function test_revoked_or_invalid_bearer_invalidates_session(): void
    {
        $this->authStatus = 401;
        $this->secretary()->post('/secretaria/checkin', ['tokenQr' => 'qr-private'])->assertRedirect(route('login'))->assertSessionMissing('auth_token');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/secretaria/checkin'));
    }

    public function test_authorization_outage_fails_closed_without_destroying_session(): void
    {
        $this->offline = true;
        $response = $this->secretary()->post('/secretaria/checkin', ['tokenQr' => 'qr-private'])->assertStatus(503)->assertSessionHas('auth_token')->assertSee('Tu sesión se conserva')->assertDontSee('qr-private');
        $this->snapshot('servicio-caido', $response);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/secretaria/'));
    }

    public static function searchTerms(): array
    {
        return [['1234567'], ['Elena'], ['elena@example.test'], ['María']];
    }

    #[DataProvider('searchTerms')]
    public function test_patient_search_sends_ci_name_or_email_without_extra_queries(string $q): void
    {
        $this->secretary()->get('/secretaria/pacientes?'.http_build_query(['q' => $q]))->assertOk()->assertSee('Elena Vargas');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/secretaria/pacientes?') && $r->data() === ['q' => $q]);
        Http::assertSentCount(2);
    }

    public function test_empty_search_never_requests_the_entire_patient_catalog(): void
    {
        $this->secretary()->get('/secretaria/pacientes?q=')->assertOk()->assertSee('Busca un paciente');
        Http::assertSentCount(1);
    }

    public static function invalidQueries(): array
    {
        return [['q=ab'], ['q=...'], ['q='.str_repeat('a', 101)], ['q[]=Elena']];
    }

    #[DataProvider('invalidQueries')]
    public function test_patient_search_respects_backend_limits(string $query): void
    {
        $this->secretary()->get('/secretaria/pacientes?'.$query)->assertStatus(422)->assertSee('Corregir consulta');
        Http::assertSentCount(1);
    }

    public function test_no_matches_and_backend_oversized_search_are_explained(): void
    {
        $this->override = fn () => Http::response(['pacientes' => []]);
        $this->snapshot('pacientes-vacio', $this->secretary()->get('/secretaria/pacientes?q=Elena')->assertOk()->assertSee('No se encontraron pacientes'));
        $this->override = fn () => Http::response(['mensaje' => 'La búsqueda supera 20 pacientes. Precise el documento.'], 400);
        $this->get('/secretaria/pacientes?q=Elena')->assertStatus(400)->assertSee('Precise el documento');
    }

    public function test_disabled_patient_cannot_start_a_reservation(): void
    {
        $this->override = fn (Request $r, string $path) => $path === '/secretaria/pacientes/PAC000001' ? Http::response(F::patient(['habilitadoParaReservar' => false, 'motivoInhabilitacion' => 'SANCION_VIGENTE'])) : null;
        $this->secretary()->get('/secretaria/pacientes/PAC000001')->assertOk()->assertSee('SANCION_VIGENTE')->assertDontSee('href="'.route('secretaria.reservas.create', ['codPac' => 'PAC000001']).'"', false);
        $this->snapshot('paciente-restringido', $this->get('/secretaria/reservas/nueva?codPac=PAC000001')->assertOk()->assertSee('no está habilitado')->assertDontSee('Consultar turnos'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/especialidades') || str_contains($r->url(), '/disponibilidad'));
    }

    public function test_reservation_filters_doctors_and_excludes_patient_overlaps(): void
    {
        $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&codMed=MED000001&fecha=2026-11-12')->assertOk()->assertSee('16:00');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/secretaria/medicos?') && $r['codEsp'] === 'ESP001');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/disponibilidad?') && $r->data() === ['codPac' => 'PAC000001', 'codEsp' => 'ESP001', 'codMed' => 'MED000001', 'fecha' => '2026-11-12']);
    }

    public static function emptyReservationCatalogs(): array
    {
        return [['especialidades', 'No hay especialidades disponibles'], ['medicos', 'No hay médicos habilitados'], ['disponibilidad', 'No hay turnos disponibles']];
    }

    #[DataProvider('emptyReservationCatalogs')]
    public function test_empty_reservation_catalogs_keep_the_recovery_path(string $catalog, string $message): void
    {
        $this->override = fn (Request $r, string $path) => $path === '/secretaria/'.$catalog ? Http::response([]) : null;
        $response = $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-12')->assertOk()->assertSee($message)->assertDontSee('Confirmar reserva</button>', false);
        if ($catalog === 'disponibilidad') {
            $this->snapshot('sin-turnos', $response);
        }
    }

    public function test_slot_confirmation_comes_from_fresh_availability_and_contains_exact_summary(): void
    {
        $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-12&codSlot=SLT00000001&nombrePaciente=Intruso')
            ->assertOk()->assertSee('Elena Vargas')->assertSee('Lucía Flores')->assertSee('16:00–16:30')->assertDontSee('Intruso')->assertSee('name="codSlot" value="SLT00000001"', false);
    }

    public function test_unavailable_selected_slot_returns_to_selection(): void
    {
        $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-12&codSlot=SLTINVALID')
            ->assertOk()->assertSee('ya no está disponible')->assertDontSee('Confirmar reserva</button>', false);
    }

    public function test_reservation_sends_only_patient_and_slot_and_uses_server_success(): void
    {
        $this->secretary()->post('/secretaria/reservas', ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001', 'confirmacion' => 1, 'codSec' => 'INTRUSO', 'canalReserva' => 'Web', 'estado' => 'Atendida'])
            ->assertRedirect(route('secretaria.citas.show', 'CIT00000001'))->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001']);
        $this->snapshot('reserva-exitosa', $this->withCookie(config('session.cookie'), session()->getId())->get('/secretaria/citas/CIT00000001')->assertOk()->assertSee('Reserva confirmada')->assertSee('Ventanilla')->assertSee('Confirmada'));
    }

    public function test_reservation_conflict_refreshes_slots_and_preserves_safe_context(): void
    {
        $this->override = fn (Request $r) => $r->method() === 'POST' ? Http::response(['mensaje' => 'El cupo ya no está disponible.'], 409) : null;
        $input = ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001', 'codEsp' => 'ESP001', 'fecha' => '2026-11-12', 'confirmacion' => 1];
        $response = $this->secretary()->post('/secretaria/reservas', $input)->assertRedirect()->assertSessionHasErrors('api')->assertSessionHas('api_status', 409)->assertSessionMissing('success');
        $target = $response->headers->get('Location');
        $this->assertStringNotContainsString('codSlot', $target);
        $this->snapshot('reserva-conflicto', $this->withCookie(config('session.cookie'), session()->getId())->get($target)->assertOk()->assertSee('El cupo ya no está disponible')->assertSee('16:00'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/disponibilidad?'));
    }

    public function test_patient_restriction_is_still_enforced_on_direct_post(): void
    {
        $this->override = fn () => Http::response(['mensaje' => 'El paciente no está habilitado.'], 403);
        $this->secretary()->post('/secretaria/reservas', ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001', 'confirmacion' => 1])->assertForbidden()->assertSessionMissing('success');
    }

    public function test_date_hints_use_server_clinic_zone_and_api_owns_d_plus_14(): void
    {
        $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001')->assertOk()->assertSee('min="2026-11-12"', false)->assertSee('max="2026-11-26"', false);
        $this->override = fn (Request $r, string $path) => $path === '/secretaria/disponibilidad' ? Http::response(['mensaje' => 'La fecha debe estar entre hoy y D+14 según la zona clínica.'], 400) : null;
        $this->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-27')->assertOk()->assertSee('D+14')->assertDontSee('Seleccionar turno');
        Http::assertSent(fn (Request $r) => ($r['fecha'] ?? null) === '2026-11-27');
    }

    public function test_citas_filters_and_pagination_are_forwarded_without_invented_fields(): void
    {
        $this->override = fn (Request $r, string $path) => $path === '/secretaria/citas' ? Http::response(F::page(['page' => 2, 'size' => 10, 'hayMas' => true])) : null;
        $filters = ['desde' => '2026-11-01', 'hasta' => '2026-11-12', 'estado' => 'Confirmada', 'codPac' => 'PAC000001', 'codMed' => 'MED000001', 'page' => 2, 'size' => 10];
        $this->secretary()->get('/secretaria/citas?'.http_build_query($filters + ['rol' => 'ROL004']))->assertOk()->assertSee('Página 3')->assertSee('Anterior')->assertSee('Siguiente');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/secretaria/citas?') && $r->data() == $filters);
    }

    public static function invalidFilters(): array
    {
        return [['fecha=2026-11-12&desde=2026-11-01'], ['size=51'], ['page=-1'], ['page=10001'], ['estado=Inventado'], ['desde=2026-11-12&hasta=2026-11-01'], ['fecha=2026-02-30']];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_before_clinical_api(string $query): void
    {
        $this->secretary()->get('/secretaria/citas?'.$query)->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_all_seven_backend_states_are_preserved_in_display(): void
    {
        $rows = array_map(fn ($state) => F::page()['citas'][0] + [], AppointmentPresentation::STATES);
        foreach ($rows as $i => &$row) {
            $row['estado'] = AppointmentPresentation::STATES[$i];
        }
        $this->override = fn () => Http::response(F::page(['citas' => $rows]));
        $response = $this->secretary()->get('/secretaria/citas')->assertOk();
        foreach (AppointmentPresentation::STATES as $state) {
            $response->assertSee($state);
        }
    }

    public function test_checkin_sends_token_only_in_post_body_then_redirects_without_it(): void
    {
        $this->secretary()->post('/secretaria/checkin', ['tokenQr' => 'qr-authorized-secret', 'codSec' => 'INTRUSO'])->assertRedirect(route('secretaria.citas.asistencia', 'CIT00000001'))->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['tokenQr' => 'qr-authorized-secret'] && ! str_contains($r->url(), 'qr-authorized-secret'));
        $this->assertStringNotContainsString('qr-authorized-secret', json_encode(session()->all()));
        $this->override = fn (Request $r, string $path) => str_ends_with($path, '/asistencia') ? Http::response(F::attendance(['estado' => 'En Espera', 'checkinRegistrado' => true, 'horaLlegadaCheckin' => '2026-11-12T09:00:00-04:00', 'checkinPorUsu' => 'USU000002'])) : null;
        $this->snapshot('checkin-exitoso', $this->withCookie(config('session.cookie'), session()->getId())->get('/secretaria/citas/CIT00000001/asistencia')->assertOk()->assertSee('En Espera')->assertSee('Llegada acreditada')->assertDontSee('qr-authorized-secret'));
    }

    public static function qrFailures(): array
    {
        return ['empty' => [null, 0], 'invalid' => ['invalid-content', 400], 'signature' => ['forged-signature', 422], 'other-day' => ['future-qr', 409], 'duplicate' => ['already-checked', 409], 'missing' => ['nonexistent', 404], 'offline' => ['offline-qr', 503]];
    }

    #[DataProvider('qrFailures')]
    public function test_qr_errors_never_echo_or_flash_credentials(?string $token, int $status): void
    {
        $this->override = fn () => Http::response(['mensaje' => 'No se admite check-in: '.$token.' HMAC signature invalid.'], $status ?: 400);
        $response = $this->secretary()->from('/secretaria/checkin')->post('/secretaria/checkin', ['tokenQr' => $token]);
        $status === 404 ? $response->assertNotFound() : $response->assertRedirect('/secretaria/checkin')->assertSessionHasErrors('tokenQr');
        $this->assertArrayNotHasKey('tokenQr', session('_old_input', []));
        if ($token) {
            $this->assertStringNotContainsString($token, json_encode(session()->all()));
            $response->assertDontSee($token);
        }
        if (! $status || in_array($status, [400, 409, 422], true)) {
            $this->assertStringNotContainsString('HMAC', json_encode(session()->all()));
        }
    }

    public function test_qr_validation_screen_has_inline_error_and_cleared_input(): void
    {
        $this->secretary()->from('/secretaria/checkin')->post('/secretaria/checkin', ['tokenQr' => ''])->assertSessionHasErrors('tokenQr');
        $this->snapshot('qr-invalido', $this->withCookie(config('session.cookie'), session()->getId())->get('/secretaria/checkin')->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('name="tokenQr" id="tokenQr" value=""', false));
    }

    public function test_cancel_sends_only_reason_and_displays_returned_audit_result(): void
    {
        $this->secretary()->post('/secretaria/citas/CIT00000001/cancelar', ['motivo' => 'Solicitud del paciente', 'confirmacion' => 1, 'estado' => 'Atendida', 'codSec' => 'INTRUSO'])->assertRedirect(route('secretaria.citas.show', 'CIT00000001'))->assertSessionHas('appointment_result.estado', 'Cancelada');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['motivo' => 'Solicitud del paciente']);
        $this->withCookie(config('session.cookie'), session()->getId())->get('/secretaria/citas/CIT00000001')->assertOk()->assertSee('Solicitud del paciente')->assertSee('USU000002');
    }

    public function test_absence_sends_empty_object_and_displays_backend_incidence(): void
    {
        $this->secretary()->post('/secretaria/citas/CIT00000001/inasistencia', ['confirmacion' => 1, 'fecha' => '2000-01-01', 'codSec' => 'INTRUSO'])->assertRedirect(route('secretaria.citas.show', 'CIT00000001'))->assertSessionHas('appointment_result.codInc', 'INC00000001');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->body() === '{}');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/secretaria/citas/CIT00000001')->assertOk()->assertSee('INC00000001')->assertSee('No Asistió');
    }

    public static function transitionConflicts(): array
    {
        return [['cancelar', 'La cita ya está cancelada.'], ['cancelar', 'La cita tiene llegada previa.'], ['inasistencia', 'El turno aún no finalizó.'], ['inasistencia', 'Existe un check-in registrado.'], ['inasistencia', 'La inasistencia ya fue registrada.']];
    }

    #[DataProvider('transitionConflicts')]
    public function test_transition_conflicts_are_safe_and_preserve_reason(string $action, string $message): void
    {
        $this->override = fn (Request $r) => $r->method() === 'POST' ? Http::response(['mensaje' => $message], 409) : null;
        $path = '/secretaria/citas/CIT00000001/'.$action;
        $this->secretary()->from($path)->post($path, ['motivo' => 'Solicitud del paciente', 'confirmacion' => 1])->assertRedirect($path)->assertSessionHasErrors('api')->assertSessionHas('api_status', 409)->assertSessionHasInput('motivo', 'Solicitud del paciente');
        $this->withCookie(config('session.cookie'), session()->getId())->get($path)->assertOk()->assertSee($message);
    }

    public static function incompatibleAppointments(): array
    {
        return [['Cancelada', false], ['En Espera', true], ['Atendida', true], ['Reprogramada', false], ['No Asistió', false], ['Confirmada', true]];
    }

    #[DataProvider('incompatibleAppointments')]
    public function test_incompatible_transitions_have_no_active_submit(string $state, bool $checkin): void
    {
        $this->override = fn (Request $r, string $path) => match ($path) {
            '/secretaria/citas/CIT00000001' => Http::response(F::appointment(['estado' => $state])),
            '/secretaria/citas/CIT00000001/asistencia' => Http::response(F::attendance(['checkinRegistrado' => $checkin])), default => null,
        };
        $this->secretary()->get('/secretaria/citas/CIT00000001/cancelar')->assertOk()->assertDontSee('Confirmar cancelación</button>', false);
        $this->get('/secretaria/citas/CIT00000001/inasistencia')->assertOk()->assertDontSee('data-admin-form', false);
    }

    public function test_future_appointment_hides_absence_without_invented_tolerance(): void
    {
        $this->override = fn (Request $r, string $path) => $path === '/secretaria/citas/CIT00000001' ? Http::response(F::appointment(['horaFin' => '16:00:00'])) : null;
        $this->secretary()->get('/secretaria/citas/CIT00000001/inasistencia')->assertOk()->assertSee('No se puede registrar')->assertDontSee('data-admin-form', false);
    }

    public static function invalidForms(): array
    {
        return [['/secretaria/reservas', ['codPac' => 'PAC000001', 'confirmacion' => 1], 'codSlot'], ['/secretaria/reservas', ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001'], 'confirmacion'], ['/secretaria/citas/CIT00000001/cancelar', ['confirmacion' => 1], 'motivo'], ['/secretaria/citas/CIT00000001/cancelar', ['motivo' => str_repeat('x', 2001), 'confirmacion' => 1], 'motivo'], ['/secretaria/citas/CIT00000001/inasistencia', [], 'confirmacion']];
    }

    #[DataProvider('invalidForms')]
    public function test_invalid_mutations_never_reach_quarkus(string $path, array $input, string $field): void
    {
        $this->secretary()->post($path, $input)->assertRedirect()->assertSessionHasErrors($field);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_patient_history_forwards_only_allowed_filters_and_real_pagination(): void
    {
        $filters = ['desde' => '2026-10-01', 'hasta' => '2026-11-12', 'estado' => 'Cancelada', 'page' => 1, 'size' => 15];
        $this->override = fn (Request $r, string $path) => str_ends_with($path, '/historial') ? Http::response(F::page(['page' => 1, 'size' => 15, 'hayMas' => true])) : null;
        $this->secretary()->get('/secretaria/pacientes/PAC000001/historial?'.http_build_query($filters + ['codMed' => 'INTRUSO']))->assertOk()->assertSee('Página 2')->assertSee('Siguiente');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/historial?') && $r->data() === $filters);
    }

    public function test_empty_history_is_not_a_missing_patient(): void
    {
        $this->override = fn (Request $r, string $path) => str_ends_with($path, '/historial') ? Http::response(F::page(['citas' => []])) : null;
        $this->snapshot('historial-vacio', $this->secretary()->get('/secretaria/pacientes/PAC000001/historial')->assertOk()->assertSee('Elena Vargas')->assertSee('No hay citas'));
    }

    public function test_sensitive_fields_in_upstream_dto_are_not_rendered(): void
    {
        $this->override = fn (Request $r, string $path) => $path === '/secretaria/pacientes/PAC000001' ? Http::response(F::patient() + ['passwordHash' => 'hash-private', 'tokenQr' => 'qr-private', 'diagnostico' => 'diagnosis-private']) : null;
        $this->secretary()->get('/secretaria/pacientes/PAC000001')->assertOk()->assertDontSee('hash-private')->assertDontSee('qr-private')->assertDontSee('diagnosis-private');
    }

    public static function readErrors(): array
    {
        return [[400], [403], [404], [409], [422], [423], [500], [502], [503]];
    }

    #[DataProvider('readErrors')]
    public function test_recoverable_read_errors_do_not_destroy_session(int $status): void
    {
        $this->override = fn () => Http::response(['mensaje' => 'Conflicto administrativo seguro', 'sql' => 'INTERNAL-SQL'], $status);
        $this->secretary()->get('/secretaria/pacientes/PAC000001')->assertStatus($status >= 500 ? 503 : $status)->assertSee('No se pudo completar')->assertDontSee('INTERNAL-SQL')->assertSessionHas('auth_token');
    }

    public function test_revocation_after_role_validation_clears_session(): void
    {
        $this->override = fn () => Http::response([], 401);
        $this->secretary()->get('/secretaria/citas')->assertRedirect(route('login'))->assertSessionMissing('auth_token');
    }

    public function test_connection_failure_and_malformed_json_are_recoverable(): void
    {
        $this->override = fn () => throw new ConnectionException('private-connection-detail');
        $this->secretary()->get('/secretaria/citas')->assertStatus(503)->assertSessionHas('auth_token')->assertDontSee('private-connection-detail');
        $this->override = fn () => Http::response('not-json');
        $this->get('/secretaria/citas')->assertStatus(502)->assertSessionHas('auth_token');
    }

    public function test_unknown_write_outcome_is_not_reported_as_success_or_automatically_retried(): void
    {
        $attempts = 0;
        $this->override = function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('timeout');
        };
        $this->secretary()->post('/secretaria/reservas', ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001', 'confirmacion' => 1])->assertRedirect()->assertSessionHasErrors('api')->assertSessionMissing('success')->assertSessionHas('auth_token');
        $this->assertStringContainsString('Consulta las citas', session('errors')->first('api'));
        // ConnectionException attempts are not retained in Laravel's fake request log.
        Http::assertSentCount(1);
        $this->assertSame(1, $attempts);
    }

    public static function invalidWriteResponses(): array
    {
        return [['/secretaria/reservas', ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001', 'confirmacion' => 1]],
            ['/secretaria/checkin', ['tokenQr' => 'qr-private']], ['/secretaria/citas/CIT00000001/cancelar', ['motivo' => 'Solicitud del paciente', 'confirmacion' => 1]],
            ['/secretaria/citas/CIT00000001/inasistencia', ['confirmacion' => 1]]];
    }

    #[DataProvider('invalidWriteResponses')]
    public function test_success_status_with_invalid_dto_does_not_claim_success(string $path, array $input): void
    {
        $this->override = fn () => Http::response(['codCit' => 'CIT00000001', 'estado' => 'Unexpected'], 200);
        $this->secretary()->from('/secretaria/checkin')->post($path, $input)->assertRedirect()->assertSessionMissing('success')->assertSessionHasErrors();
        $this->assertStringNotContainsString('qr-private', json_encode(session()->all()));
    }

    public function test_csrf_is_enforced_for_every_reception_mutation(): void
    {
        $this->app['env'] = 'production';
        foreach (['/secretaria/reservas', '/secretaria/checkin', '/secretaria/citas/CIT00000001/cancelar', '/secretaria/citas/CIT00000001/inasistencia'] as $path) {
            $this->secretary()->post($path)->assertStatus(419);
        }
        Http::assertNothingSent();
        $this->withSession(['_token' => 'csrf-test'])->post('/secretaria/checkin', ['_token' => 'csrf-test', 'tokenQr' => 'valid-test-qr'])->assertRedirect()->assertSessionHas('success');
    }
}
