<?php

namespace Tests\Feature;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Administration\AdministrationService;
use App\Services\Administration\AdministrativePdfService;
use App\Services\Administration\BitacoraService;
use App\Services\Auth\AccountStatusService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdministrativeFixtures;
use Tests\Support\BitacoraFixtures as F;
use Tests\Support\ClinicalVisualSnapshots;
use Tests\TestCase;

class BitacorasTest extends TestCase
{
    private string $role = 'ROL004';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $accounts = Mockery::mock(AccountStatusService::class);
        $accounts->shouldReceive('requireActive')->andReturnUsing(fn (): object => (object) [
            'cod_usu' => 'USU000004', 'nombre_completo' => 'María Administradora', 'email' => 'maria@example.test',
            'cod_rol' => $this->role, 'nombre_rol' => 'Administrador', 'usuario_estado' => 'Activo',
            'rol_estado' => 'Activo', 'perfil_presente' => true, 'perfil_estado' => 'Activo',
            'email_verificado' => true, 'bloqueado_hasta' => null,
        ]);
        $this->app->instance(AccountStatusService::class, $accounts);
    }

    public static function sections(): array
    {
        return [['pacientes'], ['secretarias'], ['medicos']];
    }

    #[DataProvider('sections')]
    public function test_each_section_has_filters_details_profile_links_and_a_complete_export_link(string $section): void
    {
        $filters = ['desde' => '2026-11-01', 'hasta' => '2026-11-20', 'page' => '1'];
        $data = F::listing($section);
        $data['filters'] += ['page' => '1'];
        $data['page'] = 1;
        $service = Mockery::mock(BitacoraService::class);
        $service->shouldReceive('listing')->once()->with($section, $filters)->andReturn($data);
        $this->app->instance(BitacoraService::class, $service);
        $response = $this->admin()->get('/administracion/bitacoras/'.$section.'?'.http_build_query($filters))->assertOk()
            ->assertSee('Bitácoras')->assertSee('Tipo de actividad')->assertSee('Ver cambios')->assertSee('Sin responsable registrado')
            ->assertSee('Exportar PDF')->assertSee($data['records'][0]['people'][0]['name'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $export = route('administracion.bitacoras.pdf', ['seccion' => $section] + array_diff_key($data['filters'], ['page' => true]));
        $response->assertSee(e($export), false);
        ClinicalVisualSnapshots::save('bitacora-'.$section, $response->getContent());
    }

    public function test_entry_point_opens_patients_and_unknown_sections_are_not_routed(): void
    {
        $this->admin()->get('/administracion/bitacoras')->assertRedirect(route('administracion.bitacoras.show', ['seccion' => 'pacientes']));
        $this->get('/administracion/bitacoras/usuarios')->assertNotFound();
    }

    public function test_empty_and_unavailable_states_do_not_invent_activity(): void
    {
        $service = Mockery::mock(BitacoraService::class);
        $service->shouldReceive('listing')->once()->andReturn(F::listing('pacientes', 0));
        $service->shouldReceive('listing')->once()->andThrow(new ClinicalOperationException(500, 'No se pudo consultar la bitácora.'));
        $this->app->instance(BitacoraService::class, $service);
        $response = $this->admin()->get('/administracion/bitacoras/pacientes')->assertOk()->assertSee('No hay movimientos con estos filtros.');
        ClinicalVisualSnapshots::save('bitacora-vacia', $response->getContent());
        $this->get('/administracion/bitacoras/pacientes')->assertStatus(500)->assertSee('No se pudo consultar la bitácora.');
    }

    public static function invalidFilters(): array
    {
        return [
            [['desde' => '2026-02-30', 'hasta' => '2026-03-01'], 'desde'],
            [['desde' => '2026-11-20', 'hasta' => '2026-11-01'], 'hasta'],
            [['desde' => '2026-11-01'], 'hasta'],
            [['desde' => '2026-01-01', 'hasta' => '2027-01-02'], 'hasta'],
            [['persona' => 'MED000001'], 'persona'], [['accion' => 'DROP TABLE'], 'accion'],
            [['entidad' => 'configuracion_seguridad'], 'entidad'], [['relacion' => 'todos'], 'relacion'],
            [['q' => ['malformado']], 'q'], [['page' => '-1'], 'page'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_never_reach_the_query_or_pdf_renderer(array $filters, string $field): void
    {
        $service = Mockery::mock(BitacoraService::class);
        $service->shouldNotReceive('listing');
        $this->app->instance(BitacoraService::class, $service);
        $this->admin()->get('/administracion/bitacoras/pacientes/pdf?'.http_build_query($filters))
            ->assertRedirect(route('administracion.bitacoras.show', ['seccion' => 'pacientes']))->assertSessionHasErrors($field);
    }

    #[DataProvider('sections')]
    public function test_each_section_downloads_a_real_pdf_with_all_filtered_records(string $section): void
    {
        $filters = ['desde' => '2026-11-01', 'hasta' => '2026-11-20', 'page' => '9'];
        $service = Mockery::mock(BitacoraService::class);
        $service->shouldReceive('listing')->once()->with($section, $filters, true)->andReturn(F::listing($section, 35));
        $this->app->instance(BitacoraService::class, $service);
        $response = $this->admin()->get('/administracion/bitacoras/'.$section.'/pdf?'.http_build_query($filters))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->savePdf('bitacora-'.$section.'.pdf', $response->getContent());
    }

    #[DataProvider('sections')]
    public function test_each_profile_downloads_an_administrative_pdf(string $section): void
    {
        $record = match ($section) {
            'pacientes' => AdministrativeFixtures::patient(), 'medicos' => AdministrativeFixtures::doctor(),
            'secretarias' => AdministrativeFixtures::secretary(),
        };
        $code = $record[match ($section) {
            'pacientes' => 'codPac', 'medicos' => 'codMed', 'secretarias' => 'codSec'
        }];
        $path = ($section === 'pacientes' ? 'administracion/pacientes/' : 'personal/'.$section.'/').$code;
        $service = Mockery::mock(AdministrationService::class);
        $service->shouldReceive('request')->once()->with('GET', $path)->andReturn($record);
        $this->app->instance(AdministrationService::class, $service);
        $response = $this->admin()->get('/administracion/bitacoras/'.$section.'/perfiles/'.$code.'/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->savePdf('ficha-'.$section.'.pdf', $response->getContent());
    }

    public function test_export_limit_is_an_explicit_error_and_never_a_truncated_pdf(): void
    {
        $service = Mockery::mock(BitacoraService::class);
        $service->shouldReceive('listing')->once()->andThrow(new ClinicalOperationException(422, 'Reduce el período para exportar todos los resultados.', 'REPORTE_EXTENSO'));
        $renderer = Mockery::mock(AdministrativePdfService::class);
        $renderer->shouldNotReceive('audit');
        $this->app->instance(BitacoraService::class, $service);
        $this->app->instance(AdministrativePdfService::class, $renderer);
        $this->admin()->get('/administracion/bitacoras/pacientes/pdf')->assertStatus(422)->assertSee('exportar todos los resultados');
    }

    public function test_empty_report_is_still_a_valid_pdf(): void
    {
        $service = Mockery::mock(BitacoraService::class);
        $service->shouldReceive('listing')->once()->with('pacientes', [], true)->andReturn(F::listing('pacientes', 0));
        $this->app->instance(BitacoraService::class, $service);
        $response = $this->admin()->get('/administracion/bitacoras/pacientes/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->savePdf('bitacora-vacia.pdf', $response->getContent());
    }

    public function test_validation_redirect_can_render_even_with_malformed_old_search_input(): void
    {
        $service = Mockery::mock(BitacoraService::class);
        $service->shouldReceive('listing')->once()->with('pacientes', [])->andReturn(F::listing());
        $this->app->instance(BitacoraService::class, $service);
        $this->admin()->get('/administracion/bitacoras/pacientes?q[]=invalid')->assertRedirect()->assertSessionHasErrors('q');
        $this->withSession(['_old_input' => ['q' => ['invalid']]])->get('/administracion/bitacoras/pacientes')
            ->assertOk()->assertSee('name="q"', false)->assertDontSee('value="Array"', false);
    }

    public function test_profile_pdf_rejects_a_code_from_a_different_section(): void
    {
        $service = Mockery::mock(AdministrationService::class);
        $service->shouldNotReceive('request');
        $this->app->instance(AdministrationService::class, $service);
        $this->admin()->get('/administracion/bitacoras/medicos/perfiles/PAC000001/pdf')->assertNotFound();
    }

    public function test_labels_and_audited_values_are_rendered_as_text(): void
    {
        $data = F::listing();
        $data['records'][0]['people'][0]['name'] = '<script>alert(1)</script>';
        $data['records'][0]['changes'][0]['after'] = '<img src="https://example.test/private">';
        $service = Mockery::mock(BitacoraService::class);
        $service->shouldReceive('listing')->once()->andReturn($data);
        $this->app->instance(BitacoraService::class, $service);
        $this->admin()->get('/administracion/bitacoras/pacientes')->assertOk()
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(', false)->assertDontSee('<img src=', false);
    }

    public static function unauthorizedRoles(): array
    {
        return [['ROL001'], ['ROL002'], ['ROL003']];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_non_administrators_cannot_read_or_export_any_section(string $role): void
    {
        $service = Mockery::mock(BitacoraService::class);
        $service->shouldNotReceive('listing');
        $this->app->instance(BitacoraService::class, $service);
        $this->admin($role);
        foreach (array_keys(BitacoraService::SECTIONS) as $section) {
            $this->get('/administracion/bitacoras/'.$section)->assertForbidden();
            $this->get('/administracion/bitacoras/'.$section.'/pdf')->assertForbidden();
            $code = match ($section) {
                'pacientes' => 'PAC000001', 'medicos' => 'MED000001', 'secretarias' => 'SEC000001'
            };
            $this->get('/administracion/bitacoras/'.$section.'/perfiles/'.$code.'/pdf')->assertForbidden();
        }
    }

    private function savePdf(string $name, string $contents): void
    {
        if ($directory = getenv('CLINICAL_VISUAL_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name, $contents);
        }
    }

    private function admin(string $role = 'ROL004'): static
    {
        $this->role = $role;
        $user = (new Usuario)->forceFill(['cod_usu' => 'USU000004', 'cod_rol' => $role, 'estado' => 'Activo',
            'nombre_completo' => 'María Administradora', 'email' => 'maria@example.test']);

        return $this->actingAs($user, 'web')->withSession([
            'auth_authority' => 'laravel', 'auth_expires_at' => now()->addMinutes(120)->toIso8601String(),
            'cod_usu' => 'USU000004', 'cod_rol' => $role, 'nombre_usuario' => 'María Administradora', 'email_usuario' => 'maria@example.test',
        ]);
    }
}
