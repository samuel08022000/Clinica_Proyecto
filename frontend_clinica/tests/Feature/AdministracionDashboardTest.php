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
        $response = $this->admin()->get('/administracion/inicio')->assertOk()
            ->assertSee('328')->assertSee('30 pacientes distintos')->assertSee('68 citas en el período')
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
        $response = $this->admin()->get('/administracion/inicio')->assertOk()
            ->assertSee('0 pacientes distintos')->assertSee('No hay reservas en este período.')
            ->assertSee('No se registraron pacientes atendidos')->assertSee('0,0 %')
            ->assertDontSee('ad-donut-segment')->assertDontSee('NaN')->assertDontSee('No disponible');

        ClinicalVisualSnapshots::save('dashboard-empty', $response->getContent());
    }

    public function test_unavailable_data_is_never_presented_as_zero(): void
    {
        $service = Mockery::mock(DashboardService::class);
        $service->shouldReceive('snapshot')->once()->andThrow(new ClinicalOperationException(500, 'Fallo clínico', 'ERROR_CLINICO'));
        $this->app->instance(DashboardService::class, $service);
        $response = $this->admin()->get('/administracion/inicio')->assertOk()
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
        $this->admin()->get('/administracion/inicio')->assertOk()
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
