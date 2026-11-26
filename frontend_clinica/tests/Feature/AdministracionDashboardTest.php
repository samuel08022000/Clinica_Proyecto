<?php

namespace Tests\Feature;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Administration\DashboardService;
use App\Services\Auth\AccountStatusService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalVisualSnapshots;
use Tests\Support\DashboardFixtures as F;
use Tests\TestCase;

class AdministracionDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $accounts = Mockery::mock(AccountStatusService::class);
        $accounts->shouldReceive('requireActive')->andReturn((object) [
            'cod_usu' => 'USU000004', 'nombre_completo' => 'María Administradora',
            'email' => 'maria@example.test', 'cod_rol' => 'ROL004', 'nombre_rol' => 'Administrador',
            'usuario_estado' => 'Activo', 'rol_estado' => 'Activo', 'perfil_presente' => true,
            'perfil_estado' => 'Activo', 'email_verificado' => true, 'bloqueado_hasta' => null,
        ]);
        $this->app->instance(AccountStatusService::class, $accounts);
    }

    public function test_dashboard_renders_complete_counts_distributions_and_accessible_daily_data(): void
    {
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->once()->with([])->andReturn(F::snapshot());
        $this->app->instance(DashboardService::class, $service);
        $response = $this->admin()->get('/administracion/inicio?vista=analisis')->assertOk()
            ->assertSee('30 pacientes distintos')->assertSee('68 citas en el período')
            ->assertSee('Especialidades más solicitadas')->assertSee('Medicina general')
            ->assertSee('Ver las 6 especialidades')->assertSee('Canales de reserva')->assertSee('Derivaciones')
            ->assertSee('58,8 %')->assertSee('2026-11-20')->assertSee('Ver datos por día')
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('No disponible');

        ClinicalVisualSnapshots::save('dashboard-analytics', $response->getContent());
    }

    public function test_zero_records_are_real_zeros_without_fake_slices_or_invalid_geometry(): void
    {
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->once()->andReturn(F::empty());
        $this->app->instance(DashboardService::class, $service);
        $response = $this->admin()->get('/administracion/inicio?vista=analisis')->assertOk()
            ->assertSee('0 pacientes distintos')->assertSee('No hay reservas en este período.')
            ->assertSee('No se registraron pacientes atendidos')->assertSee('0,0 %')
            ->assertDontSee('ad-donut-segment')->assertDontSee('NaN')->assertDontSee('No disponible');

        ClinicalVisualSnapshots::save('dashboard-empty', $response->getContent());
    }

    public function test_queue_charts_render_uncapped_utilization_unknown_times_and_observed_samples(): void
    {
        $data = F::snapshot();
        $data['period']['label'] = '01/11/2026 – 24/11/2026';
        $data['period']['today'] = '24/11/2026';
        $data['updatedAt'] = '24/11/2026 08:15';
        $wait = [
            'total' => 4, 'en_atencion' => 1, 'sin_tiempo' => 1, 'dentro' => 1, 'fuera' => 2,
            'moderada' => 1, 'elevada' => 1, 'promedio' => 25, 'maximo' => 35, 'porcentaje' => 100 / 3,
        ];
        $usage = ['valor' => 125.0, 'estado' => 'saturado', 'etiqueta' => 'Saturado (Inestable)'];
        $doctor = ['cod_med' => 'MED123', 'nombre_completo' => 'Dra. Lucía Flores'];
        $data['queues'] = [
            'espera_actual' => [
                'global' => $wait, 'por_medico' => ['MED123' => $wait],
                'meta' => 15, 'demora_elevada' => 30, 'actualizado' => '24/11/2026 08:15:30',
            ],
            'filtros_aplicados' => ['fecha_inicio' => '2026-11-21', 'fecha_fin' => '2026-11-24', 'medico' => 'MED123'],
            'catalogos' => [
                'especialidades' => [['codEsp' => 'ESP001', 'nombreEspecialidad' => 'Medicina general']],
                'medicos' => [['codMed' => 'MED123', 'nombreCompleto' => $doctor['nombre_completo']]],
            ],
            'resumen_global' => ['ambito' => 'Médico: '.$doctor['nombre_completo'], 'utilizacion' => $usage],
            'medicos_espera' => [$doctor, ['cod_med' => 'MED124', 'nombre_completo' => 'Dr. Carlos Méndez']],
            'por_medico' => [
                ['nombre' => $doctor['nombre_completo'], 'especialidades_texto' => 'Medicina general, Cardiología', 'en_espera_hoy' => 4, 'utilizacion' => $usage],
                ['nombre' => 'Dr. Carlos Méndez', 'especialidades_texto' => 'Pediatría', 'en_espera_hoy' => 0, 'utilizacion' => ['valor' => null, 'estado' => 'sin_capacidad', 'etiqueta' => 'Sin capacidad programada']],
            ],
            'por_especialidad' => [['nombre' => 'Medicina general', 'utilizacion' => $usage]],
            'evolucion_espera' => [
                ['date' => '2026-11-21', 'label' => '21/11', 'value' => 18.2, 'sample' => 4],
                ['date' => '2026-11-23', 'label' => '23/11', 'value' => 23.5, 'sample' => 7],
                ['date' => '2026-11-24', 'label' => '24/11', 'value' => 12.4, 'sample' => 6],
            ],
        ];
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->twice()->with(['medico' => 'MED123'])->andReturn($data);
        $this->app->instance(DashboardService::class, $service);
        $response = $this->admin()->get('/administracion/inicio?medico=MED123')->assertOk()
            ->assertSee('125,0 %')->assertSee('Sin capacidad programada')->assertDontSee('Sala de espera actual')
            ->assertSee('Evolución de la espera observada')->assertSee('23,5 min')->assertSee('Congestión por especialidad');

        ClinicalVisualSnapshots::save('dashboard-queues', $response->getContent());

        $today = $this->admin()->get('/administracion/inicio?vista=hoy&medico=MED123')->assertOk()
            ->assertSee('Sala de espera actual')->assertSee('24/11/2026 08:15:30')
            ->assertSee('33,3 %')->assertSee('1 sin tiempo válido')
            ->assertSee('vista=analisis&amp;medico=MED123', false)
            ->assertDontSee('Utilización estimada por médico');

        ClinicalVisualSnapshots::save('dashboard-today', $today->getContent());
    }

    public function test_queue_charts_render_when_the_waiting_room_and_utilization_lists_are_empty(): void
    {
        $data = F::snapshot();
        $data['queues'] = [
            'espera_actual' => [
                'global' => ['total' => 0, 'en_atencion' => 0, 'promedio' => null, 'maximo' => null, 'fuera' => 0, 'sin_tiempo' => 0],
                'por_medico' => [], 'meta' => 15, 'demora_elevada' => 30, 'actualizado' => '24/11/2026 08:15:30',
            ],
            'filtros_aplicados' => [],
            'catalogos' => ['especialidades' => [], 'medicos' => []],
            'resumen_global' => ['ambito' => 'Clínica completa', 'utilizacion' => ['valor' => null, 'etiqueta' => 'Sin capacidad programada']],
            'medicos_espera' => [],
            'por_medico' => [],
            'por_especialidad' => [],
            'evolucion_espera' => [],
        ];
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->twice()->with(['periodo' => 'mes'])->andReturn($data);
        $this->app->instance(DashboardService::class, $service);

        $this->admin()->get('/administracion/inicio?periodo=mes')->assertOk()
            ->assertSee('Sin resultados para los filtros seleccionados.')
            ->assertSee('Pacientes atendidos por día');
        $empty = $this->admin()->get('/administracion/inicio?vista=hoy&periodo=mes')->assertOk()
            ->assertSee('Sin pacientes en espera');

        ClinicalVisualSnapshots::save('dashboard-today-empty', $empty->getContent());
    }

    public function test_management_groups_totals_and_direct_actions(): void
    {
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->once()->with([])->andReturn(F::snapshot());
        $this->app->instance(DashboardService::class, $service);

        $response = $this->admin()->get('/administracion/inicio?vista=gestion')->assertOk()
            ->assertSee('328')->assertSee('Médicos activos')->assertSee('Registrar médico')
            ->assertSee('Directorio')->assertSee('Personal activo por rol')->assertSee('Registros de pacientes')
            ->assertSee('En el período')->assertSee('Fuera del período')->assertSee('314')
            ->assertDontSee('Pacientes atendidos por día');

        ClinicalVisualSnapshots::save('dashboard-management', $response->getContent());
    }

    public function test_unavailable_data_is_never_presented_as_zero(): void
    {
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->once()->andThrow(new ClinicalOperationException(500, 'Fallo clínico', 'ERROR_CLINICO'));
        $this->app->instance(DashboardService::class, $service);
        $response = $this->admin()->get('/administracion/inicio?vista=gestion')->assertOk()
            ->assertSee('Las estadísticas no están disponibles.')->assertSee('No disponible')
            ->assertDontSee('ad-donut-segment')->assertDontSee('0 pacientes distintos')
            ->assertSessionHas('cod_usu', 'USU000004');

        ClinicalVisualSnapshots::save('dashboard-offline', $response->getContent());
    }

    public function test_custom_period_reaches_the_service_as_validated_input_only(): void
    {
        $filters = ['periodo' => 'personalizado', 'desde' => '2026-01-01', 'hasta' => '2027-01-01'];
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->once()->with($filters)->andReturn(F::snapshot());
        $this->app->instance(DashboardService::class, $service);
        $this->admin()->get('/administracion/inicio?'.http_build_query($filters + ['cod_rol' => 'ROL001']))->assertOk();
    }

    public static function invalidPeriods(): array
    {
        return [
            'unknown preset' => [['periodo' => 'anual'], 'periodo'],
            'array preset' => [['periodo' => ['hoy']], 'periodo'],
            'missing dates' => [['periodo' => 'personalizado'], 'desde'],
            'invalid date' => [['periodo' => 'personalizado', 'desde' => '2026-02-30', 'hasta' => '2026-03-10'], 'desde'],
            'reversed range' => [['periodo' => 'personalizado', 'desde' => '2026-11-20', 'hasta' => '2026-11-01'], 'hasta'],
            'over 366 days' => [['periodo' => 'personalizado', 'desde' => '2026-01-01', 'hasta' => '2027-01-02'], 'hasta'],
            'invalid doctor' => [['medico' => 'MED-1'], 'medico'],
            'invalid specialty' => [['especialidad' => ['ESP1']], 'especialidad'],
            'invalid view' => [['vista' => ['hoy']], 'vista'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_periods_do_not_query_statistics(array $filters, string $field): void
    {
        $service = Mockery::mock(DashboardService::class);
        $service->shouldNotReceive('snapshot');
        $this->app->instance(DashboardService::class, $service);
        $this->admin()->get('/administracion/inicio?'.http_build_query($filters))
            ->assertRedirect(route('administracion.home'))->assertSessionHasErrors($field);
    }

    public function test_authorization_errors_are_not_hidden_as_an_empty_dashboard(): void
    {
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->once()->andThrow(new ClinicalOperationException(403, 'Acceso denegado.', 'PROHIBIDO'));
        $this->app->instance(DashboardService::class, $service);
        $this->admin()->get('/administracion/inicio')->assertForbidden();
    }

    public function test_specialty_labels_are_escaped_in_charts_and_tables(): void
    {
        $data = F::snapshot();
        $data['specialties'][0]['label'] = '<script>alert("x")</script>';
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->once()->andReturn($data);
        $this->app->instance(DashboardService::class, $service);
        $this->admin()->get('/administracion/inicio?vista=analisis')->assertOk()
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(', false);
    }

    private function admin(): static
    {
        $user = new Usuario;
        $user->forceFill(['cod_usu' => 'USU000004', 'cod_rol' => 'ROL004', 'estado' => 'Activo',
            'nombre_completo' => 'María Administradora', 'email' => 'maria@example.test']);

        return $this->actingAs($user, 'web')->withSession([
            'auth_authority' => 'laravel', 'auth_expires_at' => now()->addMinutes(120)->toIso8601String(),
            'cod_usu' => 'USU000004', 'cod_rol' => 'ROL004', 'nombre_usuario' => 'María Administradora',
            'email_usuario' => 'maria@example.test', 'nombre_rol' => 'Administrador', 'rol_slug' => 'administracion',
        ]);
    }
}
