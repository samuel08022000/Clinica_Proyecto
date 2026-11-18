<?php

namespace Tests\Feature;

use App\Exceptions\AuthDomainException;
use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Auth\AccountStatusService;
use App\Services\Notifications\AppointmentNotificationService;
use App\Services\Reception\ReceptionService;
use App\Support\AppointmentPresentation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalVisualSnapshots;
use Tests\Support\ReceptionFixtures as F;
use Tests\TestCase;

class SecretariaPanelTest extends TestCase
{
    private ?\Closure $override = null;

    private string $role = 'ROL002';

    private ?int $authStatus = null;

    private bool $offline = false;

    /** @var array<int, array{method: string, path: string, data: array}> */
    private array $recorded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-12 15:00:00', 'America/La_Paz'));
        $this->withoutVite();
        $notifications = $this->partialMock(AppointmentNotificationService::class);
        $notifications->shouldReceive('sendConfirmation')->byDefault()->andReturn('Pendiente');

        $accounts = Mockery::mock(AccountStatusService::class);
        $accounts->shouldReceive('requireActive')->andReturnUsing(function (string $userId): object {
            if ($this->offline) {
                throw new QueryException('clinical', 'SELECT 1', [], new \PDOException('SQLSTATE[08006] down'));
            }
            if ($this->authStatus === 401) {
                throw new AuthDomainException('ACCOUNT_NOT_FOUND', 401, 'La cuenta ya no está disponible.');
            }
            if ($this->authStatus === 403) {
                throw new AuthDomainException('ROLE_DISABLED', 403, 'Acceso denegado.');
            }

            return (object) [
                'cod_usu' => $userId,
                'nombre_completo' => 'Ángela Secretaria',
                'email' => 'angela@example.test',
                'cod_rol' => $this->role,
                'nombre_rol' => 'Secretaria',
                'usuario_estado' => 'Activo',
                'rol_estado' => 'Activo',
                'perfil_presente' => true,
                'perfil_estado' => 'Activo',
                'email_verificado' => true,
                'bloqueado_hasta' => null,
            ];
        });
        $this->app->instance(AccountStatusService::class, $accounts);

