<?php

namespace Tests\Postgres;

use App\Exceptions\ClinicalOperationException;
use App\Http\Middleware\PatientResponses;
use App\Models\Usuario;
use App\Services\Administration\AdministrationService;
use App\Services\Paciente\PatientAppointmentService;
use App\Services\Paciente\PatientAvailabilityService;
use App\Services\Paciente\PatientProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Pruebas reales de integración PostgreSQL para los contratos backend del panel Paciente (Fase 6). */
final class PatientBackendMigrationTest extends TestCase
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
    public function patient_role_guard_rejects_wrong_roles_inactive_unverified_and_unauthenticated_accounts(): void
    {
        Http::fake();
        $profileService = app(PatientProfileService::class);
        $appointmentService = app(PatientAppointmentService::class);

        // Sin autenticación
        try {
            $profileService->profile();
            $this->fail('PacienteProfileService permitió usuario no autenticado.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(403, $error->status);
        }

        // Otro rol (ROL002 / ROL003 / ROL004)
        foreach (['ROL002', 'ROL003', 'ROL004'] as $role) {
            $user = $this->createIdentity($role);
            $this->login($user);
            try {
                $profileService->profile();
                $this->fail("Rol $role accedió al servicio de paciente.");
            } catch (ClinicalOperationException $error) {
                $this->assertSame(403, $error->status);
            }
        }

        // Usuario ROL001 Inactivo
        $inactiveUser = $this->createIdentity('ROL001', userState: 'Inactivo');
        $this->login($inactiveUser);
        try {
            $profileService->profile();
            $this->fail('Usuario Inactivo accedió al servicio.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(403, $error->status);
        }

        // Perfil paciente Inactivo
        $inactiveProfile = $this->createIdentity('ROL001', accountState: 'Inactivo');
        $this->login($inactiveProfile);
        try {
            $appointmentService->list();
            $this->fail('Perfil Inactivo accedió al servicio.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(403, $error->status);
        }

        // Correo sin verificar
        $unverified = $this->createIdentity('ROL001', emailVerified: false);
        $this->login($unverified);
        try {
            $profileService->profile();
            $this->fail('Correo sin verificar accedió al servicio.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(403, $error->status);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function patient_consults_availability_reserves_web_excludes_overlaps_lists_and_shows_safe_qr_pass(): void
    {
        Http::fake();
        [, $patientA, $specialty, $doctor] = $this->operationalFixture();
        $availability = app(PatientAvailabilityService::class);
        $appointments = app(PatientAppointmentService::class);
        $profile = app(PatientProfileService::class);

        // Catálogo público de especialidades y médicos activos
        $specialties = $availability->specialties();
        $this->assertContains($specialty['codEsp'], array_column($specialties, 'codEsp'));
        $doctors = $availability->doctors($specialty['codEsp']);
        $this->assertSame([$doctor['codMed']], array_column($doctors, 'codMed'));

        // Autenticado como paciente A
        $this->login($patientA['codUsu']);
        $me = $profile->profile();
        $this->assertSame($patientA['codPac'], $me['codPac']);
        $this->assertTrue($me['habilitadoParaReservar']);

        $date = (string) $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = $availability->slots(['codEsp' => $specialty['codEsp'], 'codMed' => $doctor['codMed'], 'fecha' => $date]);
        $this->assertGreaterThanOrEqual(2, count($slots['slots']));

        // Ventana D+15 rechazada
        $outOfWindow = (string) $this->owner->query('SELECT fecha_clinica()+15')->fetchColumn();
        try {
            $availability->slots(['codEsp' => $specialty['codEsp'], 'fecha' => $outOfWindow]);
            $this->fail('Se permitió disponibilidad fuera de D..D+14.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(400, $error->status);
            $this->assertSame('FECHA_INVALIDA', $error->errorCode);
        }

        // Reserva canal Web
        $firstSlot = $slots['slots'][0]['codSlot'];
        $booked = $appointments->reserve($firstSlot);
        $this->assertSame('Confirmada', $booked['estado']);
        $this->assertSame('Web', $booked['canalReserva']);
        $this->assertSame($patientA['codPac'], $booked['codPac']);
        $this->assertArrayNotHasKey('tokenQr', $booked);
        $this->assertTrue($booked['qrDisponible']);

        // Exclusión de solapamiento en disponibilidad tras reservar
        $afterReserve = $availability->slots(['codEsp' => $specialty['codEsp'], 'codMed' => $doctor['codMed'], 'fecha' => $date]);
        $this->assertNotContains($firstSlot, array_column($afterReserve['slots'], 'codSlot'));

        // Doble reserva en el mismo slot rechazada (409)
        try {
            $appointments->reserve($firstSlot);
            $this->fail('Se aceptó doble reserva sobre slot ocupado.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
        }

        // Listado propias citas (proximas / todas) + bitácora con actor real
        $upcoming = $appointments->list(['alcance' => 'proximas']);
        $this->assertSame([$booked['codCit']], array_column($upcoming['citas'], 'codCit'));
        $this->assertGreaterThan(0, (int) $this->scalar(
            "SELECT count(*) FROM bitacora WHERE cod_usu=? AND nombre_tabla='citas' AND clave_registro=?",
            [$patientA['codUsu'], $booked['codCit']],
        ));

        // Pase QR seguro (no expone secreto ni ejecuta check-in)
        $qrPass = $appointments->checkInPass($booked['codCit']);
        $this->assertSame($booked['codCit'], $qrPass['codCit']);
        $this->assertNotEmpty($qrPass['tokenQr']);
        $this->assertSame('Confirmada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$booked['codCit']]));

        Http::assertNothingSent();
    }

    #[Test]
    public function patient_idor_isolation_blocks_access_and_mutations_on_another_patients_records(): void
    {
        Http::fake();
        [, $patientA, $specialty, $doctor] = $this->operationalFixture();
        $patientB = $this->createPatientFixture();
        $appointments = app(PatientAppointmentService::class);
        $profiles = app(PatientProfileService::class);

        $this->login($patientA['codUsu']);
        $date = (string) $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = app(PatientAvailabilityService::class)->slots(['codEsp' => $specialty['codEsp'], 'fecha' => $date]);
        $citaA = $appointments->reserve($slots['slots'][0]['codSlot']);
        $freeSlot = $slots['slots'][1]['codSlot'];

        // Se autentica Paciente B e intenta leer/operar sobre la cita de A
        $this->login($patientB['codUsu']);
        foreach ([
            fn () => $appointments->detail($citaA['codCit']),
            fn () => $appointments->checkInPass($citaA['codCit']),
            fn () => $appointments->cancel($citaA['codCit'], 'Intento IDOR'),
            fn () => $appointments->reschedule($citaA['codCit'], $freeSlot, 'Intento IDOR'),
            fn () => $profiles->analyses($citaA['codCit']),
        ] as $call) {
            try {
                $call();
                $this->fail('Operación IDOR permitida sobre cita ajena.');
            } catch (ClinicalOperationException $error) {
                $this->assertSame(404, $error->status);
            }
        }

        // Derivaciones, incidencias y citas listadas para B no contienen registros de A
        $this->assertSame([], $appointments->list()['citas']);
        $this->assertSame([], $profiles->referrals()['derivaciones']);
        $this->assertSame([], $profiles->incidences()['incidencias']);
        Http::assertNothingSent();
    }

    #[Test]
    public function patient_can_reschedule_and_cancel_future_appointment_and_cannot_mutate_expired_or_non_confirmed(): void
    {
        Http::fake();
        [, $patient, $specialty, $doctor] = $this->operationalFixture();
        $appointments = app(PatientAppointmentService::class);

        $this->login($patient['codUsu']);
        $date = (string) $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = app(PatientAvailabilityService::class)->slots(['codEsp' => $specialty['codEsp'], 'fecha' => $date])['slots'];
        $this->assertGreaterThanOrEqual(3, count($slots));

        $first = $appointments->reserve($slots[0]['codSlot']);
        $rescheduled = $appointments->reschedule($first['codCit'], $slots[1]['codSlot'], 'Cambio de turno personal');
        $this->assertSame('Confirmada', $rescheduled['estado']);
        $this->assertSame('Web', $rescheduled['canalReserva']);
        $this->assertSame($first['codCit'], $rescheduled['codCitPadre']);
        $this->assertSame('Reprogramada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$first['codCit']]));

        $cancelled = $appointments->cancel($rescheduled['codCit'], 'No podré asistir');
        $this->assertSame('Cancelada', $cancelled['estado']);

        // Cita ya cancelada no tiene pase QR
        try {
            $appointments->checkInPass($cancelled['codCit']);
            $this->fail('Cita cancelada entregó pase QR.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
            $this->assertSame('QR_NO_DISPONIBLE', $error->errorCode);
        }

        // Cita Confirmada vencida antes del cron: bloqueada para cancelar/reprogramar por paciente (CITA_VENCIDA)
        $expiredTarget = $appointments->reserve($slots[2]['codSlot']);
        $this->owner->prepare("UPDATE slots_agenda SET inicio_at=fecha_clinica()-interval '2 hours',fin_at=fecha_clinica()-interval '1 hour' WHERE cod_slot=?")
            ->execute([$expiredTarget['codSlot']]);
        $this->owner->prepare("UPDATE citas SET motivo_reprogramacion='PHASE6_TEST vencida' WHERE cod_cit=?")
            ->execute([$expiredTarget['codCit']]);

        foreach ([
            fn () => $appointments->cancel($expiredTarget['codCit'], 'Ya pasó la hora'),
            fn () => $appointments->reschedule($expiredTarget['codCit'], $slots[0]['codSlot'], 'Ya pasó la hora'),
        ] as $call) {
            try {
                $call();
                $this->fail('Se permitió cancelar o reprogramar cita vencida por paciente.');
            } catch (ClinicalOperationException $error) {
                $this->assertSame(409, $error->status);
                $this->assertSame('CITA_VENCIDA', $error->errorCode);
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function sanctioned_patient_cannot_reserve_or_reschedule_and_can_read_own_incidences_referrals_and_analyses(): void
    {
        Http::fake();
        [$admin, $patient, $specialty, $doctor] = $this->operationalFixture();
        $appointments = app(PatientAppointmentService::class);
        $profiles = app(PatientProfileService::class);

        $this->login($patient['codUsu']);
        $date = (string) $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = app(PatientAvailabilityService::class)->slots(['codEsp' => $specialty['codEsp'], 'fecha' => $date])['slots'];
        $booked = $appointments->reserve($slots[0]['codSlot']);

        // Registrar derivación, análisis completado y sanción usando privilegio propietario de fixture (Confirmada -> En Espera -> Atendida)
        $this->owner->prepare("UPDATE citas SET estado='En Espera', hora_llegada_checkin=now(), checkin_por_usu=? WHERE cod_cit=?")
            ->execute([$admin, $booked['codCit']]);
        $this->owner->prepare("UPDATE citas SET estado='Atendida', fecha_atencion=now(), atendida_por_usu=? WHERE cod_cit=?")
            ->execute([$doctor['codUsu'], $booked['codCit']]);
        $this->owner->prepare("INSERT INTO derivaciones(cod_cit_origen,cod_med_emisor,cod_esp_destino,cod_med_destino,motivo_derivacion,estado) VALUES (?,?,?,?,'Control cardiológico','Pendiente')")
            ->execute([$booked['codCit'], $doctor['codMed'], $specialty['codEsp'], $doctor['codMed']]);
        $this->owner->prepare("INSERT INTO analisis(cod_cit,solicitado_por_usu,tipo_analisis,estado,resultado) VALUES (?,?,?,'Completado','Sin hallazgos patológicos')")
            ->execute([$booked['codCit'], $doctor['codUsu'], 'Hemograma completo']);

        // Lectura de derivaciones y análisis propios (sin mutación)
        $referrals = $profiles->referrals();
        $this->assertCount(1, $referrals['derivaciones']);
        $this->assertSame('Pendiente', $referrals['derivaciones'][0]['estado']);

        $analyses = $profiles->analyses($booked['codCit']);
        $this->assertCount(1, $analyses['analisis']);
        $this->assertSame('Hemograma completo', $analyses['analisis'][0]['tipoAnalisis']);

        // Reservar cita activa antes de sancionar y luego aplicar sanción temporal vía Administración
        $second = $appointments->reserve($slots[1]['codSlot']);
        $this->login($admin);
        app(AdministrationService::class)->request('POST', 'administracion/pacientes/'.$patient['codPac'].'/sanciones', [
            'motivo' => 'PHASE6_TEST sanción temporal',
            'dias' => 2,
        ]);

        // Paciente sancionado: perfil e incidencias visibles, nueva reserva/reprogramación bloqueadas
        $this->login($patient['codUsu']);
        $me = $profiles->profile();
        $this->assertFalse($me['habilitadoParaReservar']);
        $this->assertNotNull($me['sancionVigente']);
        $incidences = $profiles->incidences();
        $this->assertCount(1, $incidences['incidencias']);
        $this->assertSame('PHASE6_TEST sanción temporal', $incidences['incidencias'][0]['motivo']);

        try {
            $appointments->reserve($slots[2]['codSlot']);
            $this->fail('Paciente sancionado pudo reservar.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
            $this->assertSame('PACIENTE_NO_HABILITADO', $error->errorCode);
        }

        try {
            $appointments->reschedule($second['codCit'], $slots[2]['codSlot'], 'Intento sancionado');
            $this->fail('Paciente sancionado pudo reprogramar.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
            $this->assertSame('PACIENTE_NO_HABILITADO', $error->errorCode);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function patient_frontend_routes_complete_booking_detail_reschedule_and_cancel_against_postgresql(): void
    {
        Http::fake();
        [, $patient, $specialty, $doctor] = $this->operationalFixture();
        Auth::guard('web')->logout();
        $email = (string) $this->scalar('SELECT email FROM usuarios WHERE cod_usu=?', [$patient['codUsu']]);

        $this->post('/login', ['email' => $email, 'password' => $this->fixturePassword])
            ->assertRedirect('/paciente/inicio');
        $this->get('/paciente/inicio')
            ->assertOk()
            ->assertViewIs('paciente.home')
            ->assertSee('Tu próxima cita');

        $date = (string) $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = app(PatientAvailabilityService::class)->slots([
            'codEsp' => $specialty['codEsp'],
            'codMed' => $doctor['codMed'],
            'fecha' => $date,
        ])['slots'];
        $this->assertGreaterThanOrEqual(2, count($slots));

        $this->get('/paciente/citas/nueva?'.http_build_query([
            'codEsp' => $specialty['codEsp'],
            'codMed' => $doctor['codMed'],
            'fecha' => $date,
        ]))
            ->assertOk()
            ->assertViewIs('paciente.nueva-cita')
            ->assertSee($specialty['nombreEspecialidad'])
            ->assertSee($doctor['nombreCompleto']);

        $reserve = $this->post('/paciente/citas', [
            'codSlot' => $slots[0]['codSlot'],
            'codPac' => 'PACIENTE-AJENO',
        ]);
        $bookedCode = (string) $this->scalar(
            "SELECT cod_cit FROM citas WHERE cod_pac=? AND cod_slot=? AND estado='Confirmada'",
            [$patient['codPac'], $slots[0]['codSlot']],
        );
        $reserve->assertRedirect('/paciente/citas/'.$bookedCode);

        $this->get('/paciente/citas/'.$bookedCode)
            ->assertOk()
            ->assertViewIs('paciente.detalle')
            ->assertSee($bookedCode)
            ->assertSee('Cancelar cita')
            ->assertSee('Reprogramar');

        $this->get('/paciente/citas/'.$bookedCode.'/reprogramar?'.http_build_query([
            'codMed' => $doctor['codMed'],
            'fecha' => $date,
        ]))
            ->assertOk()
            ->assertViewIs('paciente.reprogramar');
        $reschedule = $this->post('/paciente/citas/'.$bookedCode.'/reprogramar', [
            'codSlotNuevo' => $slots[1]['codSlot'],
            'motivo' => 'Cambio validado desde el panel',
            'confirmacion' => '1',
            'codPac' => 'PACIENTE-AJENO',
        ]);
        $newCode = (string) $this->scalar(
            "SELECT cod_cit FROM citas WHERE cod_cit_padre=? AND estado='Confirmada'",
            [$bookedCode],
        );
        $reschedule->assertRedirect('/paciente/citas/'.$newCode);
        $this->assertSame('Reprogramada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$bookedCode]));

        $this->post('/paciente/citas/'.$newCode.'/cancelar', [
            'motivo' => 'Cancelación validada desde el panel',
            'confirmacion' => '1',
            'codPac' => 'PACIENTE-AJENO',
        ])->assertRedirect('/paciente/citas?tab=canceladas');
        $this->assertSame('Cancelada', $this->scalar('SELECT estado FROM citas WHERE cod_cit=?', [$newCode]));
        $this->get('/paciente/citas?tab=canceladas')
            ->assertOk()
            ->assertSee($newCode);

        Http::assertNothingSent();
    }

    #[Test]
    public function concurrent_web_reservations_by_patient_have_single_winner_and_clean_middleware_headers(): void
    {
        Http::fake();
        [, $patient, $specialty, $doctor] = $this->operationalFixture();
        $this->login($patient['codUsu']);
        $date = (string) $this->owner->query('SELECT fecha_clinica()+1')->fetchColumn();
        $slots = app(PatientAvailabilityService::class)->slots(['codEsp' => $specialty['codEsp'], 'fecha' => $date])['slots'];
        $targetSlot = $slots[0]['codSlot'];

        $command = [PHP_BINARY, base_path('tests/Support/ConcurrentPatientReserveWorker.php'), $patient['codPac'], $targetSlot, $patient['codUsu']];
        $processes = [];
        foreach ([1, 2] as $_) {
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

        $this->assertCount(1, array_filter($results, fn (array $r): bool => $r['ok']));
        $loser = collect($results)->firstWhere('ok', false);
        $this->assertContains($loser['state'], ['P4090', '23P01', '23505']);
        $this->assertSame(1, (int) $this->scalar(
            "SELECT count(*) FROM citas WHERE cod_slot=? AND estado NOT IN ('Cancelada','Reprogramada')",
            [$targetSlot],
        ));

        // Verificar respuesta segura de PatientResponses sin fugas internas y actor PostgreSQL limpio tras transacción
        $req = Request::create('/paciente/citas', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $resp = PatientResponses::renderFailure($req, new ClinicalOperationException(409, 'Conflicto controlado', 'CONFLICTO'));
        $this->assertSame(409, $resp->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $resp->headers->get('Referrer-Policy'));
        $this->assertNull(DB::connection('clinical')->scalar('SELECT public.actor_actual()'));

        Http::assertNothingSent();
    }

    private function operationalFixture(): array
    {
        $admin = $this->createIdentity('ROL004');
        $this->login($admin);
        $administration = app(AdministrationService::class);
        $specialty = $administration->request('POST', 'personal/especialidades', [
            'nombreEspecialidad' => 'PacienteEsp '.bin2hex(random_bytes(4)),
            'descripcion' => 'PHASE6_TEST',
            'duracionConsultaMin' => 20,
        ]);
        $doctor = $administration->request('POST', 'personal/medicos', [
            'nombreCompleto' => 'Dr. Paciente Test',
            'email' => $this->email('doc'),
            'password' => $this->fixturePassword,
            'ci' => 'DOC6-'.random_int(100000, 999999),
            'matriculaProfesional' => 'MAT6-'.bin2hex(random_bytes(4)),
            'duracionConsultaMin' => 20,
            'codigosEspecialidades' => [$specialty['codEsp']],
        ]);
        $day = (int) $this->owner->query('SELECT extract(isodow FROM fecha_clinica()+1)')->fetchColumn();
        $administration->request('POST', 'administracion/medicos/'.$doctor['codMed'].'/horarios', [
            'diaSemana' => $day,
            'horaInicio' => '08:00',
            'horaFin' => '12:00',
        ]);
        $patient = $this->createPatientFixture();

        return [$admin, $patient, $specialty, $doctor];
    }

    private function createPatientFixture(): array
    {
        $codUsu = $this->createIdentity('ROL001');
        $codPac = (string) $this->scalar('SELECT cod_pac FROM pacientes WHERE cod_usu=?', [$codUsu]);

        return ['codUsu' => $codUsu, 'codPac' => $codPac];
    }

    private function createIdentity(
        string $role,
        string $userState = 'Activo',
        string $accountState = 'Activo',
        bool $emailVerified = true,
    ): string {
        $stmt = $this->owner->prepare(
            "INSERT INTO usuarios(nombre_completo,email,password_hash,cod_rol,estado) VALUES ('PHASE6 PATIENT TEST',?,?,?,?) RETURNING cod_usu",
        );
        $stmt->execute([$this->email(strtolower($role)), Hash::make($this->fixturePassword), $role, $userState]);
        $user = (string) $stmt->fetchColumn();
        if ($role === 'ROL001') {
            $this->owner->prepare(
                "INSERT INTO pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,estado_cuenta,email_verificado,email_verificado_en) VALUES (?,?,DATE '1992-05-10','70000000','La Paz',?,?,now())",
            )->execute([$user, 'P6-'.bin2hex(random_bytes(5)), $accountState, $emailVerified ? 'true' : 'false']);
        }

        return $user;
    }

    private function login(string $user): void
    {
        Auth::guard('web')->logout();
        Auth::guard('web')->login(Usuario::query()->findOrFail($user));
    }

    private function email(string $prefix): string
    {
        return $prefix.'.'.bin2hex(random_bytes(6)).'@phase6.invalid';
    }

    private function scalar(string $sql, array $bindings = []): mixed
    {
        $stmt = $this->owner->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->fetchColumn();
    }
}
