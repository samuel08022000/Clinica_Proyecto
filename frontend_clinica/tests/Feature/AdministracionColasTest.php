<?php

namespace Tests\Feature;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Administration\ColaMetricaService;
use App\Services\Auth\AccountStatusService;
use App\Services\Clinical\ClinicalRoleGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministracionColasTest extends TestCase
{
    private string $authorizationRole = 'ROL004';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $accounts = Mockery::mock(AccountStatusService::class);
        $accounts->shouldReceive('requireActive')->andReturnUsing(fn (string $userId): object => (object) [
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
        ]);
        $this->app->instance(AccountStatusService::class, $accounts);
    }

    public function test_mm1_calculates_exact_metrics_for_known_stable_case(): void
    {
        $service = $this->mathService();
        $resultado = $service->calcularMM1(2.0, 3.0);

        $this->assertSame('M/M/1', $resultado['modelo']);
        $this->assertTrue($resultado['es_estable']);
        $this->assertSame('optimo', $resultado['estado']);
        $this->assertSame('Óptimo / Holgado', $resultado['etiqueta']);
        $this->assertEqualsWithDelta(2.0 / 3.0, $resultado['rho'], 1e-6);
        $this->assertEqualsWithDelta(1.0 / 3.0, $resultado['p0'], 1e-6);
        $this->assertEqualsWithDelta(2.0 / 3.0, $resultado['p_espera'], 1e-6);
        $this->assertEqualsWithDelta(4.0 / 3.0, $resultado['lq'], 1e-6);
        $this->assertEqualsWithDelta(2.0, $resultado['l'], 1e-6);
        $this->assertEqualsWithDelta(40.0, $resultado['wq_minutos'], 1e-6);
        $this->assertEqualsWithDelta(60.0, $resultado['w_minutos'], 1e-6);
        $this->assertSame(1, $resultado['servidores_minimos']);
    }

    public function test_mmc_with_one_server_matches_mm1_exactly(): void
    {
        $service = $this->mathService();
        $mm1 = $service->calcularMM1(2.4, 3.0);
        $mmc = $service->calcularMMc(2.4, 3.0, 1);

        $this->assertSame('M/M/1', $mmc['modelo']);
        $this->assertSame('estable', $mmc['estado']);
        $this->assertSame($mm1['es_estable'], $mmc['es_estable']);
        $this->assertSame($mm1['estado'], $mmc['estado']);
        $this->assertSame($mm1['servidores_minimos'], $mmc['servidores_minimos']);

        foreach (['rho', 'p0', 'p_espera', 'lq', 'l', 'wq_horas', 'wq_minutos', 'w_horas', 'w_minutos'] as $clave) {
            $this->assertEqualsWithDelta($mm1[$clave], $mmc[$clave], 1e-9, "Diferencia en {$clave}");
        }
    }

    public static function utilizationThresholds(): array
    {
        return [
            [69, 'optimo'], [70, 'estable'], [85, 'estable'], [86, 'alerta_congestion'],
            [99, 'alerta_congestion'], [100, 'saturado'], [125, 'saturado'],
        ];
    }

    #[DataProvider('utilizationThresholds')]
    public function test_utilization_thresholds_apply_without_capping_the_percentage(int $percent, string $state): void
    {
        $service = $this->mathService();
        foreach ([$service->calcularMM1($percent, 100), $service->calcularMMc($percent, 50, 2)] as $metrics) {
            $this->assertSame($state, $metrics['estado']);
            $this->assertEqualsWithDelta($percent, $metrics['rho_porcentaje'], 1e-9);
        }
    }

    public function test_mmc_calculates_exact_erlang_c_metrics_for_known_multi_server_case(): void
    {
        $service = $this->mathService();
        $resultado = $service->calcularMMc(4.0, 3.0, 2);

        $this->assertSame('M/M/c', $resultado['modelo']);
        $this->assertTrue($resultado['es_estable']);
        $this->assertSame('optimo', $resultado['estado']);
        $this->assertEqualsWithDelta(2.0 / 3.0, $resultado['rho'], 1e-6);
        $this->assertEqualsWithDelta(0.2, $resultado['p0'], 1e-6);
        $this->assertEqualsWithDelta(8.0 / 15.0, $resultado['p_espera'], 1e-6);
        $this->assertEqualsWithDelta(16.0 / 15.0, $resultado['lq'], 1e-6);
        $this->assertEqualsWithDelta(2.4, $resultado['l'], 1e-6);
        $this->assertEqualsWithDelta(16.0, $resultado['wq_minutos'], 1e-6);
        $this->assertEqualsWithDelta(36.0, $resultado['w_minutos'], 1e-6);
        $this->assertSame(2, $resultado['servidores_minimos']);
    }

    public function test_diagnostic_traffic_light_thresholds(): void
    {
        $service = $this->mathService();

        $this->assertSame('optimo', $service->calcularMM1(1.95, 3.0)['estado']);
        $this->assertSame('estable', $service->calcularMM1(2.10, 3.0)['estado']);
        $this->assertSame('estable', $service->calcularMM1(2.55, 3.0)['estado']);
        $this->assertSame('alerta_congestion', $service->calcularMM1(2.60, 3.0)['estado']);
        $this->assertSame('saturado', $service->calcularMM1(3.00, 3.0)['estado']);
    }

    public function test_saturated_system_does_not_throw_and_computes_minimum_servers(): void
    {
        $service = $this->mathService();

        $exacto = $service->calcularMMc(6.0, 3.0, 2);
        $this->assertFalse($exacto['es_estable']);
        $this->assertSame('saturado', $exacto['estado']);
        $this->assertSame('Saturado (Inestable)', $exacto['etiqueta']);
        $this->assertNull($exacto['lq']);
        $this->assertNull($exacto['wq_minutos']);
        $this->assertSame(3, $exacto['servidores_minimos']);
        $this->assertStringContainsString('3 médicos activos', $exacto['recomendacion']);

        $sobrecargado = $service->calcularMMc(7.5, 2.0, 3);
        $this->assertFalse($sobrecargado['es_estable']);
        $this->assertSame('saturado', $sobrecargado['estado']);
        $this->assertSame(4, $sobrecargado['servidores_minimos']);

        $bordeFlotante = $service->calcularMMc(0.6, 0.2, 3);
        $this->assertFalse($bordeFlotante['es_estable']);
        $this->assertSame('saturado', $bordeFlotante['estado']);
        $this->assertSame(4, $bordeFlotante['servidores_minimos']);

        $sinServidores = $service->calcularMMc(2.0, 3.0, 0);
        $this->assertFalse($sinServidores['es_estable']);
        $this->assertSame('saturado', $sinServidores['estado']);
        $this->assertNull($sinServidores['rho']);
        $this->assertSame(1, $sinServidores['servidores_minimos']);
    }

    public function test_zero_flow_or_zero_service_rate_returns_sin_flujo_without_division_by_zero(): void
    {
        $service = $this->mathService();

        $sinLlegadas = $service->calcularMMc(0.0, 3.0, 2);
        $this->assertSame('sin_flujo', $sinLlegadas['estado']);
        $this->assertSame('Sin flujo suficiente', $sinLlegadas['etiqueta']);
        $this->assertFalse($sinLlegadas['es_estable']);
        $this->assertNull($sinLlegadas['lq']);
        $this->assertNull($sinLlegadas['l']);
        $this->assertNull($sinLlegadas['wq_minutos']);
        $this->assertNull($sinLlegadas['w_minutos']);
        $this->assertSame(0, $sinLlegadas['servidores_minimos']);

        $sinServicio = $service->calcularMM1(2.0, 0.0);
        $this->assertSame('sin_flujo', $sinServicio['estado']);
        $this->assertNull($sinServicio['rho']);
        $this->assertNull($sinServicio['lq']);
    }

    public function test_simulator_comparative_scenarios_cover_stable_and_saturated_cases(): void
    {
        $service = $this->mathService();

        $estable = $service->simularEscenariosComparativos(4.0, 20, 2);
        $this->assertCount(3, $estable['comparativa']);
        $this->assertSame([2, 3, 4], array_map(
            static fn (array $e): int => $e['metricas']['servidores'],
            $estable['comparativa'],
        ));
        $this->assertGreaterThan(
            $estable['comparativa'][1]['metricas']['wq_minutos'],
            $estable['comparativa'][0]['metricas']['wq_minutos'],
        );

        $saturado = $service->simularEscenariosComparativos(7.0, 20, 1);
        $this->assertSame('saturado', $saturado['actual']['estado']);
        $this->assertSame(3, $saturado['actual']['servidores_minimos']);
        $this->assertSame([1, 3, 4, 5], array_map(
            static fn (array $e): int => $e['metricas']['servidores'],
            $saturado['comparativa'],
        ));
        $this->assertTrue($saturado['comparativa'][1]['metricas']['es_estable']);
    }

    public function test_effective_schedule_hours_subtracts_breaks_and_partial_and_full_day_blocks(): void
    {
        $service = $this->mathService();
        // 2026-11-16 es lunes (ISO 1), 2026-11-17 es martes (ISO 2), 2026-11-18 es miércoles (ISO 3).
        $horarios = [
            ['dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'pausa_inicio' => '10:00:00', 'pausa_fin' => '10:30:00'],
            ['dia_semana' => 2, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'pausa_inicio' => null, 'pausa_fin' => null],
            ['dia_semana' => 3, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'pausa_inicio' => null, 'pausa_fin' => null],
        ];
        $bloqueos = [
            // Bloqueo parcial lunes 09:00-11:00 solapa pausa 10:00-10:30 => quedan [08:00-09:00] y [11:00-12:00] = 2.0h.
            ['fecha_bloqueo' => '2026-11-16', 'hora_inicio' => '09:00:00', 'hora_fin' => '11:00:00'],
            // Bloqueo de jornada completa el martes => 0.0h.
            ['fecha_bloqueo' => '2026-11-17', 'hora_inicio' => null, 'hora_fin' => null],
        ];

        $horas = $service->calcularHorasEfectivasMedico($horarios, $bloqueos, '2026-11-16', '2026-11-18');
        // Lunes 2.0h + Martes 0.0h + Miércoles 4.0h = 6.0h.
        $this->assertEqualsWithDelta(6.0, $horas, 1e-4);
    }

    public function test_obtener_datos_panel_executes_only_select_queries_and_computes_real_and_theoretical_metrics(): void
    {
        $executedSql = [];
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('scalar')
            ->once()
            ->with('SELECT public.fecha_clinica()::text')
            ->andReturn('2026-11-19');

        $connection->shouldReceive('select')
            ->andReturnUsing(function (string $sql, array $bindings = []) use (&$executedSql): array {
                $executedSql[] = $sql;

                if (str_contains($sql, 'FROM public.especialidades')) {
                    return [
                        (object) ['cod_esp' => 'ESP000001', 'nombre_especialidad' => 'Medicina General', 'duracion_consulta_min' => 20, 'estado' => 'Activo'],
                        (object) ['cod_esp' => 'ESP000002', 'nombre_especialidad' => 'Cardiología', 'duracion_consulta_min' => 30, 'estado' => 'Activo'],
                    ];
                }
                if (str_contains($sql, 'FROM public.medicos')) {
                    return [
                        (object) ['cod_med' => 'MED000001', 'nombre_completo' => 'Dra. Lucía Flores', 'duracion_consulta_min' => 20, 'estado_medico' => 'Activo', 'estado_usuario' => 'Activo', 'estado_rol' => 'Activo', 'cod_rol' => 'ROL003'],
                        (object) ['cod_med' => 'MED000002', 'nombre_completo' => 'Dr. Carlos Méndez', 'duracion_consulta_min' => 20, 'estado_medico' => 'Activo', 'estado_usuario' => 'Activo', 'estado_rol' => 'Activo', 'cod_rol' => 'ROL003'],
                        (object) ['cod_med' => 'MED000003', 'nombre_completo' => 'Dra. Sofía Paredes', 'duracion_consulta_min' => 30, 'estado_medico' => 'Activo', 'estado_usuario' => 'Activo', 'estado_rol' => 'Activo', 'cod_rol' => 'ROL003'],
                    ];
                }
                if (str_contains($sql, 'FROM public.medico_especialidades')) {
                    return [
                        (object) ['cod_med' => 'MED000001', 'cod_esp' => 'ESP000001', 'duracion_vinculo' => 20, 'estado_vinculo' => 'Activo', 'nombre_especialidad' => 'Medicina General', 'duracion_especialidad' => 20, 'estado_especialidad' => 'Activo'],
                        (object) ['cod_med' => 'MED000002', 'cod_esp' => 'ESP000001', 'duracion_vinculo' => 20, 'estado_vinculo' => 'Activo', 'nombre_especialidad' => 'Medicina General', 'duracion_especialidad' => 20, 'estado_especialidad' => 'Activo'],
                        (object) ['cod_med' => 'MED000003', 'cod_esp' => 'ESP000002', 'duracion_vinculo' => 30, 'estado_vinculo' => 'Activo', 'nombre_especialidad' => 'Cardiología', 'duracion_especialidad' => 30, 'estado_especialidad' => 'Activo'],
                    ];
                }
                if (str_contains($sql, 'FROM public.horarios')) {
                    // 2026-11-16 (lunes ISO 1): 4h para MED000001 y MED000002; MED000003 sin horario (activará respaldo 8h/día).
                    return [
                        (object) ['cod_med' => 'MED000001', 'dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'pausa_inicio' => null, 'pausa_fin' => null],
                        (object) ['cod_med' => 'MED000002', 'dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'pausa_inicio' => null, 'pausa_fin' => null],
                    ];
                }
                if (str_contains($sql, 'FROM public.bloqueos_agenda')) {
                    return [];
                }
                if (str_contains($sql, "WHERE c.estado = 'En Espera'")) {
                    $sample = [
                        'total' => 3, 'en_atencion' => 0, 'validos' => 2, 'dentro' => 1,
                        'moderada' => 1, 'elevada' => 0, 'promedio' => 12.0, 'maximo' => 20.0,
                    ];

                    return array_map(static fn (array $scope): object => (object) ($scope + $sample), [
                        ['cod_esp' => null, 'cod_med' => null],
                        ['cod_esp' => 'ESP000001', 'cod_med' => null],
                        ['cod_esp' => null, 'cod_med' => 'MED000001'],
                    ]);
                }
                if (str_contains($sql, 'FROM public.citas')) {
                    $observed = [
                        'muestra_espera' => 1, 'suma_espera' => 14.0, 'muestra_consulta' => 1, 'suma_consulta' => 25.0,
                        'suma_programada' => 20.0, 'muestra_total' => 1, 'suma_total' => 39.0,
                    ];

                    return array_map(static fn (array $row): object => (object) ($row + $observed), [
                        // ESP000001: 16 llegadas totales sobre H promedio = 4h => lambda = 4 pac/h, mu = 3 pac/h, c = 2 => Wq = 16 min, W = 36 min.
                        ['cod_esp' => 'ESP000001', 'cod_med' => 'MED000001', 'fecha' => '2026-11-16', 'llegadas' => 8, 'atendidas' => 8, 'muestra_empirica' => 8, 'suma_w_empirico_min' => 280.0],
                        ['cod_esp' => 'ESP000001', 'cod_med' => 'MED000002', 'fecha' => '2026-11-16', 'llegadas' => 8, 'atendidas' => 8, 'muestra_empirica' => 8, 'suma_w_empirico_min' => 240.0],
                        // ESP000002 (MED000003 sin horarios): 20 llegadas en 1 día => respaldo 8h => lambda = 2.5 pac/h, mu = 2.0 pac/h => saturado (c_min = 2).
                        ['cod_esp' => 'ESP000002', 'cod_med' => 'MED000003', 'fecha' => '2026-11-16', 'llegadas' => 20, 'atendidas' => 18, 'muestra_empirica' => 10, 'suma_w_empirico_min' => 520.0],
                    ]);
                }

                $this->fail('Consulta SQL inesperada: '.$sql);
            });

        $rolesQuery = Mockery::mock(Builder::class);
        $rolesQuery->shouldReceive('where')->with('cod_rol', 'ROL004')->once()->andReturnSelf();
        $rolesQuery->shouldReceive('where')->with('estado', 'Activo')->once()->andReturnSelf();
        $rolesQuery->shouldReceive('exists')->once()->andReturnTrue();
        $connection->shouldReceive('table')->with('roles')->once()->andReturn($rolesQuery);

        $connection->shouldNotReceive('insert');
        $connection->shouldNotReceive('update');
        $connection->shouldNotReceive('delete');
        $connection->shouldNotReceive('statement');

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->with('clinical')->andReturn($connection);

        $this->admin();
        $guard = new ClinicalRoleGuard(app('auth'), $database);

        $service = new ColaMetricaService($database, $guard);
        $datos = $service->obtenerDatosPanel([
            'fecha_inicio' => '2026-11-16',
            'fecha_fin' => '2026-11-16',
        ]);

        foreach ($executedSql as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(SELECT|WITH)\b/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE)\b/i', $sql);
        }

        $this->assertSame(3, $datos['resumen_global']['en_espera_hoy']);
        $this->assertSame(50.0, $datos['espera_actual']['global']['porcentaje']);
        $this->assertSame(14.0, $datos['resumen_global']['observado']['espera']);
        $this->assertSame(3, $datos['evolucion_espera'][0]['sample']);

        $medGeneral = collect($datos['por_especialidad'])->firstWhere('cod_esp', 'ESP000001');
        $this->assertSame(2, $medGeneral['servidores']);
        $this->assertEqualsWithDelta(4.0, $medGeneral['metricas']['lambda'], 1e-6);
        $this->assertEqualsWithDelta(16.0, $medGeneral['metricas']['wq_minutos'], 1e-6);
        $this->assertEqualsWithDelta(36.0, $medGeneral['metricas']['w_minutos'], 1e-6);

        $cardiologia = collect($datos['por_especialidad'])->firstWhere('cod_esp', 'ESP000002');
        $this->assertSame('respaldo_8h', $cardiologia['base_horas']);
        $this->assertSame('saturado', $cardiologia['metricas']['estado']);
        $this->assertSame(2, $cardiologia['metricas']['servidores_minimos']);

        $lucia = collect($datos['por_medico'])->firstWhere('cod_med', 'MED000001');
        $this->assertEqualsWithDelta(35.0, $lucia['w_empirico_minutos'], 1e-6);
        $this->assertSame(3, $lucia['en_espera_hoy']);

        $this->assertNotEmpty($datos['alertas']);
        $this->assertSame('saturado', $datos['alertas'][0]['estado']);
    }

    public function test_guest_cannot_access_queue_analysis_route(): void
    {
        $this->get('/administracion/colas')->assertRedirect(route('login'));
    }

    public static function nonAdminRoles(): array
    {
        return [
            'paciente' => ['ROL001'],
            'secretaria' => ['ROL002'],
            'medico' => ['ROL003'],
        ];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_roles_are_forbidden_from_queue_analysis(string $role): void
    {
        $this->authorizationRole = $role;

        $service = Mockery::mock(ColaMetricaService::class);
        $service->shouldNotReceive('obtenerDatosPanel');
        $this->app->instance(ColaMetricaService::class, $service);

        $this->admin()->get('/administracion/colas')->assertForbidden();
    }

    public function test_authenticated_admin_renders_kpis_tables_and_what_if_simulator(): void
    {
        $math = $this->mathService();
        $capturedFiltros = null;

        $service = Mockery::mock(ColaMetricaService::class);
        $service->shouldReceive('obtenerDatosPanel')
            ->once()
            ->andReturnUsing(function (array $filtros) use ($math, &$capturedFiltros): array {
                $capturedFiltros = $filtros;
                $simLambda = isset($filtros['sim_lambda']) ? (float) $filtros['sim_lambda'] : 4.0;
                $simDuracion = isset($filtros['sim_duracion']) ? (int) $filtros['sim_duracion'] : 20;
                $simServidores = isset($filtros['sim_servidores']) ? (int) $filtros['sim_servidores'] : 2;

                return $this->panelFixture($math, $simLambda, $simDuracion, $simServidores);
            });
        $this->app->instance(ColaMetricaService::class, $service);

        $response = $this->admin()->get('/administracion/colas?sim_lambda=4&sim_duracion=20&sim_servidores=2');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Análisis de Teoría de Colas')
            ->assertSee('Medicina General')
            ->assertSee('Dra. Lucía Flores')
            ->assertSee('W empírico: Check-in → Cierre')
            ->assertSee('Simulador interactivo «What-If» (M/M/1 y M/M/c)')
            ->assertSee('16.0 min')
            ->assertSee('36.0 min')
            ->assertSee('53.3%')
            ->assertSee('+1 médico (c = 3)')
            ->assertSee('Notación de Kendall (M/M/1 y M/M/c)')
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('@endsection');

        $this->assertSame('4', (string) ($capturedFiltros['sim_lambda'] ?? ''));
        $this->assertSame('20', (string) ($capturedFiltros['sim_duracion'] ?? ''));
        $this->assertSame('2', (string) ($capturedFiltros['sim_servidores'] ?? ''));
    }

    public function test_what_if_simulator_renders_saturated_warning_and_minimum_servers(): void
    {
        $math = $this->mathService();
        $service = Mockery::mock(ColaMetricaService::class);
        $service->shouldReceive('obtenerDatosPanel')
            ->once()
            ->andReturn($this->panelFixture($math, 9.0, 20, 2));
        $this->app->instance(ColaMetricaService::class, $service);

        $this->admin()->get('/administracion/colas?sim_lambda=9&sim_duracion=20&sim_servidores=2')
            ->assertOk()
            ->assertSee('Saturado (Inestable)')
            ->assertSee('Saturado (∞)')
            ->assertSee('Mínimo estable (c = 4)');
    }

    public static function invalidQueryInputs(): array
    {
        return [
            'fecha_fin anterior' => [['fecha_inicio' => '2026-11-15', 'fecha_fin' => '2026-11-10'], 'fecha_fin'],
            'rango mayor a 366 dias' => [['fecha_inicio' => '2024-01-01', 'fecha_fin' => '2026-11-19'], 'fecha_fin'],
            'especialidad arreglo' => [['especialidad' => ['ESP000001']], 'especialidad'],
            'medico invalido' => [['medico' => 'MED-01;DROP'], 'medico'],
            'sim_lambda negativo' => [['sim_lambda' => '-1'], 'sim_lambda'],
            'sim_duracion muy corta' => [['sim_duracion' => '2'], 'sim_duracion'],
            'sim_servidores fuera de rango' => [['sim_servidores' => '99'], 'sim_servidores'],
        ];
    }

    #[DataProvider('invalidQueryInputs')]
    public function test_invalid_query_parameters_are_rejected_with_validation_errors(array $query, string $expectedErrorField): void
    {
        $service = Mockery::mock(ColaMetricaService::class);
        $service->shouldNotReceive('obtenerDatosPanel');
        $this->app->instance(ColaMetricaService::class, $service);

        $this->admin()
            ->from('/administracion/colas')
            ->get('/administracion/colas?'.http_build_query($query))
            ->assertRedirect('/administracion/colas')
            ->assertSessionHasErrors($expectedErrorField);
    }

    public function test_clinical_error_renders_recoverable_admin_error_screen(): void
    {
        $service = Mockery::mock(ColaMetricaService::class);
        $service->shouldReceive('obtenerDatosPanel')
            ->once()
            ->andThrow(new ClinicalOperationException(503, 'Base de datos clínica no disponible.', 'SERVICIO_NO_DISPONIBLE'));
        $this->app->instance(ColaMetricaService::class, $service);

        $this->admin()->get('/administracion/colas')
            ->assertStatus(503)
            ->assertSee('No se pudo completar')
            ->assertSessionHas('cod_usu', 'USU000004');
    }

    public function test_container_resolves_cola_metrica_service_with_real_dependencies_for_http_request(): void
    {
        $connection = Mockery::mock(Connection::class);
        $rolesQuery = Mockery::mock(Builder::class);
        $rolesQuery->shouldReceive('where')->with('cod_rol', 'ROL004')->once()->andReturnSelf();
        $rolesQuery->shouldReceive('where')->with('estado', 'Activo')->once()->andReturnSelf();
        $rolesQuery->shouldReceive('exists')->once()->andReturnTrue();
        $connection->shouldReceive('table')->with('roles')->once()->andReturn($rolesQuery);
        $connection->shouldReceive('scalar')->once()->with('SELECT public.fecha_clinica()::text')->andReturn('2026-11-19');
        $connection->shouldReceive('select')->andReturnUsing(static fn (string $sql): array => str_contains($sql, 'GROUPING SETS') ? [(object) [
            'cod_esp' => null, 'cod_med' => null, 'total' => 0, 'en_atencion' => 0, 'validos' => 0,
            'dentro' => 0, 'moderada' => 0, 'elevada' => 0, 'promedio' => null, 'maximo' => null,
        ]] : []);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->with('clinical')->andReturn($connection);
        $this->app->instance(DatabaseManager::class, $database);

        $this->admin()->get('/administracion/colas')
            ->assertOk()
            ->assertSee('Análisis de Teoría de Colas')
            ->assertSee('Sin flujo suficiente');
    }

    private function mathService(): ColaMetricaService
    {
        return $this->app->make(ColaMetricaService::class);
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

    /**
     * @return array<string, mixed>
     */
    private function panelFixture(ColaMetricaService $math, float $simLambda, int $simDuracion, int $simServidores): array
    {
        $metricasEsp = $math->calcularMMc(4.0, 3.0, 2);
        $metricasMed = $math->calcularMM1(2.0, 3.0);

        return [
            'fecha_clinica_hoy' => '2026-11-19',
            'filtros_aplicados' => [
                'fecha_inicio' => '2026-10-21',
                'fecha_fin' => '2026-11-19',
                'especialidad' => null,
                'medico' => null,
            ],
            'catalogos' => [
                'especialidades' => [
                    ['codEsp' => 'ESP000001', 'nombreEspecialidad' => 'Medicina General', 'duracionConsultaMin' => 20, 'estado' => 'Activo'],
                ],
                'medicos' => [
                    ['codMed' => 'MED000001', 'nombreCompleto' => 'Dra. Lucía Flores', 'estado' => 'Activo'],
                ],
            ],
            'resumen_global' => [
                'ambito' => 'Clínica completa',
                'total_llegadas' => 160,
                'total_atendidas' => 155,
                'horas_efectivas' => 40.0,
                'base_horas' => 'horarios',
                'en_espera_hoy' => 4,
                'w_empirico_minutos' => 34.5,
                'muestra_empirica' => 120,
                'metricas' => $metricasEsp,
            ],
            'por_especialidad' => [
                [
                    'cod_esp' => 'ESP000001',
                    'nombre' => 'Medicina General',
                    'estado_catalogo' => 'Activo',
                    'servidores' => 2,
                    'llegadas' => 160,
                    'atendidas' => 155,
                    'horas_efectivas' => 40.0,
                    'base_horas' => 'horarios',
                    'mu_promedio' => 3.0,
                    'duracion_sugerida' => 20,
                    'en_espera_hoy' => 4,
                    'w_empirico_minutos' => 34.5,
                    'muestra_empirica' => 120,
                    'metricas' => $metricasEsp,
                ],
            ],
            'por_medico' => [
                [
                    'cod_med' => 'MED000001',
                    'nombre' => 'Dra. Lucía Flores',
                    'estado_medico' => 'Activo',
                    'especialidades_texto' => 'Medicina General',
                    'duracion_minutos' => 20,
                    'llegadas' => 80,
                    'atendidas' => 78,
                    'horas_efectivas' => 40.0,
                    'base_horas' => 'horarios',
                    'en_espera_hoy' => 2,
                    'w_empirico_minutos' => 54.0,
                    'muestra_empirica' => 60,
                    'metricas' => $metricasMed,
                ],
            ],
            'alertas' => [],
            'simulacion' => $math->simularEscenariosComparativos($simLambda, $simDuracion, $simServidores),
            'simulacion_es_referencia' => false,
        ];
    }
}
