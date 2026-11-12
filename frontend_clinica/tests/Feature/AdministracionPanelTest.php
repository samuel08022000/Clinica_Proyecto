<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdministrativeFixtures as F;
use Tests\TestCase;

class AdministracionPanelTest extends TestCase
{
    private ?int $failureStatus = null;

    private string $failureMessage = 'La edición afecta citas futuras; conserva el horario actual.';

    private ?\Closure $override = null;

    private string $authorizationRole = 'ROL004';

    private ?int $authorizationStatus = null;

    private bool $authorizationOffline = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLINICA_ADMIN_SNAPSHOTS') === '1') {
            $this->withVite();
        } else {
            $this->withoutVite();
        }
        config(['services.quarkus.base_url' => 'http://localhost:8080/api/v1']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = str_replace('/api/v1', '', parse_url($request->url(), PHP_URL_PATH));
            if ($path === '/auth/authorize') {
                if ($this->authorizationOffline) {
                    throw new ConnectionException('down');
                }
                if ($this->authorizationStatus) {
                    return Http::response(['error' => ['code' => 'INVALID_SESSION']], $this->authorizationStatus);
                }

                return Http::response(F::authorization($this->authorizationRole));
            }
            if ($this->override && ($response = ($this->override)($request, $path)) !== null) {
                return $response;
            }
            if ($this->failureStatus) {
                return Http::response(['ok' => false, 'error' => 'CONFLICTO', 'mensaje' => $this->failureMessage], $this->failureStatus);
            }
            if ($request->method() === 'GET') {
                return Http::response(F::get($path));
            }
            if (str_ends_with($path, '/password')) {
                return Http::response('', 204);
            }

            return Http::response(['codBlo' => 'BLO000001'], $request->method() === 'POST' ? 201 : 200);
        });
    }

    private function admin(): static
    {
        return $this->withSession(['auth_token' => 'opaque-admin-test', 'cod_usu' => 'USU000004', 'cod_rol' => 'ROL004']);
    }

    private function snapshot(string $name, TestResponse $response): void
    {
        if (getenv('CLINICA_ADMIN_SNAPSHOTS') !== '1') {
            return;
        }
        $directory = storage_path('app/private/fase10/snapshots');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/'.$name.'.html', preg_replace('~(?:https?://[^/\s"\x27]+)?/build/~',
            '/frontend_clinica/public/build/', $response->getContent()));
    }

    public static function screens(): array
    {
        return [
            'dashboard' => ['/administracion/inicio', 'Panel de administración'],
            'medicos' => ['/administracion/medicos', 'Lucía Flores'],
            'medico-alta' => ['/administracion/medicos/crear', 'Registrar médico'],
            'medico-detalle' => ['/administracion/medicos/MED000001', 'MP-1024'],
            'medico-edicion' => ['/administracion/medicos/MED000001/editar', 'Editar médico'],
            'secretarios' => ['/administracion/secretarias', 'Daniel Rojas'],
            'secretario-alta' => ['/administracion/secretarias/crear', 'Registrar secretario'],
            'secretario-detalle' => ['/administracion/secretarias/SEC000001', 'Daniel Rojas'],
            'secretario-edicion' => ['/administracion/secretarias/SEC000001/editar', 'Editar secretario'],
            'password' => ['/administracion/secretarias/SEC000001/password', 'Restablecer contraseña'],
            'especialidades' => ['/administracion/especialidades', 'Atención integral'],
            'especialidad-alta' => ['/administracion/especialidades/crear', 'Nueva especialidad'],
            'especialidad-edicion' => ['/administracion/especialidades/ESP001/editar', 'Editar especialidad'],
            'especialidad-detalle' => ['/administracion/especialidades/ESP001', 'Médicos vinculados'],
            'agendas-selector' => ['/administracion/agendas', 'Selecciona un médico'],
            'agendas' => ['/administracion/agendas?medico=MED000001', 'Lunes'],
            'horario-alta' => ['/administracion/agendas/MED000001/horarios/crear', 'Agregar horario'],
            'horario-edicion' => ['/administracion/agendas/MED000001/horarios/HOR000001/editar', 'Editar horario'],
            'bloqueos-selector' => ['/administracion/bloqueos', 'Selecciona un médico'],
            'bloqueos' => ['/administracion/bloqueos?medico=MED000001', 'Capacitación médica'],
            'bloqueo-alta' => ['/administracion/bloqueos/medicos/MED000001/crear', 'Registrar bloqueo'],
            'bloqueo-detalle' => ['/administracion/bloqueos/BLO000001', 'CIT00000001'],
            'pacientes' => ['/administracion/pacientes', 'Elena Vargas'],
            'paciente-detalle' => ['/administracion/pacientes/PAC000001', 'Historial de incidencias'],
            'sanciones' => ['/administracion/sanciones', 'Busca un paciente'],
            'sancion-alta' => ['/administracion/pacientes/PAC000001/sanciones/crear', 'Aplicar sanción temporal'],
            'sancion-detalle' => ['/administracion/pacientes/PAC000001/sanciones/INC00000001', 'Inasistencias reiteradas'],
        ];
    }

    #[DataProvider('screens')]
    public function test_admin_screens_render_contract_data_without_exposing_token(string $path, string $expected): void
    {
        $export = getenv('CLINICA_ADMIN_SNAPSHOTS') === '1';
        if ($export) {
            $this->withVite();
        }
        $response = $this->admin()->get($path)->assertOk()->assertSee($expected)->assertDontSee('opaque-admin-test')
            ->assertSee('María Administradora')->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('<h1', false)->assertSee('id="contenido-principal"', false)->assertDontSee('@endsection');
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer opaque-admin-test'));
        if ($export) {
            $directory = storage_path('app/private/fase10/snapshots');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            $name = array_search([$path, $expected], self::screens(), true);
            $html = preg_replace('~(?:https?://[^/\s"\x27]+)?/build/~', '/frontend_clinica/public/build/', $response->getContent());
            file_put_contents($directory.'/'.$name.'.html', $html);
            if ($name === 'dashboard') {
                $before = view('dashboard.home', ['user' => ['roleName' => 'Administrador', 'roleCode' => 'ROL004',
                    'accountStatus' => 'Activo', 'name' => 'María Administradora', 'email' => 'maria@example.test']])->render();
                file_put_contents($directory.'/before.html', preg_replace('~(?:https?://[^/\s"\x27]+)?/build/~', '/frontend_clinica/public/build/', $before));
            }
        }
    }

    public function test_every_admin_route_has_remote_role_authorization_and_no_guest_access(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->getName() ?? '', 'administracion.')) {
                continue;
            }
            $this->assertContains('rol:ROL004', $route->gatherMiddleware(), $route->uri());
        }
        $this->get('/administracion/medicos')->assertRedirect(route('login'));
        $this->post('/administracion/medicos', [])->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public static function otherRoles(): array
    {
        return [['ROL001'], ['ROL002'], ['ROL003']];
    }

    #[DataProvider('otherRoles')]
    public function test_remote_role_mismatch_rejects_reads_and_writes(string $role): void
    {
        $this->authorizationRole = $role;
        $this->withSession(['auth_token' => 'other-token', 'cod_usu' => 'USU000004', 'cod_rol' => $role])
            ->get('/administracion/pacientes')->assertForbidden();
        $this->post('/administracion/pacientes/PAC000001/sanciones', ['motivo' => 'Intento', 'dias' => 7, 'confirmacion' => 1])->assertForbidden();
        Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), '/auth/authorize'));
    }

    public function test_revoked_session_is_invalidated_before_any_action(): void
    {
        $this->authorizationStatus = 401;
        $this->admin()->get('/administracion/medicos')->assertRedirect(route('login'))->assertSessionMissing('auth_token');
    }

    public function test_authorization_outage_fails_closed_preserving_session(): void
    {
        $this->authorizationOffline = true;
        $response = $this->admin()->post('/administracion/pacientes/PAC000001/sanciones', ['motivo' => 'Motivo', 'dias' => 7, 'confirmacion' => 1])
            ->assertStatus(503)->assertSee('Tu sesión se conserva')->assertSessionHas('auth_token')->assertDontSee('opaque-admin-test');
        $this->snapshot('servicio-indisponible', $response);
    }

    public static function mutations(): array
    {
        $doctor = ['nombreCompleto' => 'Lucía Flores', 'email' => 'lucia@example.test', 'ci' => '1234567',
            'matriculaProfesional' => 'MP-1024', 'resumenCurricular' => 'Medicina interna', 'duracionConsultaMin' => '30',
            'codigosEspecialidades' => ['ESP001']];
        $secretary = ['nombreCompleto' => 'Daniel Rojas', 'email' => 'daniel@example.test', 'ci' => '7654321', 'telefono' => '70000000'];
        $password = ['password' => 'AccesoSeguro123', 'password_confirmation' => 'AccesoSeguro123'];
        $state = ['estado' => 'Inactivo', 'confirmacion' => '1'];
        $schedule = ['diaSemana' => '1', 'horaInicio' => '08:00', 'horaFin' => '12:00', 'pausaInicio' => '10:00', 'pausaFin' => '10:30'];

        return [
            'doctor-create' => ['POST', '/administracion/medicos', '/personal/medicos', $doctor + $password, array_keys($doctor + ['password' => ''])],
            'doctor-edit' => ['PUT', '/administracion/medicos/MED000001', '/personal/medicos/MED000001', $doctor, array_keys($doctor)],
            'doctor-password' => ['PUT', '/administracion/medicos/MED000001', '/personal/medicos/MED000001', $doctor + $password, array_keys($doctor + ['password' => ''])],
            'doctor-state' => ['PUT', '/administracion/medicos/MED000001/estado', '/personal/medicos/MED000001/estado', $state, ['estado']],
            'secretary-create' => ['POST', '/administracion/secretarias', '/personal/secretarias', $secretary + $password, array_keys($secretary + ['password' => ''])],
            'secretary-edit' => ['PUT', '/administracion/secretarias/SEC000001', '/personal/secretarias/SEC000001', $secretary, array_keys($secretary)],
            'secretary-state' => ['PUT', '/administracion/secretarias/SEC000001/estado', '/personal/secretarias/SEC000001/estado', $state, ['estado']],
            'password-204' => ['PUT', '/administracion/secretarias/SEC000001/password', '/personal/secretarias/SEC000001/password', $password + ['confirmacion' => 1], ['password']],
            'specialty-create' => ['POST', '/administracion/especialidades', '/personal/especialidades', ['nombreEspecialidad' => 'Cardiología', 'descripcion' => 'Atención cardiaca', 'duracionConsultaMin' => '45'], ['nombreEspecialidad', 'descripcion', 'duracionConsultaMin']],
            'specialty-edit' => ['PUT', '/administracion/especialidades/ESP001', '/personal/especialidades/ESP001', ['nombreEspecialidad' => 'Cardiología', 'descripcion' => 'Atención cardiaca', 'duracionConsultaMin' => 60], ['nombreEspecialidad', 'descripcion']],
            'specialty-state' => ['PUT', '/administracion/especialidades/ESP001/estado', '/personal/especialidades/ESP001/estado', $state, ['estado']],
            'schedule-create' => ['POST', '/administracion/agendas/MED000001/horarios', '/administracion/medicos/MED000001/horarios', $schedule, array_keys($schedule)],
            'schedule-edit' => ['PUT', '/administracion/agendas/MED000001/horarios/HOR000001', '/administracion/horarios/HOR000001', $schedule, array_keys($schedule)],
            'schedule-state' => ['PUT', '/administracion/agendas/MED000001/horarios/HOR000001/estado', '/administracion/horarios/HOR000001/estado', $state, ['estado']],
            'duration' => ['PUT', '/administracion/agendas/MED000001/duracion', '/administracion/medicos/MED000001/duracion', ['duracionConsultaMin' => '20', 'confirmacion' => 1], ['duracionConsultaMin']],
            'block-full-day' => ['POST', '/administracion/bloqueos/medicos/MED000001', '/administracion/medicos/MED000001/bloqueos', ['fechaBloqueo' => '2026-12-01', 'horaInicio' => '', 'horaFin' => '', 'motivo' => 'Capacitación', 'confirmacion' => 1], ['fechaBloqueo', 'horaInicio', 'horaFin', 'motivo']],
            'block-interval' => ['POST', '/administracion/bloqueos/medicos/MED000001', '/administracion/medicos/MED000001/bloqueos', ['fechaBloqueo' => '2026-12-01', 'horaInicio' => '10:00', 'horaFin' => '12:00', 'motivo' => 'Capacitación', 'confirmacion' => 1], ['fechaBloqueo', 'horaInicio', 'horaFin', 'motivo']],
            'sanction' => ['POST', '/administracion/pacientes/PAC000001/sanciones', '/administracion/pacientes/PAC000001/sanciones', ['motivo' => 'Inasistencias reiteradas', 'dias' => '7', 'confirmacion' => 1], ['motivo', 'dias']],
        ];
    }

    #[DataProvider('mutations')]
    public function test_mutations_send_only_the_backend_contract(string $method, string $path, string $endpoint, array $input, array $keys): void
    {
        $input += ['codUsuAdmin' => 'FORGED', 'role' => 'ROL001', 'codRol' => 'ROL004', 'registradoPorUsu' => 'FORGED'];
        $this->admin()->call($method, $path, $input)->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();
        Http::assertSent(function (Request $request) use ($method, $endpoint, $keys): bool {
            if ($request->method() !== $method || ! str_ends_with($request->url(), $endpoint)) {
                return false;
            }
            $this->assertEqualsCanonicalizing($keys, array_keys($request->data()));
            foreach (['dias', 'diaSemana', 'duracionConsultaMin'] as $key) {
                if (isset($request[$key])) {
                    $this->assertIsInt($request[$key]);
                }
            }

            return $request->hasHeader('Authorization', 'Bearer opaque-admin-test');
        });
        $this->assertStringNotContainsString('AccesoSeguro123', json_encode(session()->all()));
        if (str_ends_with($endpoint, '/password')) {
            $success = $this->withCookie(config('session.cookie'), session()->getId())->get('/administracion/secretarias/SEC000001')
                ->assertOk()->assertSee('La operación se completó correctamente.')->assertDontSee('AccesoSeguro123');
            $this->snapshot('secretario-exito', $success);
        }
    }

    public function test_catalog_state_and_search_filters_change_the_visible_result(): void
    {
        $this->admin()->get('/administracion/medicos?estado=Activo&q=no-coincide')->assertOk()->assertDontSee('lucia@example.test');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/personal/medicos?estado=Activo'));
        $this->get('/administracion/especialidades?estado=Todos&q=Medicina')->assertOk()->assertSee('Medicina interna');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/personal/especialidades?estado=Todos'));
    }

    public function test_patient_pagination_and_false_filter_reach_quarkus_as_real_parameters(): void
    {
        $this->admin()->get('/administracion/pacientes?page=2&q=Elena&estado=Activo')->assertOk();
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/administracion/pacientes?') && (string) $r['page'] === '2' && (string) $r['size'] === '20' && $r['q'] === 'Elena');
        $this->get('/administracion/pacientes/PAC000001?incidencias_page=1&sanciones_page=2&vigente=false&tipo=CANCELACION')->assertOk();
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/sanciones?') && $r['vigente'] === 'false' && (string) $r['page'] === '2');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/incidencias?') && $r['tipo'] === 'CANCELACION' && (string) $r['page'] === '1');
    }

    public function test_existing_inactive_specialty_link_can_be_preserved_but_not_offered_as_a_new_link(): void
    {
        $this->override = function (Request $r, string $path) {
            if ($path === '/personal/especialidades') {
                return Http::response([]);
            }

            return null;
        };
        $this->admin()->get('/administracion/medicos/MED000001/editar')->assertOk()->assertSee('Solo se permite conservar');
        $this->get('/administracion/medicos/crear')->assertOk()->assertDontSee('Medicina interna');
    }

    public static function invalidInputs(): array
    {
        return [
            ['POST', '/administracion/medicos', [], 'nombreCompleto'],
            ['POST', '/administracion/secretarias', ['nombreCompleto' => 'Daniel Rojas', 'email' => 'daniel@example.test', 'password' => 'abcdefghij', 'password_confirmation' => 'abcdefghij'], 'password'],
            ['POST', '/administracion/especialidades', ['nombreEspecialidad' => 'Cardiología', 'duracionConsultaMin' => 25], 'duracionConsultaMin'],
            ['POST', '/administracion/agendas/MED000001/horarios', ['diaSemana' => 8, 'horaInicio' => '12:00', 'horaFin' => '10:00'], 'diaSemana'],
            ['POST', '/administracion/agendas/MED000001/horarios', ['diaSemana' => 1, 'horaInicio' => '08:00', 'horaFin' => '12:00', 'pausaInicio' => '10:00'], 'pausaFin'],
            ['POST', '/administracion/bloqueos/medicos/MED000001', ['fechaBloqueo' => '2026-12-01', 'horaInicio' => '12:00', 'motivo' => 'Ausencia', 'confirmacion' => 1], 'horaFin'],
            ['PUT', '/administracion/medicos/MED000001/estado', ['estado' => 'Inactivo'], 'confirmacion'],
            ['PUT', '/administracion/agendas/MED000001/duracion', ['duracionConsultaMin' => 25, 'confirmacion' => 1], 'duracionConsultaMin'],
            ['POST', '/administracion/pacientes/PAC000001/sanciones', ['motivo' => 'Motivo', 'dias' => 0, 'confirmacion' => 1], 'dias'],
            ['POST', '/administracion/pacientes/PAC000001/sanciones', ['motivo' => 'Motivo', 'dias' => 366, 'confirmacion' => 1], 'dias'],
            ['POST', '/administracion/pacientes/PAC000001/sanciones', ['motivo' => 'Motivo', 'dias' => '7.5', 'confirmacion' => 1], 'dias'],
            ['POST', '/administracion/pacientes/PAC000001/sanciones', ['motivo' => 'Motivo', 'dias' => 7], 'confirmacion'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_forms_never_reach_mutation_endpoint(string $method, string $path, array $input, string $field): void
    {
        $this->admin()->call($method, $path, $input)->assertRedirect()->assertSessionHasErrors($field);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        if ($path === '/administracion/medicos') {
            $invalid = $this->withCookie(config('session.cookie'), session()->getId())->get('/administracion/medicos/crear')
                ->assertOk()->assertSee('data-error-summary', false)->assertSee('aria-invalid="true"', false);
            $this->snapshot('medico-validacion', $invalid);
        }
    }

    public static function businessFailures(): array
    {
        return [[400], [409], [422], [500], [503]];
    }

    #[DataProvider('businessFailures')]
    public function test_failed_mutation_preserves_safe_input_and_session(int $status): void
    {
        $this->failureStatus = $status;
        $this->admin()->from('/administracion/medicos/crear')->post('/administracion/medicos', [
            'nombreCompleto' => 'Lucía Flores', 'email' => 'lucia@example.test', 'matriculaProfesional' => 'MP-1024',
            'codigosEspecialidades' => ['ESP001'], 'password' => 'AccesoSeguro123', 'password_confirmation' => 'AccesoSeguro123',
        ])->assertRedirect('/administracion/medicos/crear')->assertSessionHasErrors('api')
            ->assertSessionHasInput('nombreCompleto', 'Lucía Flores')->assertSessionHas('auth_token');
        $this->assertArrayNotHasKey('password', session('_old_input'));
        $this->assertArrayNotHasKey('password_confirmation', session('_old_input'));
        $expected = $status >= 500 ? 'Tu sesión se conserva' : 'La edición afecta citas futuras';
        $this->withCookie(config('session.cookie'), session()->getId())->get('/administracion/medicos/crear')
            ->assertStatus($status >= 500 ? 503 : $status)->assertSee($expected);
    }

    public function test_schedule_conflict_is_visible_and_preserves_times(): void
    {
        $this->override = fn (Request $r) => $r->method() === 'POST' ? Http::response(['mensaje' => $this->failureMessage], 409) : null;
        $this->admin()->from('/administracion/agendas/MED000001/horarios/crear')->post('/administracion/agendas/MED000001/horarios', [
            'diaSemana' => 1, 'horaInicio' => '08:00', 'horaFin' => '12:00',
        ])->assertRedirect()->assertSessionHasInput('horaInicio', '08:00')->assertSessionHas('api_status', 409);
        $feedback = $this->withCookie(config('session.cookie'), session()->getId())->get('/administracion/agendas/MED000001/horarios/crear');
        $feedback->assertOk()->assertSee($this->failureMessage)->assertSee('value="08:00"', false);
        $this->snapshot('horario-conflicto', $feedback);
    }

    public function test_duplicate_sanction_conflict_retains_days_and_explanation(): void
    {
        $this->override = fn (Request $r) => $r->method() === 'POST' ? Http::response(['mensaje' => 'El paciente tiene una restricción vigente.'], 409) : null;
        $this->admin()->from('/administracion/pacientes/PAC000001/sanciones/crear')->post('/administracion/pacientes/PAC000001/sanciones', [
            'motivo' => 'Inasistencias reiteradas', 'dias' => 7, 'confirmacion' => 1,
        ])->assertRedirect()->assertSessionHasInput('dias', 7)->assertSessionHasErrors('api');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/administracion/pacientes/PAC000001/sanciones/crear')
            ->assertOk()->assertSee('restricción vigente');
    }

    public static function readFailures(): array
    {
        return [[400], [403], [404], [409], [423], [500], [503]];
    }

    #[DataProvider('readFailures')]
    public function test_read_errors_render_a_recoverable_screen(int $status): void
    {
        $this->failureStatus = $status;
        $this->admin()->get('/administracion/pacientes/PAC000001')->assertStatus($status >= 500 ? 503 : $status)
            ->assertSee('No se pudo completar')->assertSessionHas('auth_token')->assertDontSee('opaque-admin-test');
    }

    public function test_session_revoked_after_authorization_is_also_cleared(): void
    {
        $this->failureStatus = 401;
        $this->admin()->get('/administracion/medicos')->assertRedirect(route('login'))->assertSessionMissing('auth_token');
    }

    public function test_connection_outage_after_authorization_preserves_session(): void
    {
        $this->override = fn () => throw new ConnectionException('down');
        $this->admin()->get('/administracion/medicos')->assertStatus(503)->assertSessionHas('auth_token');
    }

    public function test_malformed_success_payload_does_not_become_an_empty_success_screen(): void
    {
        $this->override = fn () => Http::response('not-json', 200);
        $this->admin()->get('/administracion/medicos')->assertStatus(502)->assertSee('no pudo interpretarse');
    }

    public function test_legacy_block_does_not_invent_affected_appointments(): void
    {
        $this->override = fn (Request $r, string $path) => $path === '/administracion/bloqueos/BLO000001'
            ? Http::response(array_replace(F::block(), ['trazabilidadCompleta' => false])) : null;
        $this->admin()->get('/administracion/bloqueos/BLO000001')->assertOk()->assertSee('no dispone de trazabilidad completa')->assertDontSee('CIT00000001');
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'citas-afectadas'));
    }

    public function test_empty_lists_keep_the_action_and_do_not_fabricate_totals(): void
    {
        $this->override = fn (Request $r, string $path) => $path === '/personal/medicos' ? Http::response([]) : null;
        $empty = $this->admin()->get('/administracion/medicos')->assertOk()->assertSee('No se encontraron médicos')->assertSee('Registrar médico');
        $this->snapshot('medicos-vacio', $empty);
        $this->get('/administracion/inicio')->assertOk()->assertSee('Médicos activos')->assertDontSee('+12%');
    }

    public function test_upstream_cannot_reflect_password_or_token_into_session_errors(): void
    {
        $this->failureStatus = 400;
        $this->failureMessage = 'Rechazado AccesoSeguro123 opaque-admin-test';
        $this->admin()->put('/administracion/secretarias/SEC000001/password', [
            'password' => 'AccesoSeguro123', 'password_confirmation' => 'AccesoSeguro123', 'confirmacion' => 1,
        ])->assertSessionHasErrors('api');
        $this->assertStringNotContainsString('AccesoSeguro123', json_encode(session()->get('errors')->all()));
        $this->assertStringNotContainsString('opaque-admin-test', json_encode(session()->get('errors')->all()));
    }

    public function test_csrf_is_enforced_for_administrative_writes(): void
    {
        $this->app['env'] = 'production';
        $this->admin()->post('/administracion/especialidades', ['nombreEspecialidad' => 'Cardiología', 'duracionConsultaMin' => 30])->assertStatus(419);
        Http::assertNothingSent();
        $this->withSession(['_token' => 'csrf-test'])->post('/administracion/especialidades', [
            '_token' => 'csrf-test', 'nombreEspecialidad' => 'Cardiología', 'duracionConsultaMin' => 30,
        ])->assertRedirect()->assertSessionHas('success');
    }
}
