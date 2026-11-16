<?php

namespace Tests\Feature;

use App\Exceptions\AuthDomainException;
use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Administration\AdministrationService;
use App\Services\Auth\AccountStatusService;
use Illuminate\Database\QueryException;
use Mockery;
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

    /** @var array<int, array{method: string, path: string, data: array}> */
    private array $recorded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $accounts = Mockery::mock(AccountStatusService::class);
        $accounts->shouldReceive('requireActive')->andReturnUsing(function (string $userId): object {
            if ($this->authorizationOffline) {
                throw new QueryException('clinical', 'SELECT 1', [], new \PDOException('SQLSTATE[08006] down'));
            }
            if ($this->authorizationStatus === 401) {
                throw new AuthDomainException('ACCOUNT_NOT_FOUND', 401, 'La cuenta ya no está disponible.');
            }
            if ($this->authorizationStatus === 403) {
                throw new AuthDomainException('ROLE_DISABLED', 403, 'Acceso denegado.');
            }

            return (object) [
                'cod_usu' => $userId,
                'nombre_completo' => 'María Administradora',
                'email' => 'maria@example.test',
                'cod_rol' => $this->authorizationRole,
                'nombre_rol' => 'Administrador',
                'usuario_estado' => 'Activo',
                'rol_estado' => 'Activo',
                'perfil_presente' => true,
                'perfil_estado' => 'Activo',
                'email_verificado' => true,
                'bloqueado_hasta' => null,
            ];
        });
        $this->app->instance(AccountStatusService::class, $accounts);

        $adminService = Mockery::mock(AdministrationService::class);
        $adminService->shouldReceive('request')->andReturnUsing(function (string $method, string $path, array $data = []): array {
            $normalizedPath = '/'.ltrim($path, '/');
            $this->recorded[] = ['method' => $method, 'path' => $normalizedPath, 'data' => $data];

            if ($this->override && ($result = ($this->override)($method, $normalizedPath, $data)) !== null) {
                return $result;
            }
            if ($this->failureStatus) {
                throw new ClinicalOperationException(
                    $this->failureStatus >= 500 ? 503 : $this->failureStatus,
                    $this->failureMessage,
                    'CLINICAL_ERROR',
                );
            }
            if ($method === 'GET') {
                return F::get($normalizedPath);
            }

            return ['codBlo' => 'BLO000001'];
        });
        $this->app->instance(AdministrationService::class, $adminService);
    }

    public static function blockReasonBoundaries(): array
    {
        return ['backend maximum' => [500, true], 'exceeds backend maximum' => [501, false]];
    }

    #[DataProvider('blockReasonBoundaries')]
    public function test_block_reason_matches_the_real_backend_character_limit(int $length, bool $valid): void
    {
        $reason = str_repeat('á', $length);
        $response = $this->admin()->from('/administracion/bloqueos/medicos/MED000001/crear')
            ->post('/administracion/bloqueos/medicos/MED000001', [
                'fechaBloqueo' => '2026-12-01', 'motivo' => $reason, 'confirmacion' => 1,
            ]);

        if ($valid) {
            $response->assertSessionHasNoErrors()->assertRedirect('/administracion/bloqueos/BLO000001');
            $this->assertContains('POST', array_column($this->recorded, 'method'));
            $post = collect($this->recorded)->firstWhere('method', 'POST');
            $this->assertSame($reason, $post['data']['motivo']);
        } else {
            $response->assertRedirect('/administracion/bloqueos/medicos/MED000001/crear')
                ->assertSessionHasErrors('motivo')->assertSessionHasInput('motivo', $reason);
            $this->assertEmpty(array_filter($this->recorded, fn ($entry) => $entry['method'] === 'POST'));
        }
    }

    public function test_block_form_advertises_the_real_backend_reason_limit(): void
    {
        $this->admin()->get('/administracion/bloqueos/medicos/MED000001/crear')
            ->assertOk()->assertSee('maxlength="500"', false);
    }

    private function admin(): static
    {
        $user = new Usuario;
        $user->forceFill([
            'cod_usu' => 'USU000004',
            'cod_rol' => $this->authorizationRole,
            'nombre_completo' => 'María Administradora',
            'email' => 'maria@example.test',
            'estado' => 'Activo',
        ]);

        return $this->actingAs($user, 'web')->withSession([
            'auth_authority' => 'laravel',
            'auth_expires_at' => now()->addMinutes(120)->toIso8601String(),
            'cod_usu' => 'USU000004',
            'cod_rol' => $this->authorizationRole,
            'nombre_usuario' => 'María Administradora',
            'email_usuario' => 'maria@example.test',
            'nombre_rol' => 'Administrador',
            'rol_slug' => 'administracion',
            'estado_cuenta' => 'Activo',
        ]);
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
    public function test_admin_screens_render_contract_data_without_exposing_credentials(string $path, string $expected): void
    {
        $this->admin()->get($path)->assertOk()->assertSee($expected)
            ->assertSee('María Administradora')->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('<h1', false)->assertSee('id="contenido-principal"', false)->assertDontSee('@endsection');
    }

    public function test_every_admin_route_has_role_authorization_and_no_guest_access(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->getName() ?? '', 'administracion.')) {
                continue;
            }
            $this->assertContains('rol:ROL004', $route->gatherMiddleware(), $route->uri());
        }
        $this->get('/administracion/medicos')->assertRedirect(route('login'));
        $this->post('/administracion/medicos', [])->assertRedirect(route('login'));
        $this->assertEmpty($this->recorded);
    }

    public static function otherRoles(): array
    {
        return [['ROL001'], ['ROL002'], ['ROL003']];
    }

    #[DataProvider('otherRoles')]
    public function test_role_mismatch_rejects_reads_and_writes(string $role): void
    {
        $this->authorizationRole = $role;
        $this->admin()->get('/administracion/pacientes')->assertForbidden();
        $this->post('/administracion/pacientes/PAC000001/sanciones', ['motivo' => 'Intento', 'dias' => 7, 'confirmacion' => 1])->assertForbidden();
        $this->assertEmpty($this->recorded);
    }

    public function test_revoked_session_is_invalidated_before_any_action(): void
    {
        $this->authorizationStatus = 401;
        $this->admin()->get('/administracion/medicos')->assertRedirect(route('login'))->assertSessionMissing('cod_usu');
    }

    public function test_authorization_outage_fails_closed_preserving_session(): void
    {
        $this->authorizationOffline = true;
        $this->admin()->post('/administracion/pacientes/PAC000001/sanciones', ['motivo' => 'Motivo', 'dias' => 7, 'confirmacion' => 1])
            ->assertStatus(503)->assertSee('Tu sesión se conserva')->assertSessionHas('cod_usu', 'USU000004');
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

        $sent = collect($this->recorded)->first(fn ($entry) => $entry['method'] === $method && $entry['path'] === $endpoint);
        $this->assertNotNull($sent, 'Expected service call '.$method.' '.$endpoint);
        $this->assertEqualsCanonicalizing($keys, array_keys($sent['data']));
        foreach (['dias', 'diaSemana', 'duracionConsultaMin'] as $key) {
            if (isset($sent['data'][$key])) {
                $this->assertIsInt($sent['data'][$key]);
            }
        }
        $this->assertStringNotContainsString('AccesoSeguro123', json_encode(session()->all()));
        if (str_ends_with($endpoint, '/password')) {
            $this->withCookie(config('session.cookie'), session()->getId())->get('/administracion/secretarias/SEC000001')
                ->assertOk()->assertSee('La operación se completó correctamente.')->assertDontSee('AccesoSeguro123');
        }
    }

    public function test_catalog_state_and_search_filters_change_the_visible_result(): void
    {
        $this->admin()->get('/administracion/medicos?estado=Activo&q=no-coincide')->assertOk()->assertDontSee('lucia@example.test');
        $doctorsCall = collect($this->recorded)->firstWhere('path', '/personal/medicos');
        $this->assertSame('Activo', $doctorsCall['data']['estado'] ?? null);

        $this->get('/administracion/especialidades?estado=Todos&q=Medicina')->assertOk()->assertSee('Medicina interna');
        $specCall = collect($this->recorded)->firstWhere('path', '/personal/especialidades');
        $this->assertSame('Todos', $specCall['data']['estado'] ?? null);
    }

    public function test_patient_pagination_and_false_filter_reach_service_as_real_parameters(): void
    {
        $this->admin()->get('/administracion/pacientes?page=2&q=Elena&estado=Activo')->assertOk();
        $listCall = collect($this->recorded)->firstWhere('path', '/administracion/pacientes');
        $this->assertSame('2', (string) ($listCall['data']['page'] ?? ''));
        $this->assertSame('20', (string) ($listCall['data']['size'] ?? ''));
        $this->assertSame('Elena', $listCall['data']['q'] ?? null);

        $this->get('/administracion/pacientes/PAC000001?incidencias_page=1&sanciones_page=2&vigente=false&tipo=CANCELACION')->assertOk();
        $sanctionsCall = collect($this->recorded)->firstWhere('path', '/administracion/pacientes/PAC000001/sanciones');
        $incidentsCall = collect($this->recorded)->firstWhere('path', '/administracion/pacientes/PAC000001/incidencias');
        $this->assertSame('false', $sanctionsCall['data']['vigente'] ?? null);
        $this->assertSame('2', (string) ($sanctionsCall['data']['page'] ?? ''));
        $this->assertSame('CANCELACION', $incidentsCall['data']['tipo'] ?? null);
        $this->assertSame('1', (string) ($incidentsCall['data']['page'] ?? ''));
    }

    public function test_existing_inactive_specialty_link_can_be_preserved_but_not_offered_as_a_new_link(): void
    {
        $this->override = fn (string $m, string $path) => $path === '/personal/especialidades' ? [] : null;
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
        $this->assertEmpty(array_filter($this->recorded, fn ($entry) => $entry['method'] !== 'GET'));
        if ($path === '/administracion/medicos') {
            $this->withCookie(config('session.cookie'), session()->getId())->get('/administracion/medicos/crear')
                ->assertOk()->assertSee('data-error-summary', false)->assertSee('aria-invalid="true"', false);
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
            ->assertSessionHasInput('nombreCompleto', 'Lucía Flores')->assertSessionHas('cod_usu', 'USU000004');
        $this->assertArrayNotHasKey('password', session('_old_input'));
        $this->assertArrayNotHasKey('password_confirmation', session('_old_input'));
        $expected = $status >= 500 ? 'Tu sesión se conserva' : 'La edición afecta citas futuras';
        $this->withCookie(config('session.cookie'), session()->getId())->get('/administracion/medicos/crear')
            ->assertStatus($status >= 500 ? 503 : $status)->assertSee($expected);
    }

    public function test_schedule_conflict_is_visible_and_preserves_times(): void
    {
        $this->override = function (string $m) {
            if ($m === 'POST') {
                throw new ClinicalOperationException(409, $this->failureMessage, 'SCHEDULE_CONFLICT');
            }

            return null;
        };
        $this->admin()->from('/administracion/agendas/MED000001/horarios/crear')->post('/administracion/agendas/MED000001/horarios', [
            'diaSemana' => 1, 'horaInicio' => '08:00', 'horaFin' => '12:00',
        ])->assertRedirect()->assertSessionHasInput('horaInicio', '08:00')->assertSessionHas('api_status', 409);
        $this->withCookie(config('session.cookie'), session()->getId())->get('/administracion/agendas/MED000001/horarios/crear')
            ->assertOk()->assertSee($this->failureMessage)->assertSee('value="08:00"', false);
    }

    public function test_duplicate_sanction_conflict_retains_days_and_explanation(): void
    {
        $this->override = function (string $m) {
            if ($m === 'POST') {
                throw new ClinicalOperationException(409, 'El paciente tiene una restricción vigente.', 'ACTIVE_SANCTION');
            }

            return null;
        };
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
            ->assertSee('No se pudo completar')->assertSessionHas('cod_usu', 'USU000004');
    }

    public function test_session_revoked_after_authorization_is_also_cleared(): void
    {
        $this->failureStatus = 401;
        $this->admin()->get('/administracion/medicos')->assertRedirect(route('login'))->assertSessionMissing('cod_usu');
    }

    public function test_service_outage_after_authorization_preserves_session(): void
    {
        $this->override = fn () => throw new ClinicalOperationException(503, 'Base de datos clínica no disponible.');
        $this->admin()->get('/administracion/medicos')->assertStatus(503)->assertSessionHas('cod_usu', 'USU000004');
    }

    public function test_legacy_block_does_not_invent_affected_appointments(): void
    {
        $this->override = fn (string $m, string $path) => $path === '/administracion/bloqueos/BLO000001'
            ? array_replace(F::block(), ['trazabilidadCompleta' => false]) : null;
        $this->admin()->get('/administracion/bloqueos/BLO000001')->assertOk()->assertSee('no dispone de trazabilidad completa')->assertDontSee('CIT00000001');
        $this->assertEmpty(array_filter($this->recorded, fn ($entry) => str_contains($entry['path'], 'citas-afectadas')));
    }

    public function test_empty_lists_keep_the_action_and_do_not_fabricate_totals(): void
    {
        $this->override = fn (string $m, string $path) => $path === '/personal/medicos' ? [] : null;
        $this->admin()->get('/administracion/medicos')->assertOk()->assertSee('No se encontraron médicos')->assertSee('Registrar médico');
        $this->get('/administracion/inicio')->assertOk()->assertSee('Médicos activos')->assertDontSee('+12%');
    }

    public function test_failed_password_reset_does_not_leak_password_into_session_input(): void
    {
        $this->failureStatus = 400;
        $this->failureMessage = 'Error de validación en cambio de clave.';
        $this->admin()->put('/administracion/secretarias/SEC000001/password', [
            'password' => 'AccesoSeguro123', 'password_confirmation' => 'AccesoSeguro123', 'confirmacion' => 1,
        ])->assertSessionHasErrors('api');
        $this->assertArrayNotHasKey('password', session('_old_input', []));
        $this->assertStringNotContainsString('AccesoSeguro123', json_encode(session()->all()));
    }

    public function test_csrf_is_enforced_for_administrative_writes(): void
    {
        $this->app['env'] = 'production';
        $this->admin()->post('/administracion/especialidades', ['nombreEspecialidad' => 'Cardiología', 'duracionConsultaMin' => 30])->assertStatus(419);
        $this->assertEmpty($this->recorded);
        $this->withSession(['_token' => 'csrf-test'])->post('/administracion/especialidades', [
            '_token' => 'csrf-test', 'nombreEspecialidad' => 'Cardiología', 'duracionConsultaMin' => 30,
        ])->assertRedirect()->assertSessionHas('success');
    }
}
