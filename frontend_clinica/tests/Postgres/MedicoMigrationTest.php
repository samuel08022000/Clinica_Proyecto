<?php

namespace Tests\Postgres;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Administration\AdministrationService;
use App\Services\Medico\MedicoAnalisisService;
use App\Services\Medico\MedicoAtencionService;
use App\Services\Medico\MedicoDerivacionService;
use App\Services\Paciente\PatientAppointmentService;
use App\Services\Reception\ReceptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Integración real del módulo Médico contra la base PostgreSQL aislada. */
final class MedicoMigrationTest extends TestCase
{
    private PDO $owner;

    private string $fixturePassword;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLINICAL_PG_TESTS') !== '1') {
            $this->markTestSkipped('Requiere PostgreSQL clínico aislado.');
        }
        $this->fixturePassword = bin2hex(random_bytes(18));
        $this->withoutVite();
        $this->owner = new PDO(
            'pgsql:host=127.0.0.1;port=35443;dbname=clinica_laravel_phase1_tests',
            'clinica_owner',
            getenv('CLINICAL_OWNER_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    protected function tearDown(): void
    {
        Auth::guard('web')->logout();
        parent::tearDown();
    }

    #[Test]
    public function physician_reads_only_own_ordered_agenda_detail_and_paginated_history(): void
    {
        $fixture = $this->medicalFixture();
        $first = $this->book($fixture, 1, 0);
        $second = $this->book($fixture, 1, 1);
        $this->login($fixture['doctorA']['codUsu']);
        $service = app(MedicoAtencionService::class);
        $date = $this->date(1);

        $agenda = $service->agenda($date);
        $this->assertSame($date, $agenda['fecha']);
        $this->assertSame([$first['codCit'], $second['codCit']], array_column($agenda['citas'], 'codCit'));
        $this->assertSame($first['codCit'], $service->appointment($first['codCit'])['codCit']);
        $this->expectDomain(400, fn () => $service->agenda(''));
        $this->expectDomain(400, fn () => $service->agenda('2026-02-30'));

        $foreign = $this->book($fixture, 1, 0, 'B');
        $this->login($fixture['doctorA']['codUsu']);
        $this->expectDomain(404, fn () => $service->appointment($foreign['codCit']));
        $this->makeAttended($fixture, $first);
        $this->login($fixture['doctorA']['codUsu']);
        $history = $service->history(['desde' => $this->date(), 'hasta' => $this->date(1), 'page' => '0', 'size' => '1']);
        $this->assertSame(0, $history['page']);
        $this->assertSame(1, $history['size']);
        $this->assertSame($first['codCit'], $history['citas'][0]['codCit']);
        $this->assertFalse($history['hayMas']);
        $this->expectDomain(400, fn () => $service->history(['desde' => $this->date(2), 'hasta' => $this->date(1)]));
    }

    #[Test]
    public function attention_requires_checkin_ownership_and_single_transition_with_audit(): void
    {
        $fixture = $this->medicalFixture();
        $confirmed = $this->book($fixture, 1, 0);
        $this->login($fixture['doctorA']['codUsu']);
        $service = app(MedicoAtencionService::class);
        $this->expectDomain(409, fn () => $service->finish($confirmed['codCit']));

        $waiting = $this->checkedIn($fixture, $this->book($fixture, 1, 1));
        $this->login($fixture['doctorB']['codUsu']);
        $this->expectDomain(404, fn () => $service->finish($waiting['codCit']));
        $this->login($fixture['doctorA']['codUsu']);
        $finished = $service->finish($waiting['codCit']);
        $this->assertSame('Atendida', $finished['estado']);
        $this->assertSame($fixture['doctorA']['codUsu'], $finished['atendidaPorUsu']);
        $this->assertNotNull($finished['fechaAtencion']);
        $this->expectDomain(409, fn () => $service->finish($waiting['codCit']));
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM bitacora WHERE nombre_tabla='citas' AND clave_registro=? AND cod_usu=? AND datos_nuevos->>'estado'='Atendida'", [$waiting['codCit'], $fixture['doctorA']['codUsu']]));
    }

    #[Test]
    public function referrals_validate_origin_destination_deduplicate_and_preserve_distinct_requests(): void
    {
        $fixture = $this->medicalFixture();
        $attended = $this->attended($fixture);
        $this->login($fixture['doctorA']['codUsu']);
        $service = app(MedicoDerivacionService::class);
        $data = ['codEspDestino' => $fixture['specialtyB']['codEsp'], 'codMedDestino' => $fixture['doctorB']['codMed'], 'motivo' => 'Evaluación especializada'];
        $first = $service->create($attended['codCit'], $data);
        $retry = $service->create($attended['codCit'], ['codEspDestino' => $data['codEspDestino'], 'codMedDestino' => $data['codMedDestino'], 'motivo' => '  Evaluación especializada  ']);
        $different = $service->create($attended['codCit'], ['codEspDestino' => $data['codEspDestino'], 'motivo' => 'Segunda opinión clínica']);

        $this->assertSame($first['codDer'], $retry['codDer']);
        $this->assertNotSame($first['codDer'], $different['codDer']);
        $this->assertSame($attended['codCit'], $first['codCitOrigen']);
        $this->assertSame($fixture['doctorA']['codMed'], $first['codMedEmisor']);
        $this->assertSame($fixture['doctorB']['codMed'], $first['codMedDestino']);
        $list = $service->listing(['estado' => 'Pendiente', 'page' => 0, 'size' => 1]);
        $this->assertCount(1, $list['derivaciones']);
        $this->assertTrue($list['hayMas']);
        $this->assertSame($first['codDer'], $service->detail($first['codDer'])['codDer']);
        $this->assertSame(2, (int) $this->scalar('SELECT count(*) FROM derivaciones WHERE cod_cit_origen=?', [$attended['codCit']]));

        $confirmed = $this->book($fixture, 1, 2);
        $this->login($fixture['doctorA']['codUsu']);
        $this->expectDomain(409, fn () => $service->create($confirmed['codCit'], $data));
        $this->expectDomain(409, fn () => $service->create($attended['codCit'], ['codEspDestino' => $fixture['specialtyA']['codEsp'], 'codMedDestino' => $fixture['doctorB']['codMed'], 'motivo' => 'Destino incompatible']));
        $this->assertSame($fixture['doctorA']['codUsu'], $this->scalar("SELECT cod_usu FROM bitacora WHERE nombre_tabla='derivaciones' AND clave_registro=? ORDER BY secuencial DESC LIMIT 1", [$first['codDer']]));
    }

    #[Test]
    public function secretary_schedules_and_reschedules_derived_child_visible_to_destination_physician(): void
    {
        $fixture = $this->medicalFixture();
        $origin = $this->attended($fixture);
        $this->login($fixture['doctorA']['codUsu']);
        $referral = app(MedicoDerivacionService::class)->create($origin['codCit'], [
            'codEspDestino' => $fixture['specialtyB']['codEsp'],
            'codMedDestino' => $fixture['doctorB']['codMed'],
            'motivo' => 'Programación por Secretaría',
        ]);

        $this->login($fixture['secretary']['codUsu']);
        $reception = app(ReceptionService::class);
        $pending = $reception->request('GET', 'derivaciones', ['estado' => 'Pendiente']);
        $this->assertContains($referral['codDer'], array_column($pending['derivaciones'], 'codDer'));
        $slots = $reception->request('GET', 'disponibilidad', [
            'codEsp' => $fixture['specialtyB']['codEsp'], 'codMed' => $fixture['doctorB']['codMed'],
            'fecha' => $this->date(1), 'codPac' => $fixture['patient'],
        ]);
        $this->assertGreaterThanOrEqual(3, count($slots));
        $child = $reception->request('POST', 'citas', ['codSlot' => $slots[0]['codSlot'], 'codDer' => $referral['codDer']]);
        $this->assertSame('Derivacion', $child['canalReserva']);
        $this->assertSame($fixture['patient'], $child['codPac']);
        $this->assertSame($child['codCit'], $this->scalar('SELECT cod_cit_hija FROM derivaciones WHERE cod_der=?', [$referral['codDer']]));
        $this->expectDomain(409, fn () => $reception->request('POST', 'citas', ['codSlot' => $slots[1]['codSlot'], 'codDer' => $referral['codDer']]));

        $rescheduled = $reception->request('POST', 'citas/'.$child['codCit'].'/reprogramar', ['codSlot' => $slots[2]['codSlot'], 'motivo' => 'Ajuste de agenda derivada']);
        $this->assertSame($rescheduled['codCit'], $this->scalar('SELECT cod_cit_hija FROM derivaciones WHERE cod_der=?', [$referral['codDer']]));
        $this->assertSame($child['codCit'], $this->scalar('SELECT cod_cit_padre FROM citas WHERE cod_cit=?', [$rescheduled['codCit']]));
        $this->login($fixture['doctorB']['codUsu']);
        $agenda = app(MedicoAtencionService::class)->agenda($this->date(1));
        $this->assertContains($rescheduled['codCit'], array_column($agenda['citas'], 'codCit'));
    }

    #[Test]
    public function analyses_are_owned_deduplicated_transitioned_and_final_results_are_immutable(): void
    {
        $fixture = $this->medicalFixture();
        $waiting = $this->checkedIn($fixture, $this->book($fixture, 1, 0));
        $this->login($fixture['doctorA']['codUsu']);
        $service = app(MedicoAnalisisService::class);
        $first = $service->request($waiting['codCit'], ['tipoAnalisis' => 'Hemograma completo']);
        $retry = $service->request($waiting['codCit'], ['tipoAnalisis' => '  HEMOGRAMA COMPLETO  ']);
        $second = $service->request($waiting['codCit'], ['tipoAnalisis' => 'Glucosa']);
        $this->assertSame($first['codAna'], $retry['codAna']);
        $this->assertNotSame($first['codAna'], $second['codAna']);
        $this->assertSame($fixture['doctorA']['codUsu'], $first['solicitadoPorUsu']);
        $this->assertCount(2, $service->listing($waiting['codCit']));
        $processing = $service->update($first['codAna'], ['estado' => 'En Proceso']);
        $this->assertSame('En Proceso', $processing['estado']);
        $completed = $service->update($first['codAna'], ['estado' => 'Completado', 'resultado' => 'Valores dentro de referencia.']);
        $this->assertSame('Completado', $completed['estado']);
        $this->assertSame('Valores dentro de referencia.', $completed['resultado']);
        $this->expectDomain(409, fn () => $service->update($first['codAna'], ['estado' => 'Completado', 'resultado' => 'Sobrescritura']));
        $this->expectDomain(400, fn () => $service->update($second['codAna'], ['estado' => 'Completado', 'resultado' => str_repeat('x', 20001)]));
        $this->login($fixture['doctorB']['codUsu']);
        $this->expectDomain(404, fn () => $service->listing($waiting['codCit']));
        $this->expectDomain(404, fn () => $service->update($second['codAna'], ['estado' => 'En Proceso']));
        $this->assertSame($fixture['doctorA']['codUsu'], $this->scalar("SELECT cod_usu FROM bitacora WHERE nombre_tabla='analisis' AND clave_registro=? ORDER BY secuencial DESC LIMIT 1", [$first['codAna']]));
    }

    #[Test]
    public function service_and_web_boundaries_reject_other_roles_inactive_profiles_and_spoofing(): void
    {
        Http::fake();
        $fixture = $this->medicalFixture();
        $appointment = $this->book($fixture, 1, 0);
        Auth::guard('web')->logout();
        $this->get('/medico/inicio')->assertRedirect('/login');
        foreach ([$fixture['admin'], $fixture['secretary']['codUsu'], $fixture['patientUser']] as $actor) {
            $this->login($actor);
            $this->expectDomain(403, fn () => app(MedicoAtencionService::class)->agenda($this->date(1)));
            $this->withSession($this->authSession($actor))->get('/medico/inicio')->assertForbidden();
            $this->withSession($this->authSession($actor))->post('/medico/citas/'.$appointment['codCit'].'/atender')->assertForbidden();
        }

        $this->login($fixture['doctorA']['codUsu']);
        $this->withSession($this->authSession($fixture['doctorA']['codUsu']))
            ->get('/medico/citas/'.$appointment['codCit'])->assertOk()->assertSee($appointment['codCit']);
        $foreign = $this->book($fixture, 1, 0, 'B');
        $this->login($fixture['doctorA']['codUsu']);
        $this->withSession($this->authSession($fixture['doctorA']['codUsu']))
            ->get('/medico/citas/'.$foreign['codCit'])->assertNotFound();
        $this->owner->prepare("UPDATE medicos SET estado='Inactivo' WHERE cod_med=?")->execute([$fixture['doctorA']['codMed']]);
        $this->expectDomain(403, fn () => app(MedicoAtencionService::class)->agenda($this->date(1)));
        Http::assertNothingSent();
    }

    #[Test]
    public function medical_blade_dashboard_agenda_history_referrals_and_analyses_render_with_csrf(): void
    {
        Http::fake();
        $fixture = $this->medicalFixture();
        $waiting = $this->checkedIn($fixture, $this->book($fixture, 1, 0));
        $this->login($fixture['doctorA']['codUsu']);
        $analysis = app(MedicoAnalisisService::class)->request($waiting['codCit'], ['tipoAnalisis' => 'Proteína C reactiva']);
        $session = $this->authSession($fixture['doctorA']['codUsu']);
        $this->withSession($session)->get('/medico/inicio')->assertOk()->assertSee('Resumen del día');
        $this->withSession($session)->get('/medico/agenda?fecha='.$this->date())->assertOk()->assertSee($waiting['codCit']);
        $this->withSession($session)->get('/medico/citas/'.$waiting['codCit'])->assertOk()->assertSee('name="_token"', false);
        $this->withSession($session)->get('/medico/historial')->assertOk();
        $this->withSession($session)->get('/medico/derivaciones')->assertOk();
        $this->withSession($session)->get('/medico/citas/'.$waiting['codCit'].'/derivaciones/crear')->assertOk()->assertSee('name="_token"', false);
        $this->withSession($session)->get('/medico/citas/'.$waiting['codCit'].'/analisis')->assertOk()->assertSee($analysis['codAna'])->assertSee('name="_token"', false);
        Http::assertNothingSent();
    }

    #[Test]
    public function attention_referral_and_analysis_concurrency_use_independent_processes_and_barriers(): void
    {
        $fixture = $this->medicalFixture();
        $waiting = $this->checkedIn($fixture, $this->book($fixture, 1, 0));
        $finish = $this->concurrent([
            ['operation' => 'finish', 'actor' => $fixture['doctorA']['codUsu'], 'code' => $waiting['codCit']],
            ['operation' => 'finish', 'actor' => $fixture['doctorA']['codUsu'], 'code' => $waiting['codCit']],
        ]);
        $this->assertCount(1, array_filter($finish, fn (array $result): bool => $result['ok']));
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM bitacora WHERE nombre_tabla='citas' AND clave_registro=? AND datos_nuevos->>'estado'='Atendida'", [$waiting['codCit']]));

        $payload = ['codEspDestino' => $fixture['specialtyB']['codEsp'], 'codMedDestino' => $fixture['doctorB']['codMed'], 'motivo' => 'Reintento concurrente'];
        $referrals = $this->concurrent([
            ['operation' => 'referral', 'actor' => $fixture['doctorA']['codUsu'], 'appointment' => $waiting['codCit'], 'data' => $payload],
            ['operation' => 'referral', 'actor' => $fixture['doctorA']['codUsu'], 'appointment' => $waiting['codCit'], 'data' => $payload],
        ]);
        $this->assertCount(2, array_filter($referrals, fn (array $result): bool => $result['ok']));
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM derivaciones WHERE cod_cit_origen=? AND motivo_derivacion='Reintento concurrente'", [$waiting['codCit']]));

        $this->login($fixture['doctorA']['codUsu']);
        $requests = $this->concurrent([
            ['operation' => 'analysis-request', 'actor' => $fixture['doctorA']['codUsu'], 'appointment' => $waiting['codCit'], 'data' => ['tipoAnalisis' => 'Resultado concurrente']],
            ['operation' => 'analysis-request', 'actor' => $fixture['doctorA']['codUsu'], 'appointment' => $waiting['codCit'], 'data' => ['tipoAnalisis' => '  RESULTADO CONCURRENTE  ']],
        ]);
        $this->assertCount(2, array_filter($requests, fn (array $result): bool => $result['ok']));
        $this->assertSame($requests[0]['result']['codAna'], $requests[1]['result']['codAna']);
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM analisis WHERE cod_cit=? AND lower(trim(tipo_analisis))='resultado concurrente'", [$waiting['codCit']]));
        $analysis = $requests[0]['result'];
        $updates = $this->concurrent([
            ['operation' => 'analysis-update', 'actor' => $fixture['doctorA']['codUsu'], 'code' => $analysis['codAna'], 'data' => ['estado' => 'Completado', 'resultado' => 'Primer resultado']],
            ['operation' => 'analysis-update', 'actor' => $fixture['doctorA']['codUsu'], 'code' => $analysis['codAna'], 'data' => ['estado' => 'Completado', 'resultado' => 'Segundo resultado']],
        ]);
        $this->assertCount(1, array_filter($updates, fn (array $result): bool => $result['ok']));
        $this->assertContains($this->scalar('SELECT resultado FROM analisis WHERE cod_ana=?', [$analysis['codAna']]), ['Primer resultado', 'Segundo resultado']);
    }

    #[Test]
    public function end_to_end_http_journey_integrates_reception_referrals_analyses_and_guards_tampering(): void
    {
        Http::fake();
        $fixture = $this->medicalFixture();
        $otherPatient = $this->identity('ROL001');
        $otherPatientCode = $this->scalar('SELECT cod_pac FROM pacientes WHERE cod_usu=?', [$otherPatient]);
        $waiting = $this->checkedIn($fixture, $this->book($fixture, 1, 0));

        $doctorASession = $this->authSession($fixture['doctorA']['codUsu']);
        $secretarySession = $this->authSession($fixture['secretary']['codUsu']);
        $doctorBSession = $this->authSession($fixture['doctorB']['codUsu']);

        $this->login($fixture['doctorA']['codUsu']);
        $this->withSession($doctorASession)
            ->post('/medico/citas/'.$waiting['codCit'].'/atender', ['confirmacion' => '1'])
            ->assertRedirect('/medico/citas/'.$waiting['codCit']);
        $this->assertSame('Atendida', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$waiting['codCit']]));

        $this->withSession($doctorASession)
            ->get('/medico/historial?desde='.$this->date().'&hasta=')
            ->assertOk()
            ->assertSee($waiting['codCit']);

        $this->withSession($doctorASession)
            ->post('/medico/citas/'.$waiting['codCit'].'/analisis', ['tipoAnalisis' => 'Perfil lipídico', 'confirmacion' => '1'])
            ->assertRedirect('/medico/citas/'.$waiting['codCit'].'/analisis');
        $codAna = (string) $this->scalar('SELECT cod_ana FROM analisis WHERE cod_cit=? ORDER BY cod_ana DESC LIMIT 1', [$waiting['codCit']]);
        $this->withSession($doctorASession)
            ->patch('/medico/analisis/'.$codAna, ['estado' => 'En Proceso', 'confirmacion' => '1'])
            ->assertRedirect('/medico/citas/'.$waiting['codCit'].'/analisis');
        $this->withSession($doctorASession)
            ->patch('/medico/analisis/'.$codAna, ['estado' => 'Completado', 'resultado' => 'Colesterol normal.', 'confirmacion' => '1'])
            ->assertRedirect('/medico/citas/'.$waiting['codCit'].'/analisis');
        $this->assertSame('Colesterol normal.', $this->scalar('SELECT resultado FROM analisis WHERE cod_ana=?', [$codAna]));

        $referralRedirect = $this->withSession($doctorASession)
            ->post('/medico/citas/'.$waiting['codCit'].'/derivaciones', [
                'codEspDestino' => $fixture['specialtyB']['codEsp'],
                'codMedDestino' => $fixture['doctorB']['codMed'],
                'motivo' => 'Interconsulta integral',
                'confirmacion' => '1',
            ]);
        $codDer = (string) $this->scalar('SELECT cod_der FROM derivaciones WHERE cod_cit_origen=?', [$waiting['codCit']]);
        $referralRedirect->assertRedirect('/medico/derivaciones/'.$codDer);

        $this->login($fixture['secretary']['codUsu']);
        $this->withSession($secretarySession)->get('/secretaria/derivaciones')->assertOk()->assertSee($codDer);
        $this->withSession($secretarySession)->get('/secretaria/derivaciones/'.$codDer)->assertOk()->assertSee('Programar cita hija');
        $this->withSession($secretarySession)
            ->get('/secretaria/reservas/nueva?codDer='.$codDer.'&codPac='.$otherPatientCode.'&codEsp='.$fixture['specialtyA']['codEsp'].'&fecha='.$this->date(1))
            ->assertOk()
            ->assertSee($fixture['patient'])
            ->assertSee('name="codDer" value="'.$codDer.'"', false);

        $slots = app(ReceptionService::class)->request('GET', 'disponibilidad', [
            'codEsp' => $fixture['specialtyB']['codEsp'], 'codMed' => $fixture['doctorB']['codMed'],
            'fecha' => $this->date(1), 'codPac' => $fixture['patient'],
        ]);
        $this->expectDomain(409, fn () => app(ReceptionService::class)->request('POST', 'citas', [
            'codDer' => $codDer, 'codPac' => $otherPatientCode, 'codSlot' => $slots[0]['codSlot'],
        ]));
        $this->expectDomain(404, fn () => app(ReceptionService::class)->request('POST', 'citas', [
            'codDer' => 'DERINEXISTENTE', 'codSlot' => $slots[0]['codSlot'],
        ]));

        // Simula un slot recién ocupado antes de confirmar: el redirect conserva codDer.
        $occupied = $this->book($fixture, 1, 0, 'B');
        $conflictRedirect = $this->withSession($secretarySession)
            ->post('/secretaria/reservas', [
                'codDer' => $codDer, 'codEsp' => $fixture['specialtyB']['codEsp'],
                'codMed' => $fixture['doctorB']['codMed'], 'fecha' => $this->date(1),
                'codSlot' => $occupied['codSlot'], 'confirmacion' => '1',
            ]);
        $conflictRedirect->assertRedirect();
        $this->assertStringContainsString('codDer='.$codDer, (string) $conflictRedirect->headers->get('Location'));

        $this->withSession($secretarySession)
            ->post('/secretaria/reservas', ['codDer' => $codDer, 'codSlot' => $slots[1]['codSlot'], 'confirmacion' => '1'])
            ->assertRedirect();
        $childCode = (string) $this->scalar('SELECT cod_cit_hija FROM derivaciones WHERE cod_der=?', [$codDer]);
        $this->assertSame($waiting['codCit'], $this->scalar('SELECT cod_cit_padre FROM citas WHERE cod_cit=?', [$childCode]));

        $this->login($fixture['doctorB']['codUsu']);
        $this->withSession($doctorBSession)
            ->get('/medico/agenda?fecha='.$this->date(1))
            ->assertOk()
            ->assertSee($childCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function one_referral_cannot_create_two_children_under_real_concurrency(): void
    {
        $fixture = $this->medicalFixture();
        $origin = $this->attended($fixture);
        $this->login($fixture['doctorA']['codUsu']);
        $referral = app(MedicoDerivacionService::class)->create($origin['codCit'], [
            'codEspDestino' => $fixture['specialtyB']['codEsp'], 'codMedDestino' => $fixture['doctorB']['codMed'], 'motivo' => 'Reserva concurrente',
        ]);
        $this->login($fixture['secretary']['codUsu']);
        $slots = app(ReceptionService::class)->request('GET', 'disponibilidad', [
            'codEsp' => $fixture['specialtyB']['codEsp'], 'codMed' => $fixture['doctorB']['codMed'], 'fecha' => $this->date(1), 'codPac' => $fixture['patient'],
        ]);
        $this->assertGreaterThanOrEqual(2, count($slots));
        $results = $this->concurrent([
            ['operation' => 'reserve', 'actor' => $fixture['secretary']['codUsu'], 'data' => ['codSlot' => $slots[0]['codSlot'], 'codDer' => $referral['codDer']]],
            ['operation' => 'reserve', 'actor' => $fixture['secretary']['codUsu'], 'data' => ['codSlot' => $slots[1]['codSlot'], 'codDer' => $referral['codDer']]],
        ]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']));
        $this->assertSame(1, (int) $this->scalar('SELECT count(*) FROM citas c JOIN derivaciones d ON d.cod_cit_hija=c.cod_cit WHERE d.cod_der=?', [$referral['codDer']]));
    }

    #[Test]
    public function phase_four_permissions_schema_and_actor_context_remain_minimal_and_intact(): void
    {
        $db = DB::connection('clinical');
        $this->assertSame('clinica_app', $db->scalar('SELECT current_user'));
        $this->assertTrue($db->scalar("SELECT has_function_privilege(current_user,'marcar_cita_atendida(text,text)','EXECUTE')"));
        $this->assertTrue($db->scalar("SELECT has_function_privilege(current_user,'crear_derivacion(text,text,text,text,text)','EXECUTE')"));
        $this->assertTrue($db->scalar("SELECT has_column_privilege(current_user,'analisis','tipo_analisis','INSERT')"));
        $this->assertTrue($db->scalar("SELECT has_column_privilege(current_user,'analisis','resultado','UPDATE')"));
        $this->assertFalse($db->scalar("SELECT has_table_privilege(current_user,'analisis','DELETE')"));
        $this->assertFalse($db->scalar("SELECT has_table_privilege(current_user,'configuracion_seguridad','SELECT')"));
        $this->assertFalse($db->scalar('SELECT rolsuper FROM pg_roles WHERE rolname=current_user'));
        $this->assertNull($db->scalar('SELECT actor_actual()'));
        $this->assertSame(21, (int) $db->scalar("SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename<>'flyway_schema_history'"));
        $this->assertSame(2, (int) $db->scalar("SELECT count(*) FROM pg_views WHERE schemaname='public'"));
        $this->assertSame(42, (int) $db->scalar("SELECT count(*) FROM pg_proc p WHERE pronamespace='public'::regnamespace AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid='pg_proc'::regclass AND d.objid=p.oid AND d.deptype='e')"));
    }

    #[Test]
    public function derived_child_completion_and_cancellation_update_the_ticket_and_audit_actor(): void
    {
        $fixture = $this->medicalFixture();
        $origin = $this->attended($fixture);
        $this->login($fixture['doctorA']['codUsu']);
        $referrals = app(MedicoDerivacionService::class);
        $first = $referrals->create($origin['codCit'], ['codEspDestino' => $fixture['specialtyB']['codEsp'], 'motivo' => 'AUDIT_TEST completar']);
        $second = $referrals->create($origin['codCit'], ['codEspDestino' => $fixture['specialtyB']['codEsp'], 'motivo' => 'AUDIT_TEST cancelar']);
        $this->login($fixture['secretary']['codUsu']);
        $reception = app(ReceptionService::class);
        $slots = $reception->request('GET', 'disponibilidad', ['codPac' => $fixture['patient'], 'codEsp' => $fixture['specialtyB']['codEsp'], 'fecha' => $this->date(1)]);
        $child = $reception->request('POST', 'citas', ['codDer' => $first['codDer'], 'codSlot' => $slots[1]['codSlot']]);
        $cancelled = $reception->request('POST', 'citas', ['codDer' => $second['codDer'], 'codSlot' => $slots[2]['codSlot']]);
        $reception->request('POST', 'citas/'.$cancelled['codCit'].'/cancelar', ['motivo' => 'AUDIT_TEST no requiere interconsulta']);
        $this->assertSame('Cancelada', $this->scalar('SELECT estado FROM derivaciones WHERE cod_der=?', [$second['codDer']]));
        $this->assertSame($fixture['secretary']['codUsu'], $this->scalar("SELECT cod_usu FROM bitacora WHERE nombre_tabla='derivaciones' AND clave_registro=? ORDER BY secuencial DESC LIMIT 1", [$second['codDer']]));
        $this->checkedIn($fixture, $child);
        $this->login($fixture['doctorB']['codUsu']);
        app(MedicoAtencionService::class)->finish($child['codCit']);
        $this->assertSame('Completada', $this->scalar('SELECT estado FROM derivaciones WHERE cod_der=?', [$first['codDer']]));
        $this->assertSame($fixture['doctorB']['codUsu'], $this->scalar("SELECT cod_usu FROM bitacora WHERE nombre_tabla='derivaciones' AND clave_registro=? ORDER BY secuencial DESC LIMIT 1", [$first['codDer']]));
        $this->login($fixture['doctorA']['codUsu']);
        $this->assertSame('Completada', $referrals->detail($first['codDer'])['estado']);
    }

    #[Test]
    public function patient_overlap_is_rejected_at_write_across_doctors_and_channels_but_adjacency_is_allowed(): void
    {
        $fixture = $this->medicalFixture();
        $slotA = $this->scalar('SELECT cod_slot FROM slots_agenda WHERE cod_med=? AND fecha=fecha_clinica()+1 ORDER BY inicio_at LIMIT 1', [$fixture['doctorA']['codMed']]);
        $slotB = $this->scalar('SELECT cod_slot FROM slots_agenda WHERE cod_med=? AND fecha=fecha_clinica()+1 ORDER BY inicio_at LIMIT 1', [$fixture['doctorB']['codMed']]);
        $adjacentB = $this->scalar('SELECT cod_slot FROM slots_agenda WHERE cod_med=? AND fecha=fecha_clinica()+1 ORDER BY inicio_at LIMIT 1 OFFSET 1', [$fixture['doctorB']['codMed']]);
        $partialB = $this->scalar("INSERT INTO slots_agenda(cod_hor,cod_med,cod_esp,inicio_at,fin_at)
            SELECT cod_hor,cod_med,cod_esp,inicio_at+interval '10 minutes',fin_at+interval '10 minutes'
            FROM slots_agenda WHERE cod_slot=? RETURNING cod_slot", [$slotB]);
        $this->login($fixture['patientUser']);
        $appointments = app(PatientAppointmentService::class);
        $bookedA = $appointments->reserve($slotA);
        $this->login($fixture['secretary']['codUsu']);
        $reception = app(ReceptionService::class);
        foreach ([$slotB, $partialB] as $conflicting) {
            $this->expectDomain(409, fn () => $reception->request('POST', 'citas', ['codPac' => $fixture['patient'], 'codSlot' => $conflicting]));
        }
        $bookedB = $reception->request('POST', 'citas', ['codPac' => $fixture['patient'], 'codSlot' => $adjacentB]);
        $this->expectDomain(409, fn () => $reception->request('POST', 'citas/'.$bookedB['codCit'].'/reprogramar', ['codSlot' => $partialB, 'motivo' => 'AUDIT_TEST conflicto']));
        $this->assertSame('Confirmada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$bookedB['codCit']]));
        $reception->request('POST', 'citas/'.$bookedA['codCit'].'/cancelar', ['motivo' => 'AUDIT_TEST cambio de canal']);
        $reception->request('POST', 'citas', ['codPac' => $fixture['patient'], 'codSlot' => $slotA]);
        $this->login($fixture['patientUser']);
        $this->expectDomain(409, fn () => $appointments->reserve($slotB));
        $this->assertSame(2, (int) $this->scalar("SELECT count(*) FROM citas WHERE cod_pac=? AND estado='Confirmada'", [$fixture['patient']]));
        $this->assertSame(0, (int) $this->scalar('SELECT count(*) FROM citas WHERE cod_slot=?', [$partialB]));
    }

    private function medicalFixture(): array
    {
        $admin = $this->identity('ROL004');
        $this->login($admin);
        $administration = app(AdministrationService::class);
        $specialtyA = $administration->request('POST', 'personal/especialidades', ['nombreEspecialidad' => 'Fase4 A '.bin2hex(random_bytes(4)), 'descripcion' => 'PHASE4_TEST', 'duracionConsultaMin' => 20]);
        $specialtyB = $administration->request('POST', 'personal/especialidades', ['nombreEspecialidad' => 'Fase4 B '.bin2hex(random_bytes(4)), 'descripcion' => 'PHASE4_TEST', 'duracionConsultaMin' => 20]);
        $doctorA = $administration->request('POST', 'personal/medicos', $this->doctorData('A', $specialtyA['codEsp']));
        $doctorB = $administration->request('POST', 'personal/medicos', $this->doctorData('B', $specialtyB['codEsp']));
        $secretary = $administration->request('POST', 'personal/secretarias', [
            'nombreCompleto' => 'Secretaría Fase Cuatro', 'email' => $this->email('secretary'),
            'password' => $this->fixturePassword, 'ci' => 'SEC-'.bin2hex(random_bytes(5)), 'telefono' => '70000000',
        ]);
        $day = (int) $this->scalar('SELECT extract(isodow FROM fecha_clinica()+1)');
        foreach ([$doctorA, $doctorB] as $doctor) {
            $administration->request('POST', 'administracion/medicos/'.$doctor['codMed'].'/horarios', [
                'diaSemana' => $day, 'horaInicio' => '08:00', 'horaFin' => '14:00',
            ]);
        }
        $patientUser = $this->identity('ROL001');

        return [
            'admin' => $admin,
            'secretary' => $secretary,
            'patientUser' => $patientUser,
            'patient' => $this->scalar('SELECT cod_pac FROM pacientes WHERE cod_usu=?', [$patientUser]),
            'specialtyA' => $specialtyA,
            'specialtyB' => $specialtyB,
            'doctorA' => $doctorA,
            'doctorB' => $doctorB,
        ];
    }

    private function doctorData(string $suffix, string $specialty): array
    {
        return [
            'nombreCompleto' => 'Médico Fase Cuatro '.$suffix,
            'email' => $this->email('doctor'.strtolower($suffix)),
            'password' => $this->fixturePassword,
            'ci' => 'DOC-'.$suffix.'-'.bin2hex(random_bytes(4)),
            'matriculaProfesional' => 'MAT-'.$suffix.'-'.bin2hex(random_bytes(4)),
            'duracionConsultaMin' => 20,
            'codigosEspecialidades' => [$specialty],
        ];
    }

    private function book(array $fixture, int $offset, int $index, string $doctor = 'A'): array
    {
        $this->login($fixture['secretary']['codUsu']);
        $reception = app(ReceptionService::class);
        $selectedDoctor = $fixture['doctor'.$doctor];
        $selectedSpecialty = $fixture['specialty'.$doctor];
        $slots = $reception->request('GET', 'disponibilidad', [
            'codEsp' => $selectedSpecialty['codEsp'], 'codMed' => $selectedDoctor['codMed'],
            'fecha' => $this->date($offset), 'codPac' => $fixture['patient'],
        ]);
        $this->assertArrayHasKey($index, $slots, 'No se generó el slot clínico requerido por el fixture.');

        return $reception->request('POST', 'citas', ['codPac' => $fixture['patient'], 'codSlot' => $slots[$index]['codSlot']]);
    }

    private function checkedIn(array $fixture, array $appointment): array
    {
        $statement = $this->owner->prepare(<<<'SQL'
            DELETE FROM slots_agenda current_slot
            USING slots_agenda selected_slot
            WHERE selected_slot.cod_slot=?
              AND current_slot.cod_hor=selected_slot.cod_hor
              AND current_slot.cod_esp=selected_slot.cod_esp
              AND current_slot.fecha=fecha_clinica()
              AND current_slot.hora_inicio=selected_slot.hora_inicio
              AND current_slot.cod_slot<>selected_slot.cod_slot
              AND NOT EXISTS (SELECT 1 FROM citas WHERE cod_slot=current_slot.cod_slot)
            SQL);
        $statement->execute([$appointment['codSlot']]);
        $statement = $this->owner->prepare('UPDATE slots_agenda SET inicio_at=fecha_clinica()+hora_inicio,fin_at=fecha_clinica()+hora_fin WHERE cod_slot=?');
        $statement->execute([$appointment['codSlot']]);
        $statement = $this->owner->prepare('UPDATE citas SET fecha=fecha_clinica(),token_qr=crear_token_qr(cod_cit,fecha_clinica()) WHERE cod_cit=?');
        $statement->execute([$appointment['codCit']]);
        $token = $this->scalar('SELECT token_qr FROM citas WHERE cod_cit=?', [$appointment['codCit']]);
        $this->login($fixture['secretary']['codUsu']);
        $result = app(ReceptionService::class)->request('POST', 'checkin', ['tokenQr' => $token]);
        $this->assertSame('En Espera', $result['estado']);

        return $result;
    }

    private function attended(array $fixture): array
    {
        return $this->makeAttended($fixture, $this->book($fixture, 1, 0));
    }

    private function makeAttended(array $fixture, array $appointment): array
    {
        $waiting = $this->checkedIn($fixture, $appointment);
        $this->login($fixture['doctorA']['codUsu']);

        return app(MedicoAtencionService::class)->finish($waiting['codCit']);
    }

    private function concurrent(array $payloads): array
    {
        $barrier = storage_path('framework/testing/phase4-'.bin2hex(random_bytes(6)));
        if (! is_dir($barrier) && ! mkdir($barrier, 0777, true) && ! is_dir($barrier)) {
            $this->fail('No se pudo preparar la barrera de concurrencia.');
        }
        $processes = [];
        foreach ($payloads as $index => $payload) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Support/ConcurrentMedicalWorker.php')], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            $payload['barrierDir'] = $barrier;
            $payload['workerId'] = (string) $index;
            fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        foreach (array_keys($payloads) as $index) {
            while (! is_file($barrier.'/ready-'.$index) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($barrier.'/ready-'.$index, 'Un proceso no alcanzó la barrera.');
        }
        file_put_contents($barrier.'/go', 'go', LOCK_EX);
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }
        foreach (phaseFourFiles($barrier) as $file) {
            unlink($file);
        }
        rmdir($barrier);

        return $results;
    }

    private function identity(string $role): string
    {
        $statement = $this->owner->prepare("INSERT INTO usuarios(nombre_completo,email,password_hash,cod_rol,estado) VALUES ('PHASE4 TEST',?,?,?,'Activo') RETURNING cod_usu");
        $statement->execute([$this->email(strtolower($role)), Hash::make($this->fixturePassword), $role]);
        $user = $statement->fetchColumn();
        if ($role === 'ROL001') {
            $this->owner->prepare("INSERT INTO pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,estado_cuenta,email_verificado,email_verificado_en) VALUES (?,?,DATE '1990-01-01','70000000','La Paz','Activo',true,now())")
                ->execute([$user, 'PAC-'.bin2hex(random_bytes(5))]);
        }

        return $user;
    }

    private function login(string $user): void
    {
        Auth::guard('web')->logout();
        Auth::guard('web')->login(Usuario::query()->findOrFail($user));
    }

    private function authSession(string $user): array
    {
        $identity = Usuario::query()->findOrFail($user);

        return [
            'auth_authority' => 'laravel', 'cod_usu' => $identity->cod_usu, 'cod_rol' => $identity->cod_rol,
            'auth_expires_at' => now()->addMinutes(120)->toIso8601String(),
        ];
    }

    private function expectDomain(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('La operación debía ser rechazada.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    private function date(int $offset = 0): string
    {
        return (string) $this->scalar("SELECT (fecha_clinica()+$offset)::text");
    }

    private function email(string $prefix): string
    {
        return $prefix.'.'.bin2hex(random_bytes(6)).'@phase4.invalid';
    }

    private function scalar(string $sql, array $bindings = []): mixed
    {
        $statement = $this->owner->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchColumn();
    }
}

/** @return list<string> */
function phaseFourFiles(string $directory): array
{
    $files = glob($directory.'/*');

    return $files === false ? [] : $files;
}
