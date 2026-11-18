<?php

namespace Tests\Postgres;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Administration\AdministrationService;
use App\Services\Notifications\AppointmentNotificationService;
use App\Services\Reception\ReceptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Integración real con la base aislada. No usa RefreshDatabase ni migrate:fresh. */
final class AdministrationReceptionMigrationTest extends TestCase
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
        $this->owner = new PDO('pgsql:host=127.0.0.1;port=35443;dbname=clinica_laravel_phase1_tests', 'clinica_owner', getenv('CLINICAL_OWNER_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        Auth::guard('web')->logout();
        parent::tearDown();
    }

    #[Test]
    public function administrator_manages_staff_specialties_schedules_blocks_and_audit_without_quarkus(): void
    {
        Http::fake();
        $admin = $this->identity('ROL004');
        $this->login($admin);
        $service = app(AdministrationService::class);

        $specialty = $service->request('POST', 'personal/especialidades', [
            'nombreEspecialidad' => 'Fase3 '.bin2hex(random_bytes(4)), 'descripcion' => 'PHASE3_TEST', 'duracionConsultaMin' => 30,
        ]);
        $doctor = $service->request('POST', 'personal/medicos', [
            'nombreCompleto' => 'Médico Fase Tres', 'email' => $this->email('medico'), 'password' => $this->fixturePassword,
            'ci' => 'MED-'.random_int(100000, 999999), 'matriculaProfesional' => 'MAT-'.bin2hex(random_bytes(4)),
            'resumenCurricular' => 'Integración', 'duracionConsultaMin' => 30,
            'codigosEspecialidades' => [$specialty['codEsp']],
        ]);
        $secretary = $service->request('POST', 'personal/secretarias', [
            'nombreCompleto' => 'Secretaría Fase Tres', 'email' => $this->email('secretaria'),
            'password' => $this->fixturePassword, 'ci' => 'SEC-'.random_int(100000, 999999), 'telefono' => '70000000',
        ]);
        $day = (int) $this->owner->query('SELECT extract(isodow FROM fecha_clinica()+1)')->fetchColumn();
        $schedule = $service->request('POST', 'administracion/medicos/'.$doctor['codMed'].'/horarios', [
            'diaSemana' => $day, 'horaInicio' => '08:00', 'horaFin' => '12:00', 'pausaInicio' => '10:00', 'pausaFin' => '10:30',
        ]);
        $block = $service->request('POST', 'administracion/medicos/'.$doctor['codMed'].'/bloqueos', [
            'fechaBloqueo' => $this->owner->query('SELECT fecha_clinica()+2')->fetchColumn(), 'motivo' => 'PHASE3_TEST bloqueo',
        ]);

        $this->assertSame($specialty['codEsp'], $doctor['especialidades'][0]['codEsp']);
        $this->assertSame($secretary['codSec'], $service->request('GET', 'personal/secretarias/'.$secretary['codSec'])['codSec']);
        $this->assertSame(30, (int) $service->request('PUT', 'administracion/medicos/'.$doctor['codMed'].'/duracion', ['duracionConsultaMin' => 30])['duracionConsultaMin']);
        $this->assertSame($schedule['codHor'], $service->request('GET', 'administracion/medicos/'.$doctor['codMed'].'/horarios')[0]['codHor']);
        $this->assertSame($block['codBlo'], $service->request('GET', 'administracion/bloqueos/'.$block['codBlo'])['codBlo']);
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT count(*) FROM bitacora WHERE cod_usu=? AND clave_registro IN (?,?,?,?)', [$admin, $specialty['codEsp'], $doctor['codMed'], $secretary['codSec'], $block['codBlo']]));
        $service->request('PUT', 'personal/secretarias/'.$secretary['codSec'].'/estado', ['estado' => 'Inactivo']);
        $this->assertSame('Inactivo', $service->request('GET', 'personal/secretarias/'.$secretary['codSec'])['estado']);
        Http::assertNothingSent();
    }

    #[Test]
    public function secretary_uses_postgresql_availability_reservation_cancel_and_sanction_rules(): void
    {
        [$admin, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture();
        $this->login($secretary['codUsu']);
        $service = app(ReceptionService::class);
        $date = $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = $service->request('GET', 'disponibilidad', ['codEsp' => $specialty['codEsp'], 'codMed' => $doctor['codMed'], 'fecha' => $date, 'codPac' => $patient]);
        $this->assertNotEmpty($slots);
        $appointment = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
        $this->assertSame('Confirmada', $appointment['estado']);
        $this->assertSame('Ventanilla', $appointment['canalReserva']);
        try {
            $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
            $this->fail('Se aceptó una doble reserva.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
        }
        $cancelled = $service->request('POST', 'citas/'.$appointment['codCit'].'/cancelar', ['motivo' => 'PHASE3_TEST cancelación controlada']);
        $this->assertSame('Cancelada', $cancelled['estado']);
        $this->assertTrue($cancelled['cancelada']);

        $this->login($admin);
        $sanction = app(AdministrationService::class)->request('POST', 'administracion/pacientes/'.$patient.'/sanciones', ['motivo' => 'PHASE3_TEST sanción', 'dias' => 2]);
        $this->assertSame($patient, $sanction['codPac']);
        $this->login($secretary['codUsu']);
        $this->expectException(ClinicalOperationException::class);
        $service->request('GET', 'disponibilidad', ['codEsp' => $specialty['codEsp'], 'fecha' => $date, 'codPac' => $patient]);
    }

    #[Test]
    public function qr_checkin_is_single_use_and_actor_is_the_authenticated_secretary(): void
    {
        [, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture(today: true);
        $this->login($secretary['codUsu']);
        $service = app(ReceptionService::class);
        $date = $this->owner->query('SELECT fecha_clinica()')->fetchColumn();
        $slots = $service->request('GET', 'disponibilidad', ['codEsp' => $specialty['codEsp'], 'codMed' => $doctor['codMed'], 'fecha' => $date, 'codPac' => $patient]);
        if ($slots === []) {
            $this->markTestSkipped('La hora clínica ya no permite generar un slot futuro hoy.');
        }
        $appointment = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
        $token = $this->scalar('SELECT token_qr FROM citas WHERE cod_cit=?', [$appointment['codCit']]);
        $preview = $service->request('POST', 'checkin/validar', ['tokenQr' => 'https://clinica.test/pase-qr/'.rawurlencode($token)]);
        $this->assertSame($appointment['codCit'], $preview['codCit']);
        $this->assertSame('Confirmada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$appointment['codCit']]));
        try {
            $service->request('POST', 'checkin', ['tokenQr' => $token, 'codCit' => 'CIT99999999']);
            $this->fail('Se acreditó un paciente distinto del validado.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
        }
        $attendance = $service->request('POST', 'checkin', ['tokenQr' => $token]);
        $this->assertSame('En Espera', $attendance['estado']);
        $this->assertSame($secretary['codUsu'], $attendance['checkinPorUsu']);
        try {
            $service->request('POST', 'checkin', ['tokenQr' => $token]);
            $this->fail('Se aceptó check-in duplicado.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->assertSame($secretary['codUsu'], $this->scalar("SELECT cod_usu FROM bitacora WHERE nombre_tabla='citas' AND clave_registro=? ORDER BY secuencial DESC LIMIT 1", [$appointment['codCit']]));
    }

    #[Test]
    public function services_reject_the_wrong_role_even_when_called_without_route_middleware(): void
    {
        $patient = $this->identity('ROL001');
        $this->login($patient);
        foreach ([AdministrationService::class, ReceptionService::class] as $service) {
            try {
                app($service)->request('GET', $service === AdministrationService::class ? 'personal/medicos' : 'especialidades');
                $this->fail('Servicio accesible con rol Paciente.');
            } catch (ClinicalOperationException $error) {
                $this->assertSame(403, $error->status);
            }
        }
        $this->withSession(['auth_authority' => 'laravel', 'cod_usu' => $patient, 'cod_rol' => 'ROL001', 'auth_expires_at' => now()->addHour()->toIso8601String()])
            ->get('/administracion/inicio')->assertForbidden();
    }

    #[Test]
    public function blade_routes_render_with_laravel_identity_and_send_no_http_to_quarkus(): void
    {
        Http::fake();
        [$admin, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture();
        $this->login($admin);
        $session = ['auth_authority' => 'laravel', 'cod_usu' => $admin, 'cod_rol' => 'ROL004',
            'auth_expires_at' => now()->addMinutes(120)->toIso8601String()];
        $this->withSession($session)->get('/administracion/inicio')->assertOk();
        $this->withSession($session)->get('/administracion/medicos')->assertOk()->assertSee($doctor['nombreCompleto']);
        $this->withSession($session)->get('/administracion/especialidades/'.$specialty['codEsp'])->assertOk();
        $this->withSession($session)->get('/administracion/pacientes/'.$patient)->assertOk()->assertSee('Habilitado');
        $sanction = app(AdministrationService::class)->request('POST', 'administracion/pacientes/'.$patient.'/sanciones', ['motivo' => 'PHASE3_TEST vista', 'dias' => 1]);
        $this->withSession($session)->get('/administracion/pacientes/'.$patient.'/sanciones/'.$sanction['codInc'])->assertOk()->assertSee($sanction['codInc']);

        $this->login($secretary['codUsu']);
        $session = ['auth_authority' => 'laravel', 'cod_usu' => $secretary['codUsu'], 'cod_rol' => 'ROL002',
            'auth_expires_at' => now()->addMinutes(120)->toIso8601String()];
        $this->withSession($session)->get('/secretaria/inicio')->assertOk();
        $this->withSession($session)->get('/secretaria/pacientes?q=PHASE3')->assertOk();
        $this->withSession($session)->get('/secretaria/pacientes/'.$patient)->assertOk();
        Http::assertNothingSent();
    }

    #[Test]
    public function concurrent_reservations_on_independent_connections_have_one_winner(): void
    {
        [, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture();
        $this->login($secretary['codUsu']);
        $date = $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = app(ReceptionService::class)->request('GET', 'disponibilidad', [
            'codEsp' => $specialty['codEsp'], 'codMed' => $doctor['codMed'], 'fecha' => $date, 'codPac' => $patient,
        ]);
        $this->assertNotEmpty($slots);
        $command = [PHP_BINARY, base_path('tests/Support/ConcurrentReserveWorker.php'), $patient, $slots[0]['codSlot'], $secretary['codUsu']];
        $processes = [];
        foreach ([1, 2] as $index) {
            $pipes = [];
            $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertCount(1, array_filter($results, fn ($result) => $result['ok']));
        $loser = collect($results)->firstWhere('ok', false);
        $this->assertContains($loser['state'], ['P4090', '23P01', '23505']);
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM citas WHERE cod_slot=? AND estado NOT IN ('Cancelada','Reprogramada')", [$slots[0]['codSlot']]));
    }

    #[Test]
    public function concurrent_checkin_on_independent_connections_is_single_use(): void
    {
        [, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture(today: true);
        $this->login($secretary['codUsu']);
        $date = $this->owner->query('SELECT fecha_clinica()')->fetchColumn();
        $slots = app(ReceptionService::class)->request('GET', 'disponibilidad', ['codEsp' => $specialty['codEsp'], 'codMed' => $doctor['codMed'], 'fecha' => $date, 'codPac' => $patient]);
        if ($slots === []) {
            $this->markTestSkipped('La hora clínica ya no permite generar un slot futuro hoy.');
        }
        $appointment = app(ReceptionService::class)->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
        $token = $this->scalar('SELECT token_qr FROM citas WHERE cod_cit=?', [$appointment['codCit']]);
        $processes = [];
        foreach ([1, 2] as $index) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Support/ConcurrentCheckinWorker.php')], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            fwrite($pipes[0], json_encode(['token' => $token, 'secretary' => $secretary['codSec']]));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertCount(1, array_filter($results, fn ($result) => $result['ok']));
        $this->assertSame('P4091', collect($results)->firstWhere('ok', false)['state']);
        $this->assertSame('En Espera', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$appointment['codCit']]));
        $this->assertSame($secretary['codUsu'], $this->scalar('SELECT checkin_por_usu FROM citas WHERE cod_cit=?', [$appointment['codCit']]));
    }

    #[Test]
    public function referral_ticket_is_consumed_once_and_remains_linked_after_reschedule(): void
    {
        [, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture();
        $this->login($secretary['codUsu']);
        $service = app(ReceptionService::class);
        $date = $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = $service->request('GET', 'disponibilidad', ['codEsp' => $specialty['codEsp'], 'codMed' => $doctor['codMed'], 'fecha' => $date, 'codPac' => $patient]);
        $this->assertGreaterThanOrEqual(3, count($slots));
        $origin = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
        $stmt = $this->owner->prepare("INSERT INTO derivaciones(cod_cit_origen,cod_med_emisor,cod_esp_destino,cod_med_destino,motivo_derivacion,estado) VALUES (?,?,?,?,'PHASE3_TEST derivación','Pendiente') RETURNING cod_der");
        $stmt->execute([$origin['codCit'], $doctor['codMed'], $specialty['codEsp'], $doctor['codMed']]);
        $referral = $stmt->fetchColumn();
        $derived = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[1]['codSlot'], 'codDer' => $referral]);
        $this->assertSame('Derivacion', $derived['canalReserva']);
        $this->assertSame($derived['codCit'], $this->scalar('SELECT cod_cit_hija FROM derivaciones WHERE cod_der=?', [$referral]));
        try {
            $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[2]['codSlot'], 'codDer' => $referral]);
            $this->fail('Ticket consumido reutilizado.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
        }
        $rescheduled = $service->request('POST', 'citas/'.$derived['codCit'].'/reprogramar', ['codSlot' => $slots[2]['codSlot'], 'motivo' => 'PHASE3_TEST cambio']);
        $this->assertSame('Derivacion', $rescheduled['canalReserva']);
        $this->assertSame($rescheduled['codCit'], $this->scalar('SELECT cod_cit_hija FROM derivaciones WHERE cod_der=?', [$referral]));
        $this->assertSame($derived['codCit'], $this->scalar('SELECT cod_cit_padre FROM citas WHERE cod_cit=?', [$rescheduled['codCit']]));
    }

    #[Test]
    public function absence_rejects_early_request_and_accepts_an_expired_confirmed_appointment(): void
    {
        [, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture();
        $this->login($secretary['codUsu']);
        $service = app(ReceptionService::class);
        $date = $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = $service->request('GET', 'disponibilidad', ['codEsp' => $specialty['codEsp'], 'codMed' => $doctor['codMed'], 'fecha' => $date, 'codPac' => $patient]);
        $appointment = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
        try {
            $service->request('POST', 'citas/'.$appointment['codCit'].'/inasistencia');
            $this->fail('Inasistencia prematura aceptada.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->owner->prepare("UPDATE slots_agenda SET inicio_at=fecha_clinica()-interval '2 hours',fin_at=fecha_clinica()-interval '1 hour' WHERE cod_slot=?")->execute([$appointment['codSlot']]);
        $this->owner->prepare("UPDATE citas SET motivo_reprogramacion='PHASE3_TEST fixture vencido' WHERE cod_cit=?")->execute([$appointment['codCit']]);
        $result = $service->request('POST', 'citas/'.$appointment['codCit'].'/inasistencia');
        $this->assertSame('No Asistió', $result['estado']);
        $this->assertTrue($result['inasistenciaRegistrada']);
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM incidencias_sanciones WHERE cod_cit=? AND tipo_incidencia='INASISTENCIA_NO_SHOW'", [$appointment['codCit']]));
    }

    #[Test]
    public function phase_three_privileges_are_minimal_and_actor_context_does_not_leak(): void
    {
        $db = DB::connection('clinical');
        $this->assertSame('clinica_app', $db->scalar('SELECT current_user'));
        $this->assertTrue($db->scalar("SELECT has_table_privilege(current_user,'medicos','INSERT')"));
        $this->assertTrue($db->scalar("SELECT has_function_privilege(current_user,'reservar_cita(text,text,text,text,text)','EXECUTE')"));
        $this->assertFalse($db->scalar("SELECT has_table_privilege(current_user,'citas','INSERT')"));
        $this->assertFalse($db->scalar("SELECT has_table_privilege(current_user,'usuarios','DELETE')"));
        $this->assertFalse($db->scalar('SELECT rolsuper FROM pg_roles WHERE rolname=current_user'));
        $this->assertNull($db->scalar('SELECT actor_actual()'));
        $this->assertSame(21, (int) $db->scalar("SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename<>'flyway_schema_history'"));
    }

    #[Test]
    public function affected_appointment_is_resolved_across_secretary_patient_and_outbox(): void
    {
        [$admin, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture();
        $this->login($secretary['codUsu']);
        $service = app(ReceptionService::class);
        $date = $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = $service->request('GET', 'disponibilidad', ['codPac' => $patient, 'codEsp' => $specialty['codEsp'], 'fecha' => $date]);
        $first = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
        $token = $this->scalar('SELECT token_qr FROM citas WHERE cod_cit=?', [$first['codCit']]);
        $unaffected = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[3]['codSlot']]);
        $patientUser = $this->scalar('SELECT cod_usu FROM pacientes WHERE cod_pac=?', [$patient]);
        $this->login($patientUser);
        $this->get('/paciente/citas/'.$first['codCit'])->assertOk()->assertSee('data:image/png;base64,', false)->assertSee('Pendiente de envío');
        $this->get('/paciente/notificaciones')->assertOk()->assertSee($first['codCit']);
        $otherPatientUser = $this->identity('ROL001');
        $this->login($otherPatientUser);
        $this->get('/paciente/notificaciones')->assertOk()->assertDontSee($first['codCit']);
        $this->postJson('/paciente/citas/'.$first['codCit'].'/correo-qr')->assertStatus(409);
        $this->login($admin);
        $block = app(AdministrationService::class)->request('POST', 'administracion/medicos/'.$doctor['codMed'].'/bloqueos', [
            'fechaBloqueo' => $date, 'horaInicio' => $slots[0]['horaInicio'], 'horaFin' => $slots[0]['horaFin'], 'motivo' => 'AUDIT_TEST bloqueo parcial',
        ]);
        $this->assertSame('Afectada/Pendiente Reprogramación', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$first['codCit']]));
        $this->assertSame('Confirmada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$unaffected['codCit']]));
        $this->assertSame(1, (int) $this->scalar('SELECT count(*) FROM bloqueos_citas_afectadas WHERE cod_blo=?', [$block['codBlo']]));
        $this->get('/pase-qr/'.rawurlencode($token))->assertNotFound();
        $this->login($patientUser);
        $this->get('/paciente/citas')->assertOk()->assertSee($first['codCit'])->assertSee('Requiere reprogramación');
        $this->login($secretary['codUsu']);
        $this->get('/secretaria/citas/'.$first['codCit'].'/reprogramar?fecha='.$date)->assertOk()->assertSee('Confirmar reprogramación');
        $new = $service->request('POST', 'citas/'.$first['codCit'].'/reprogramar', ['codSlot' => $slots[1]['codSlot'], 'motivo' => 'AUDIT_TEST resolver bloqueo']);
        $this->assertSame('Reprogramada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$first['codCit']]));
        $this->assertSame($first['codCit'], $this->scalar('SELECT cod_cit_padre FROM citas WHERE cod_cit=?', [$new['codCit']]));
        $this->assertNotSame($token, $this->scalar('SELECT token_qr FROM citas WHERE cod_cit=?', [$new['codCit']]));
        try {
            $service->request('POST', 'citas/'.$first['codCit'].'/reprogramar', ['codSlot' => $slots[2]['codSlot'], 'motivo' => 'AUDIT_TEST formulario obsoleto']);
            $this->fail('Se permitió reprogramar una cita ya reemplazada.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->login($patientUser);
        $this->get('/paciente/citas/'.$new['codCit'])->assertOk()->assertSee('Pase QR de confirmación');
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM notificaciones WHERE cod_cit=? AND canal='Email' AND tipo_alerta='Confirmacion'", [$new['codCit']]));
    }

    #[Test]
    public function outbox_accepts_mail_once_and_suppresses_obsolete_reminders(): void
    {
        [, $secretary, $patient, $specialty] = $this->operationalFixture();
        $this->login($secretary['codUsu']);
        $date = $this->owner->query('SELECT fecha_clinica()+8')->fetchColumn();
        $service = app(ReceptionService::class);
        $slots = $service->request('GET', 'disponibilidad', ['codPac' => $patient, 'codEsp' => $specialty['codEsp'], 'fecha' => $date]);
        $first = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
        $mailState = fn () => $this->scalar("SELECT estado FROM notificaciones WHERE cod_cit=? AND canal='Email' AND tipo_alerta='Confirmacion' ORDER BY cod_not DESC LIMIT 1", [$first['codCit']]);
        $worker = app(AppointmentNotificationService::class);
        for ($i = 0; $i < 30 && $mailState() !== 'Enviada'; $i++) {
            $worker->process(500);
        }
        $this->assertSame('Enviada', $mailState());
        $this->assertSame(1, (int) $this->scalar("SELECT intentos FROM notificaciones WHERE cod_cit=? AND canal='Email' AND tipo_alerta='Confirmacion'", [$first['codCit']]));
        $this->owner->prepare("UPDATE notificaciones SET estado='EnProceso', en_proceso_desde=now() WHERE cod_cit=? AND tipo_alerta='Recordatorio_24h'")->execute([$first['codCit']]);
        $service->request('POST', 'citas/'.$first['codCit'].'/cancelar', ['motivo' => 'AUDIT_TEST cancelación']);
        $worker->process(500);
        $this->assertSame(0, (int) $this->scalar("SELECT count(*) FROM notificaciones WHERE cod_cit=? AND tipo_alerta='Recordatorio_24h' AND estado IN ('Pendiente','EnProceso','Enviada')", [$first['codCit']]));
        $this->assertSame(1, (int) $this->scalar("SELECT intentos FROM notificaciones WHERE cod_cit=? AND canal='Email' AND tipo_alerta='Confirmacion'", [$first['codCit']]));
    }

    #[Test]
    public function mixed_web_and_desk_reservations_have_only_one_winner(): void
    {
        [, $secretary, $patient, $specialty] = $this->operationalFixture();
        $patientUser = $this->scalar('SELECT cod_usu FROM pacientes WHERE cod_pac=?', [$patient]);
        $otherUser = $this->identity('ROL001');
        $otherPatient = $this->scalar('SELECT cod_pac FROM pacientes WHERE cod_usu=?', [$otherUser]);
        $this->login($secretary['codUsu']);
        $slots = app(ReceptionService::class)->request('GET', 'disponibilidad', ['codPac' => $patient, 'codEsp' => $specialty['codEsp'], 'fecha' => $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn()]);
        $processes = [];
        foreach ([['ConcurrentPatientReserveWorker.php', $patient, $patientUser], ['ConcurrentReserveWorker.php', $otherPatient, $secretary['codUsu']]] as [$file, $pac, $actor]) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Support/'.$file), $pac, $slots[0]['codSlot'], $actor], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $results[] = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process));
        }
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM citas WHERE cod_slot=? AND estado='Confirmada'", [$slots[0]['codSlot']]));
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM notificaciones n JOIN citas c ON c.cod_cit=n.cod_cit WHERE c.cod_slot=? AND n.canal='Email' AND n.tipo_alerta='Confirmacion'", [$slots[0]['codSlot']]));
    }

    #[Test]
    public function failed_email_can_be_retried_without_rebooking_or_leaking_transport_secrets(): void
    {
        [, $secretary, $patient, $specialty] = $this->operationalFixture();
        $this->login($secretary['codUsu']);
        $date = $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $service = app(ReceptionService::class);
        $slots = $service->request('GET', 'disponibilidad', ['codPac' => $patient, 'codEsp' => $specialty['codEsp'], 'fecha' => $date]);
        $booked = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP password=secret-token-do-not-save'));
        $worker = app(AppointmentNotificationService::class);
        $attempts = fn () => (int) $this->scalar("SELECT intentos FROM notificaciones WHERE cod_cit=? AND canal='Email' AND tipo_alerta='Confirmacion'", [$booked['codCit']]);
        for ($i = 0; $i < 30 && $attempts() === 0; $i++) {
            $worker->process(500);
        }
        $this->assertSame(1, $attempts());
        $this->assertSame('Confirmada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$booked['codCit']]));
        $this->assertStringNotContainsString('secret-token', $this->scalar("SELECT ultimo_error FROM notificaciones WHERE cod_cit=? AND canal='Email' AND tipo_alerta='Confirmacion'", [$booked['codCit']]));
        $this->login($this->scalar('SELECT cod_usu FROM pacientes WHERE cod_pac=?', [$patient]));
        $this->get('/paciente/citas/'.$booked['codCit'])->assertOk()->assertSee('Error recuperable')->assertSee('Pase QR de confirmación');
        $this->post('/paciente/citas/'.$booked['codCit'].'/correo-qr')->assertRedirect('/paciente/citas/'.$booked['codCit']);
        $this->assertSame(1, (int) $this->scalar('SELECT count(*) FROM citas WHERE cod_slot=?', [$slots[0]['codSlot']]));
        $this->assertSame(1, (int) $this->scalar("SELECT count(*) FROM notificaciones WHERE cod_cit=? AND canal='Email' AND tipo_alerta='Confirmacion'", [$booked['codCit']]));
    }

    #[Test]
    public function postgres_itself_rejects_dates_outside_the_booking_window(): void
    {
        [$admin, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture();
        $this->login($admin);
        $weekday = (int) $this->owner->query('SELECT extract(isodow FROM fecha_clinica())')->fetchColumn();
        app(AdministrationService::class)->request('POST', 'administracion/medicos/'.$doctor['codMed'].'/horarios', [
            'diaSemana' => $weekday, 'horaInicio' => '22:00', 'horaFin' => '23:40',
        ]);
        $this->login($secretary['codUsu']);
        $db = DB::connection('clinical');
        $lastSlot = $this->scalar('SELECT cod_slot FROM slots_agenda WHERE cod_med=? AND fecha=fecha_clinica()+14 ORDER BY inicio_at LIMIT 1', [$doctor['codMed']]);
        $this->assertNotFalse($lastSlot);
        $this->assertSame('Confirmada', app(ReceptionService::class)->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $lastSlot])['estado']);
        foreach ([-1, 15] as $offset) {
            $slot = $this->scalar('INSERT INTO slots_agenda(cod_hor,cod_med,cod_esp,inicio_at,fin_at)
                SELECT cod_hor,cod_med,cod_esp,fecha_clinica()+?::integer+hora_inicio,fecha_clinica()+?::integer+hora_fin FROM slots_agenda
                WHERE cod_slot=? RETURNING cod_slot', [$offset, $offset, $lastSlot]);
            try {
                $db->selectOne("SELECT reservar_cita(?,?,?,'Ventanilla',NULL)", [$patient, $slot, $secretary['codUsu']]);
                $this->fail('PostgreSQL aceptó una fecha fuera de la ventana.');
            } catch (QueryException $error) {
                $this->assertSame('P4000', $error->errorInfo[0]);
            }
            $this->assertSame(0, (int) $this->scalar('SELECT count(*) FROM citas WHERE cod_slot=?', [$slot]));
        }
        $this->assertSame($this->owner->query("SELECT (now() AT TIME ZONE 'America/La_Paz')::date")->fetchColumn(), $db->scalar('SELECT fecha_clinica()'));
    }

    #[Test]
    public function qr_confirmation_is_sent_immediately_once_for_desk_and_web_without_a_scheduler(): void
    {
        [, $secretary, $patient, $specialty] = $this->operationalFixture();
        $this->login($secretary['codUsu']);
        $service = app(ReceptionService::class);
        $date = $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = $service->request('GET', 'disponibilidad', ['codPac' => $patient, 'codEsp' => $specialty['codEsp'], 'fecha' => $date]);
        $response = $this->post('/secretaria/reservas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot'], 'confirmacion' => 1])->assertRedirect();
        $code = basename($response->headers->get('Location'));
        $worker = app(AppointmentNotificationService::class);
        $this->assertSame('Enviada', $worker->confirmationStatus($code)['correoEstado']);
        $untouched = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[1]['codSlot']]);
        $this->assertSame('Pendiente', DB::connection('clinical')->transaction(fn () => $worker->sendConfirmation($untouched['codCit'])));
        $this->assertSame('Enviada', $worker->sendConfirmation($code));
        $this->assertSame(1, $worker->confirmationStatus($code)['intentos']);
        $this->assertSame(0, $worker->confirmationStatus($untouched['codCit'])['intentos']);
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('image/png', $messages->first()->getOriginalMessage()->getAttachments()[0]->getMediaType().'/'.$messages->first()->getOriginalMessage()->getAttachments()[0]->getMediaSubtype());
        $patientUser = $this->scalar('SELECT cod_usu FROM pacientes WHERE cod_pac=?', [$patient]);
        $this->login($patientUser);
        $this->post('/paciente/citas', ['codSlot' => $slots[2]['codSlot']])->assertRedirect();
        $webCode = $this->scalar('SELECT cod_cit FROM citas WHERE cod_slot=?', [$slots[2]['codSlot']]);
        $this->assertSame('Enviada', $worker->confirmationStatus($webCode)['correoEstado']);
        $this->assertSame(1, $worker->confirmationStatus($webCode)['intentos']);
        $this->assertCount(2, $messages);
        $this->assertSame(3, (int) $this->scalar('SELECT count(*) FROM citas WHERE cod_pac=?', [$patient]));
    }

    #[Test]
    public function automatic_scan_updates_secretary_patient_and_sends_one_arrival_notification(): void
    {
        [, $secretary, $patient, $specialty, $doctor] = $this->operationalFixture(today: true);
        $this->login($secretary['codUsu']);
        $service = app(ReceptionService::class);
        $date = $this->owner->query('SELECT fecha_clinica()')->fetchColumn();
        $slots = $service->request('GET', 'disponibilidad', ['codPac' => $patient, 'codEsp' => $specialty['codEsp'], 'codMed' => $doctor['codMed'], 'fecha' => $date]);
        if ($slots === []) {
            $this->markTestSkipped('Ya no hay un horario futuro del día para crear la cita sintética.');
        }
        $booked = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $slots[0]['codSlot']]);
        $code = $booked['codCit'];
        $token = $this->scalar('SELECT token_qr FROM citas WHERE cod_cit=?', [$code]);
        $result = $this->postJson('/secretaria/checkin', ['tokenQr' => 'https://clinic.test/pase-qr/'.rawurlencode($token)])
            ->assertOk()->assertJsonPath('estado', 'En Espera')->assertJsonPath('codPac', $patient)
            ->assertJsonPath('checkinRegistrado', true)->assertDontSee($token);
        $arrival = $result->json('horaLlegadaCheckin');
        $this->assertNotEmpty($arrival);
        $this->assertSame('En Espera', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$code]));
        $this->assertSame(2, (int) $this->scalar("SELECT count(*) FROM notificaciones WHERE cod_cit=? AND tipo_alerta='Cita_En_Espera'", [$code]));
        $this->assertSame('Enviada', $this->scalar("SELECT estado FROM notificaciones WHERE cod_cit=? AND canal='Email' AND tipo_alerta='Cita_En_Espera'", [$code]));
        $this->postJson('/secretaria/checkin', ['tokenQr' => $token])->assertStatus(409)->assertJsonPath('error', 'CHECKIN_DUPLICADO');
        $this->assertSame($arrival, $this->scalar('SELECT hora_llegada_checkin FROM citas WHERE cod_cit=?', [$code]));
        $this->assertSame(2, (int) $this->scalar("SELECT count(*) FROM notificaciones WHERE cod_cit=? AND tipo_alerta='Cita_En_Espera'", [$code]));
        $this->get('/secretaria/citas?codPac='.$patient)->assertOk()->assertSee($code)->assertSee('En Espera')->assertSee('data-clinical-live-content', false);
        $this->login($this->scalar('SELECT cod_usu FROM pacientes WHERE cod_pac=?', [$patient]));
        $this->get('/paciente/citas/'.$code)->assertOk()->assertViewHas('cita', fn ($cita) => $cita['estado'] === 'En Espera')->assertSee('data-clinical-live-content', false);
        $this->get('/paciente/notificaciones')->assertOk()->assertSee('Registramos tu llegada')->assertSee($code);
        $this->login($secretary['codUsu']);
        $futureDate = $this->owner->query('SELECT fecha_clinica()+7')->fetchColumn();
        $futureSlots = $service->request('GET', 'disponibilidad', ['codPac' => $patient, 'codEsp' => $specialty['codEsp'], 'fecha' => $futureDate]);
        $future = $service->request('POST', 'citas', ['codPac' => $patient, 'codSlot' => $futureSlots[0]['codSlot']]);
        $futureToken = $this->scalar('SELECT token_qr FROM citas WHERE cod_cit=?', [$future['codCit']]);
        $this->postJson('/secretaria/checkin', ['tokenQr' => $futureToken])->assertStatus(409)->assertJsonPath('error', 'QR_OTRA_FECHA')->assertSee($futureDate);
        $this->assertSame('Confirmada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$future['codCit']]));
        $service->request('POST', 'citas/'.$future['codCit'].'/cancelar', ['motivo' => 'Llegada no autorizada de prueba']);
        $this->postJson('/secretaria/checkin', ['tokenQr' => $futureToken])->assertStatus(409)->assertJsonPath('error', 'ESTADO_INCOMPATIBLE')->assertSee('Cancelada');
        $this->postJson('/secretaria/checkin', ['tokenQr' => 'qr-falso'])->assertStatus(400)->assertJsonPath('error', 'QR_INVALIDO');
        $this->assertSame(0, (int) $this->scalar("SELECT count(*) FROM notificaciones WHERE cod_cit=? AND tipo_alerta='Cita_En_Espera'", [$future['codCit']]));
    }

    private function operationalFixture(bool $today = false): array
    {
        $admin = $this->identity('ROL004');
        $this->login($admin);
        $administration = app(AdministrationService::class);
        $specialty = $administration->request('POST', 'personal/especialidades', ['nombreEspecialidad' => 'Fase3 '.bin2hex(random_bytes(4)), 'descripcion' => 'PHASE3_TEST', 'duracionConsultaMin' => 20]);
        $doctor = $administration->request('POST', 'personal/medicos', ['nombreCompleto' => 'Médico Operativo', 'email' => $this->email('doctor'), 'password' => $this->fixturePassword, 'ci' => 'DOC-'.random_int(100000, 999999), 'matriculaProfesional' => 'MAT-'.bin2hex(random_bytes(4)), 'duracionConsultaMin' => 20, 'codigosEspecialidades' => [$specialty['codEsp']]]);
        $secretary = $administration->request('POST', 'personal/secretarias', ['nombreCompleto' => 'Secretaría Operativa', 'email' => $this->email('secretary'), 'password' => $this->fixturePassword, 'ci' => 'SEC-'.random_int(100000, 999999), 'telefono' => '70000000']);
        $offset = $today ? 0 : 1;
        $day = (int) $this->owner->query("SELECT extract(isodow FROM fecha_clinica()+$offset)")->fetchColumn();
        $start = $today ? $this->owner->query("SELECT to_char(date_trunc('hour',ahora_clinica())+interval '1 hour','HH24:MI')")->fetchColumn() : '08:00';
        $end = $today ? $this->owner->query("SELECT to_char(least(date_trunc('hour',ahora_clinica())+interval '4 hours',fecha_clinica()+interval '23 hours 59 minutes'),'HH24:MI')")->fetchColumn() : '12:00';
        if ($start >= $end) {
            $start = '00:01';
        }
        $administration->request('POST', 'administracion/medicos/'.$doctor['codMed'].'/horarios', ['diaSemana' => $day, 'horaInicio' => $start, 'horaFin' => $end]);
        $patientUser = $this->identity('ROL001');
        $patient = $this->scalar('SELECT cod_pac FROM pacientes WHERE cod_usu=?', [$patientUser]);

        return [$admin, $secretary, $patient, $specialty, $doctor];
    }

    private function identity(string $role): string
    {
        $stmt = $this->owner->prepare("INSERT INTO usuarios(nombre_completo,email,password_hash,cod_rol,estado) VALUES ('PHASE3 TEST',?,?,?,'Activo') RETURNING cod_usu");
        $stmt->execute([$this->email(strtolower($role)), Hash::make($this->fixturePassword), $role]);
        $user = $stmt->fetchColumn();
        if ($role === 'ROL001') {
            $this->owner->prepare("INSERT INTO pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,estado_cuenta,email_verificado,email_verificado_en) VALUES (?,?,DATE '1990-01-01','70000000','La Paz','Activo',true,now())")->execute([$user, 'PAC-'.bin2hex(random_bytes(5))]);
        }

        return $user;
    }

    private function login(string $user): void
    {
        Auth::guard('web')->logout();
        $record = Usuario::query()->findOrFail($user);
        Auth::guard('web')->login($record);
        $this->withSession(['auth_authority' => 'laravel', 'cod_usu' => $user, 'cod_rol' => $record->cod_rol,
            'auth_expires_at' => now()->addHours(2)->toIso8601String(), 'nombre_usuario' => $record->nombre_completo]);
    }

    private function email(string $prefix): string
    {
        return $prefix.'.'.bin2hex(random_bytes(6)).'@phase3.invalid';
    }

    private function scalar(string $sql, array $bindings = []): mixed
    {
        $stmt = $this->owner->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->fetchColumn();
    }
}