        $reception = Mockery::mock(ReceptionService::class);
        $reception->shouldReceive('request')->andReturnUsing(function (string $method, string $path, array $data = []): array {
            $normalizedPath = '/'.ltrim($path, '/');
            $fixtureKey = str_starts_with($normalizedPath, '/derivaciones')
                ? $normalizedPath
                : '/secretaria'.$normalizedPath;
            $this->recorded[] = ['method' => $method, 'path' => $fixtureKey, 'data' => $data];

            if ($this->override && ($result = ($this->override)($method, $fixtureKey, $data)) !== null) {
                return $result;
            }
            if ($method === 'GET') {
                return match ($fixtureKey) {
                    '/derivaciones' => ['derivaciones' => [self::referral()], 'page' => 0, 'size' => 20, 'hayMas' => false],
                    '/derivaciones/DER00000001' => self::referral(),
                    default => F::get($fixtureKey),
                };
            }

            return match ($fixtureKey) {
                '/secretaria/citas' => F::appointment(),
                '/secretaria/checkin' => F::attendance(['estado' => 'En Espera', 'checkinRegistrado' => true, 'horaLlegadaCheckin' => '2026-11-12T09:00:00-04:00', 'checkinPorUsu' => 'USU000002']),
                '/secretaria/citas/CIT00000001/cancelar' => ['codCit' => 'CIT00000001', 'estado' => 'Cancelada', 'motivoCancelacion' => 'Solicitud del paciente', 'canceladoPorUsu' => 'USU000002', 'canceladoEn' => '2026-11-12T08:00:00-04:00', 'cancelada' => true],
                '/secretaria/citas/CIT00000001/inasistencia' => ['codCit' => 'CIT00000001', 'estado' => 'No Asistió', 'codInc' => 'INC00000001', 'registradoPorUsu' => 'USU000002', 'fechaRegistro' => '2026-11-12T15:00:00-04:00', 'motivo' => 'Sin llegada al finalizar el turno', 'inasistenciaRegistrada' => true],
                default => throw new \LogicException('Unexpected write '.$fixtureKey),
            };
        });
        $this->app->instance(ReceptionService::class, $reception);
    }

    private static function referral(array $overrides = []): array
    {
        return array_replace([
            'codDer' => 'DER00000001',
            'codCitOrigen' => 'CIT00000001',
            'codPac' => 'PAC000001',
            'nombrePaciente' => 'Elena Vargas',
            'codEspDestino' => 'ESP001',
            'nombreEspecialidadDestino' => 'Medicina interna',
            'codMedDestino' => 'MED000001',
            'nombreMedicoDestino' => 'Lucía Flores',
            'nombreMedicoEmisor' => 'Carlos Quispe',
            'nombreMedicoOrigen' => 'Carlos Quispe',
            'motivo' => 'Evaluación complementaria de control clínico.',
            'estado' => 'Pendiente',
            'codCitHija' => null,
            'fechaDerivacion' => '2026-11-12T10:00:00-04:00',
            'fechaRegistro' => '2026-11-12T10:00:00-04:00',
        ], $overrides);
    }

    private function secretary(): static
    {
        $user = new Usuario;
        $user->forceFill([
            'cod_usu' => 'USU000002',
            'cod_rol' => $this->role,
            'nombre_completo' => 'Ángela Secretaria',
            'email' => 'angela@example.test',
            'estado' => 'Activo',
        ]);

        return $this->actingAs($user, 'web')->withSession([
            'auth_authority' => 'laravel',
            'auth_expires_at' => now()->addMinutes(120)->toIso8601String(),
            'cod_usu' => 'USU000002',
            'cod_rol' => $this->role,
            'nombre_usuario' => 'Ángela Secretaria',
            'email_usuario' => 'angela@example.test',
            'nombre_rol' => 'Secretaria',
            'rol_slug' => 'secretaria',
            'estado_cuenta' => 'Activo',
        ]);
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
            'derivaciones' => ['/secretaria/derivaciones', 'Derivaciones'],
            'derivacion-detalle' => ['/secretaria/derivaciones/DER00000001', 'DER00000001'],
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
        $this->secretary()->get($path)->assertOk()->assertSee($text)->assertSee('Ángela Secretaria')
            ->assertSee('Proyecto Clínica')->assertSee('<h1', false)->assertSee('id="contenido-principal"', false)
            ->assertDontSee('administracion/')->assertDontSee('@endsection')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_referral_reservation_locks_specialty_and_destination_doctor_in_form_ui(): void
    {
        $this->secretary()->get('/secretaria/reservas/nueva?codDer=DER00000001&fecha=2026-11-12')
            ->assertOk()
            ->assertSee('Reserva derivada DER00000001')
            ->assertSee('<input type="hidden" name="codEsp" value="ESP001">', false)
            ->assertSee('<input type="hidden" name="codMed" value="MED000001">', false)
            ->assertSee('Especialidad fijada por la derivación DER00000001.')
            ->assertSee('Médico destinatario fijado por la derivación.');
    }

    public function test_derivaciones_index_uses_compact_filter_and_descriptive_links(): void
    {
        $this->secretary()->get('/secretaria/derivaciones')
            ->assertOk()
            ->assertSee('a-filter--compact', false)
            ->assertSee('aria-label="Ver detalle de derivación DER00000001"', false);
    }

    public function test_dashboard_uses_backend_clinical_default_and_never_claims_a_global_total(): void
    {
        $this->secretary()->get('/secretaria/inicio')->assertOk()->assertSee('3 citas mostradas en esta página');
        $citasCall = collect($this->recorded)->firstWhere('path', '/secretaria/citas');
        $this->assertSame(['page' => 0, 'size' => 20], $citasCall['data']);
    }

    public function test_all_secretary_routes_require_session(): void
    {
        foreach (self::screens() as [$path]) {
            $this->get($path)->assertRedirect(route('login'));
        }
        foreach (['/secretaria/reservas', '/secretaria/checkin', '/secretaria/citas/CIT00000001/cancelar', '/secretaria/citas/CIT00000001/inasistencia'] as $path) {
            $this->post($path)->assertRedirect(route('login'));
        }
        $this->assertEmpty($this->recorded);
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
        $this->assertEmpty($this->recorded);
    }

    public function test_role_denial_preserves_authenticated_session(): void
    {
        $this->role = 'ROL001';
        $this->secretary()->get('/secretaria/inicio')->assertForbidden()->assertSessionHas('cod_usu', 'USU000002');
    }

    public function test_disabled_role_in_database_revokes_session(): void
    {
        $this->authStatus = 403;
        $this->secretary()->get('/secretaria/inicio')->assertRedirect(route('login'))->assertSessionMissing('cod_usu');
    }

    public function test_revoked_account_invalidates_session(): void
    {
        $this->authStatus = 401;
        $this->secretary()->post('/secretaria/checkin', ['tokenQr' => 'qr-private'])
            ->assertRedirect(route('login'))->assertSessionMissing('cod_usu');
        $this->assertEmpty($this->recorded);
    }

    public function test_authorization_outage_fails_closed_without_destroying_session(): void
    {
        $this->offline = true;
        $this->secretary()->post('/secretaria/checkin', ['tokenQr' => 'qr-private'])
            ->assertStatus(503)->assertSessionHas('cod_usu', 'USU000002')
            ->assertSee('Tu sesión se conserva')->assertDontSee('qr-private');
        $this->assertEmpty($this->recorded);
    }

    public static function searchTerms(): array
    {
        return [['1234567'], ['Elena'], ['elena@example.test'], ['María']];
    }

    #[DataProvider('searchTerms')]
    public function test_patient_search_sends_ci_name_or_email_without_extra_queries(string $q): void
    {
        $this->secretary()->get('/secretaria/pacientes?'.http_build_query(['q' => $q]))->assertOk()->assertSee('Elena Vargas');
        $this->assertCount(1, $this->recorded);
        $this->assertSame('/secretaria/pacientes', $this->recorded[0]['path']);
        $this->assertSame(['q' => $q], $this->recorded[0]['data']);
    }

    public function test_empty_search_never_requests_the_entire_patient_catalog(): void
    {
        $this->secretary()->get('/secretaria/pacientes?q=')->assertOk()->assertSee('Busca un paciente');
        $this->assertEmpty($this->recorded);
    }

    public static function invalidQueries(): array
    {
        return [['q=ab'], ['q=...'], ['q='.str_repeat('a', 101)], ['q[]=Elena']];
    }

    #[DataProvider('invalidQueries')]
    public function test_patient_search_respects_backend_limits(string $query): void
    {
        $this->secretary()->get('/secretaria/pacientes?'.$query)->assertStatus(422)->assertSee('Corregir consulta');
        $this->assertEmpty($this->recorded);
    }

    public function test_no_matches_and_backend_oversized_search_are_explained(): void
    {
        $this->override = fn () => ['pacientes' => []];
        $this->secretary()->get('/secretaria/pacientes?q=Elena')->assertOk()->assertSee('No se encontraron pacientes');

        $this->override = fn () => throw new ClinicalOperationException(400, 'La búsqueda supera 20 pacientes. Precise el documento.');
        $this->get('/secretaria/pacientes?q=Elena')->assertStatus(400)->assertSee('Precise el documento');
    }

    public function test_disabled_patient_cannot_start_a_reservation(): void
    {
        $this->override = fn (string $m, string $path) => $path === '/secretaria/pacientes/PAC000001'
            ? F::patient(['habilitadoParaReservar' => false, 'motivoInhabilitacion' => 'SANCION_VIGENTE'])
            : null;
        $this->secretary()->get('/secretaria/pacientes/PAC000001')->assertOk()->assertSee('SANCION_VIGENTE')
            ->assertDontSee('href="'.route('secretaria.reservas.create', ['codPac' => 'PAC000001']).'"', false);
        $this->get('/secretaria/reservas/nueva?codPac=PAC000001')->assertOk()->assertSee('no está habilitado')->assertDontSee('Consultar turnos');
        $this->assertEmpty(array_filter($this->recorded, fn ($entry) => str_contains($entry['path'], 'especialidades') || str_contains($entry['path'], 'disponibilidad')));
    }

    public function test_reservation_filters_doctors_and_excludes_patient_overlaps(): void
    {
        $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&codMed=MED000001&fecha=2026-11-12')->assertOk()->assertSee('16:00');
        $medCall = collect($this->recorded)->firstWhere('path', '/secretaria/medicos');
        $dispCall = collect($this->recorded)->firstWhere('path', '/secretaria/disponibilidad');
        $this->assertSame('ESP001', $medCall['data']['codEsp'] ?? null);
        $this->assertSame(['codPac' => 'PAC000001', 'codEsp' => 'ESP001', 'codMed' => 'MED000001', 'fecha' => '2026-11-12'], $dispCall['data']);
    }

    public static function emptyReservationCatalogs(): array
    {
        return [['especialidades', 'No hay especialidades disponibles'], ['medicos', 'No hay médicos habilitados'], ['disponibilidad', 'No hay turnos disponibles']];
    }

    #[DataProvider('emptyReservationCatalogs')]
    public function test_empty_reservation_catalogs_keep_the_recovery_path(string $catalog, string $message): void
    {
        $this->override = fn (string $m, string $path) => $path === '/secretaria/'.$catalog ? [] : null;
        $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-12')
            ->assertOk()->assertSee($message)->assertDontSee('Confirmar reserva</button>', false);
    }

    public function test_slot_confirmation_comes_from_fresh_availability_and_contains_exact_summary(): void
    {
        $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-12&codSlot=SLT00000001&nombrePaciente=Intruso')
            ->assertOk()->assertSee('Elena Vargas')->assertSee('Lucía Flores')->assertSee('16:00–16:30')->assertDontSee('Intruso')->assertSee('name="codSlot" value="SLT00000001"', false);
    }

    public function test_unavailable_selected_slot_returns_to_selection(): void
    {
        $this->override = fn (string $m, string $path) => $path === '/secretaria/disponibilidad' ? [] : null;
        $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-12&codSlot=SLT00000001')
            ->assertOk()->assertSee('ya no está disponible')->assertDontSee('Confirmar reserva</button>', false);
    }

    public function test_reservation_sends_only_patient_and_slot_and_uses_server_success(): void
    {
        $this->secretary()->post('/secretaria/reservas', ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001', 'confirmacion' => 1, 'codSec' => 'INTRUSO', 'canalReserva' => 'Web', 'estado' => 'Atendida'])
            ->assertRedirect(route('secretaria.citas.show', 'CIT00000001'))->assertSessionHas('success');
        $postCall = collect($this->recorded)->firstWhere('method', 'POST');
        $this->assertSame(['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001'], $postCall['data']);
        $this->withCookie(config('session.cookie'), session()->getId())->get('/secretaria/citas/CIT00000001')->assertOk()->assertSee('Reserva confirmada')->assertSee('Ventanilla')->assertSee('Confirmada');
    }

    public function test_reservation_conflict_refreshes_slots_and_preserves_safe_context(): void
    {
        $this->override = function (string $m) {
            if ($m === 'POST') {
                throw new ClinicalOperationException(409, 'El cupo ya no está disponible.');
            }

            return null;
        };
        $input = ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001', 'codEsp' => 'ESP001', 'fecha' => '2026-11-12', 'confirmacion' => 1];
        $response = $this->secretary()->post('/secretaria/reservas', $input)
            ->assertRedirect()->assertSessionHasErrors('api')->assertSessionHas('api_status', 409)->assertSessionMissing('success');
        $target = $response->headers->get('Location');
        $this->assertStringNotContainsString('codSlot', $target);
        $this->override = null;
        $this->withCookie(config('session.cookie'), session()->getId())->get($target)->assertOk()->assertSee('El cupo ya no está disponible')->assertSee('16:00');
    }

    public function test_patient_restriction_is_still_enforced_on_direct_post(): void
    {
        $this->override = fn () => throw new ClinicalOperationException(403, 'El paciente no está habilitado.');
        $this->secretary()->post('/secretaria/reservas', ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001', 'confirmacion' => 1])
            ->assertForbidden()->assertSessionMissing('success');
    }

    public function test_date_hints_use_server_clinic_zone_and_service_owns_d_plus_14(): void
    {
        $this->secretary()->get('/secretaria/reservas/nueva?codPac=PAC000001')->assertOk()
            ->assertSee('min="2026-11-12"', false)->assertSee('max="2026-11-26"', false);
        $this->override = function (string $m, string $path) {
            if ($path === '/secretaria/disponibilidad') {
                throw new ClinicalOperationException(400, 'La fecha debe estar entre hoy y D+14 según la zona clínica.');
            }

            return null;
        };
        $this->get('/secretaria/reservas/nueva?codPac=PAC000001&codEsp=ESP001&fecha=2026-11-27')
            ->assertOk()->assertSee('D+14')->assertDontSee('Seleccionar turno');
    }

    public function test_citas_filters_and_pagination_are_forwarded_without_invented_fields(): void
    {
        $this->override = fn (string $m, string $path) => $path === '/secretaria/citas' ? F::page(['page' => 2, 'size' => 10, 'hayMas' => true]) : null;
        $filters = ['desde' => '2026-11-01', 'hasta' => '2026-11-12', 'estado' => 'Confirmada', 'codPac' => 'PAC000001', 'codMed' => 'MED000001', 'page' => 2, 'size' => 10];
        $this->secretary()->get('/secretaria/citas?'.http_build_query($filters + ['rol' => 'ROL004']))
            ->assertOk()->assertSee('Página 3')->assertSee('Anterior')->assertSee('Siguiente');
        $citasCall = collect($this->recorded)->firstWhere('path', '/secretaria/citas');
        $this->assertEquals($filters, $citasCall['data']);
    }

    public static function invalidFilters(): array
    {
        return [['fecha=2026-11-12&desde=2026-11-01'], ['size=51'], ['page=-1'], ['page=10001'], ['estado=Inventado'], ['desde=2026-11-12&hasta=2026-11-01'], ['fecha=2026-02-30']];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_before_clinical_service(string $query): void
    {
        $this->secretary()->get('/secretaria/citas?'.$query)->assertStatus(422);
        $this->assertEmpty($this->recorded);
    }

    public function test_all_seven_backend_states_and_danger_class_for_cancelled_are_rendered(): void
    {
        $rows = array_map(fn ($state) => F::page()['citas'][0] + [], AppointmentPresentation::STATES);
        foreach ($rows as $i => &$row) {
            $row['estado'] = AppointmentPresentation::STATES[$i];
        }
        $this->override = fn () => F::page(['citas' => $rows]);
        $response = $this->secretary()->get('/secretaria/citas')->assertOk()->assertSee('a-badge--danger', false);
        foreach (AppointmentPresentation::STATES as $state) {
            $response->assertSee($state);
        }
    }

    public function test_camera_controls_and_server_preview_are_available(): void
    {
        ClinicalVisualSnapshots::save('checkin', $this->secretary()->get('/secretaria/checkin')->getContent());
        $this->secretary()->get('/secretaria/checkin')->assertOk()
            ->assertSee('Activar cámara')->assertSee('Detener cámara')
            ->assertSee('data-qr-video', false)->assertSee('data-preview-url', false);
        $this->override = fn ($method, $path) => str_ends_with($path, '/checkin/validar')
            ? ['codCit' => 'CIT00000001', 'codPac' => 'PAC000001', 'nombrePaciente' => 'Paciente de prueba', 'fecha' => '2026-11-12', 'horaInicio' => '09:00', 'estado' => 'Confirmada'] : null;
        $this->postJson('/secretaria/checkin/validar', ['tokenQr' => 'token-privado'])
            ->assertOk()->assertJsonPath('codCit', 'CIT00000001')->assertDontSee('token-privado');
        $this->assertStringNotContainsString('token-privado', json_encode(session()->all()));
    }

    public function test_affected_appointment_has_an_operational_reschedule_screen(): void
    {
        $this->override = fn ($method, $path) => $method === 'GET' && $path === '/secretaria/citas/CIT00000001'
            ? F::appointment(['estado' => 'Afectada/Pendiente Reprogramación']) : null;
        $this->secretary()->get('/secretaria/citas/CIT00000001/reprogramar?fecha=2026-11-13')
            ->assertOk()->assertSee('Confirmar reprogramación')->assertSee('name="codSlot"', false);
        ClinicalVisualSnapshots::save('reprogramar', $this->get('/secretaria/citas/CIT00000001/reprogramar?fecha=2026-11-13')->getContent());
    }

    public function test_checkin_sends_token_only_in_post_body_then_redirects_without_it(): void
    {
        $this->secretary()->post('/secretaria/checkin', ['tokenQr' => 'qr-authorized-secret', 'codSec' => 'INTRUSO'])
            ->assertRedirect(route('secretaria.citas.asistencia', 'CIT00000001'))->assertSessionHas('success');
        $postCall = collect($this->recorded)->firstWhere('method', 'POST');
        $this->assertSame(['tokenQr' => 'qr-authorized-secret'], $postCall['data']);
        $this->assertStringNotContainsString('qr-authorized-secret', json_encode(session()->all()));

        $this->override = fn (string $m, string $path) => str_ends_with($path, '/asistencia')
            ? F::attendance(['estado' => 'En Espera', 'checkinRegistrado' => true, 'horaLlegadaCheckin' => '2026-11-12T09:00:00-04:00', 'checkinPorUsu' => 'USU000002'])
            : null;
        $this->get('/secretaria/citas/CIT00000001/asistencia')->assertOk()
            ->assertSee('En Espera')->assertSee('Llegada acreditada')->assertDontSee('qr-authorized-secret');
    }

    public static function qrFailures(): array
    {
        return ['empty' => [null, 0], 'invalid' => ['invalid-content', 400], 'signature' => ['forged-signature', 422], 'other-day' => ['future-qr', 409], 'duplicate' => ['already-checked', 409], 'missing' => ['nonexistent', 404], 'offline' => ['offline-qr', 503]];
    }

    #[DataProvider('qrFailures')]
    public function test_qr_errors_never_echo_or_flash_credentials(?string $token, int $status): void
    {
        $this->override = fn () => throw new ClinicalOperationException(
            $status ?: 400,
            $status === 404 ? 'Cita no encontrada.' : 'No se admite check-in: HMAC signature invalid.',
        );
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
        $this->withCookie(config('session.cookie'), session()->getId())->get('/secretaria/checkin')->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('name="tokenQr" id="tokenQr" value=""', false);
    }

    public function test_cancel_sends_only_reason_and_displays_returned_audit_result(): void
    {
        $this->secretary()->post('/secretaria/citas/CIT00000001/cancelar', ['motivo' => 'Solicitud del paciente', 'confirmacion' => 1, 'estado' => 'Atendida', 'codSec' => 'INTRUSO'])
            ->assertRedirect(route('secretaria.citas.show', 'CIT00000001'))->assertSessionHas('appointment_result.estado', 'Cancelada');
        $postCall = collect($this->recorded)->firstWhere('method', 'POST');
        $this->assertSame(['motivo' => 'Solicitud del paciente'], $postCall['data']);
        $this->withCookie(config('session.cookie'), session()->getId())->get('/secretaria/citas/CIT00000001')->assertOk()->assertSee('Solicitud del paciente')->assertSee('USU000002');
    }

    public function test_absence_sends_empty_payload_and_displays_backend_incidence(): void
    {
        $this->secretary()->post('/secretaria/citas/CIT00000001/inasistencia', ['confirmacion' => 1, 'fecha' => '2000-01-01', 'codSec' => 'INTRUSO'])
            ->assertRedirect(route('secretaria.citas.show', 'CIT00000001'))->assertSessionHas('appointment_result.codInc', 'INC00000001');
        $postCall = collect($this->recorded)->firstWhere('method', 'POST');
        $this->assertSame([], $postCall['data']);
        $this->withCookie(config('session.cookie'), session()->getId())->get('/secretaria/citas/CIT00000001')->assertOk()->assertSee('INC00000001')->assertSee('No Asistió');
    }

    public static function transitionConflicts(): array
    {
        return [['cancelar', 'La cita ya está cancelada.'], ['cancelar', 'La cita tiene llegada previa.'], ['inasistencia', 'El turno aún no finalizó.'], ['inasistencia', 'Existe un check-in registrado.'], ['inasistencia', 'La inasistencia ya fue registrada.']];
    }

    #[DataProvider('transitionConflicts')]
    public function test_transition_conflicts_are_safe_and_preserve_reason(string $action, string $message): void
    {
        $this->override = function (string $m) use ($message) {
            if ($m === 'POST') {
                throw new ClinicalOperationException(409, $message);
            }

            return null;
        };
        $path = '/secretaria/citas/CIT00000001/'.$action;
        $this->secretary()->from($path)->post($path, ['motivo' => 'Solicitud del paciente', 'confirmacion' => 1])
            ->assertRedirect($path)->assertSessionHasErrors('api')->assertSessionHas('api_status', 409)->assertSessionHasInput('motivo', 'Solicitud del paciente');
        $this->withCookie(config('session.cookie'), session()->getId())->get($path)->assertOk()->assertSee($message);
    }

    public static function incompatibleAppointments(): array
    {
        return [['Cancelada', false], ['En Espera', true], ['Atendida', true], ['Reprogramada', false], ['No Asistió', false], ['Confirmada', true]];
    }

    #[DataProvider('incompatibleAppointments')]
    public function test_incompatible_transitions_have_no_active_submit(string $state, bool $checkin): void
    {
        $this->override = fn (string $m, string $path) => match ($path) {
            '/secretaria/citas/CIT00000001' => F::appointment(['estado' => $state]),
            '/secretaria/citas/CIT00000001/asistencia' => F::attendance(['checkinRegistrado' => $checkin]),
            default => null,
        };
        $this->secretary()->get('/secretaria/citas/CIT00000001/cancelar')->assertOk()->assertDontSee('Confirmar cancelación</button>', false);
        $this->get('/secretaria/citas/CIT00000001/inasistencia')->assertOk()->assertDontSee('data-admin-form', false);
    }

    public function test_future_appointment_hides_absence_without_invented_tolerance(): void
    {
        $this->override = fn (string $m, string $path) => $path === '/secretaria/citas/CIT00000001' ? F::appointment(['horaFin' => '16:00:00']) : null;
        $this->secretary()->get('/secretaria/citas/CIT00000001/inasistencia')->assertOk()->assertSee('No se puede registrar')->assertDontSee('data-admin-form', false);
    }

    public static function invalidForms(): array
    {
        return [['/secretaria/reservas', ['codPac' => 'PAC000001', 'confirmacion' => 1], 'codSlot'], ['/secretaria/reservas', ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001'], 'confirmacion'], ['/secretaria/citas/CIT00000001/cancelar', ['confirmacion' => 1], 'motivo'], ['/secretaria/citas/CIT00000001/cancelar', ['motivo' => str_repeat('x', 2001), 'confirmacion' => 1], 'motivo'], ['/secretaria/citas/CIT00000001/inasistencia', [], 'confirmacion']];
    }

    #[DataProvider('invalidForms')]
    public function test_invalid_mutations_never_reach_clinical_service(string $path, array $input, string $field): void
    {
        $this->secretary()->post($path, $input)->assertRedirect()->assertSessionHasErrors($field);
        $this->assertEmpty(array_filter($this->recorded, fn ($entry) => $entry['method'] !== 'GET'));
    }

    public function test_patient_history_forwards_only_allowed_filters_and_real_pagination(): void
    {
        $filters = ['desde' => '2026-10-01', 'hasta' => '2026-11-12', 'estado' => 'Cancelada', 'page' => 1, 'size' => 15];
        $this->override = fn (string $m, string $path) => str_ends_with($path, '/historial') ? F::page(['page' => 1, 'size' => 15, 'hayMas' => true]) : null;
        $this->secretary()->get('/secretaria/pacientes/PAC000001/historial?'.http_build_query($filters + ['codMed' => 'INTRUSO']))->assertOk()->assertSee('Página 2')->assertSee('Siguiente');
        $historyCall = collect($this->recorded)->first(fn ($entry) => str_ends_with($entry['path'], '/historial'));
        $this->assertEquals($filters, $historyCall['data']);
    }

    public function test_empty_history_is_not_a_missing_patient(): void
    {
        $this->override = fn (string $m, string $path) => str_ends_with($path, '/historial') ? F::page(['citas' => []]) : null;
        $this->secretary()->get('/secretaria/pacientes/PAC000001/historial')->assertOk()->assertSee('Elena Vargas')->assertSee('No hay citas');
    }

    public function test_sensitive_fields_in_service_payload_are_not_rendered(): void
    {
        $this->override = fn (string $m, string $path) => $path === '/secretaria/pacientes/PAC000001'
            ? F::patient() + ['passwordHash' => 'hash-private', 'tokenQr' => 'qr-private', 'diagnostico' => 'diagnosis-private']
            : null;
        $this->secretary()->get('/secretaria/pacientes/PAC000001')->assertOk()->assertDontSee('hash-private')->assertDontSee('qr-private')->assertDontSee('diagnosis-private');
    }

    public static function readErrors(): array
    {
        return [[400], [403], [404], [409], [422], [423], [500], [502], [503]];
    }

    #[DataProvider('readErrors')]
    public function test_recoverable_read_errors_do_not_destroy_session(int $status): void
    {
        $this->override = fn () => throw new ClinicalOperationException($status >= 500 ? 503 : $status, 'Conflicto administrativo seguro');
        $this->secretary()->get('/secretaria/pacientes/PAC000001')->assertStatus($status >= 500 ? 503 : $status)
            ->assertSee('No se pudo completar')->assertSessionHas('cod_usu', 'USU000002');
    }

    public function test_revocation_after_role_validation_clears_session(): void
    {
        $this->override = fn () => throw new ClinicalOperationException(401, 'Sesión expirada.');
        $this->secretary()->get('/secretaria/citas')->assertRedirect(route('login'))->assertSessionMissing('cod_usu');
    }

    public function test_service_outage_is_recoverable(): void
    {
        $this->override = fn () => throw new ClinicalOperationException(503, 'Servicio clínico no disponible.');
        $this->secretary()->get('/secretaria/citas')->assertStatus(503)->assertSessionHas('cod_usu', 'USU000002');
    }

    public static function invalidWriteResponses(): array
    {
        return [['/secretaria/reservas', ['codPac' => 'PAC000001', 'codSlot' => 'SLT00000001', 'confirmacion' => 1]],
            ['/secretaria/checkin', ['tokenQr' => 'qr-private']], ['/secretaria/citas/CIT00000001/cancelar', ['motivo' => 'Solicitud del paciente', 'confirmacion' => 1]],
            ['/secretaria/citas/CIT00000001/inasistencia', ['confirmacion' => 1]]];
    }

    #[DataProvider('invalidWriteResponses')]
    public function test_unexpected_write_outcome_does_not_claim_success(string $path, array $input): void
    {
        $this->override = fn () => ['codCit' => 'CIT00000001', 'estado' => 'Unexpected'];
        $this->secretary()->from('/secretaria/checkin')->post($path, $input)
            ->assertRedirect()->assertSessionMissing('success')->assertSessionHasErrors();
        $this->assertStringNotContainsString('qr-private', json_encode(session()->all()));
    }

    public function test_csrf_is_enforced_for_every_reception_mutation(): void
    {
        $this->app['env'] = 'production';
        foreach (['/secretaria/reservas', '/secretaria/checkin', '/secretaria/citas/CIT00000001/cancelar', '/secretaria/citas/CIT00000001/inasistencia'] as $path) {
            $this->secretary()->post($path)->assertStatus(419);
        }
        $this->assertEmpty($this->recorded);
        $this->withSession(['_token' => 'csrf-test'])->post('/secretaria/checkin', ['_token' => 'csrf-test', 'tokenQr' => 'valid-test-qr'])
            ->assertRedirect()->assertSessionHas('success');
    }
}
