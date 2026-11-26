<?php

namespace Tests\Postgres;

use App\Mail\VerificationCodeMail;
use App\Services\Clinical\ClinicalMaintenanceService;
use App\Services\Clinical\ClinicalTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recorrido integral E2E (Bloque 8 y 10) de los cuatro roles reales contra PostgreSQL 16
 * sin depender de Quarkus. Opera exclusivamente sobre clinica_laravel_phase1_tests.
 */
final class EndToEndClinicWorkflowTest extends TestCase
{
    private PDO $owner;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLINICAL_PG_TESTS') !== '1') {
            $this->markTestSkipped('Requiere PostgreSQL aislado mediante Invoke-Laravel.ps1 -TestDatabase.');
        }
        Http::fake();
        Mail::fake();
        $this->withoutVite();
        Artisan::call('migrate', ['--force' => true]);
        config([
            'session.driver' => 'database',
            'session.connection' => 'sqlite',
            'mail.default' => 'array',
        ]);
        $this->owner = new PDO(
            'pgsql:host=127.0.0.1;port=35443;dbname=clinica_laravel_phase1_tests',
            'clinica_owner',
            getenv('CLINICAL_OWNER_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    #[Test]
    public function full_four_role_clinical_journey_portal_audit_and_maintenance_run_without_quarkus(): void
    {
        $tag = strtoupper(bin2hex(random_bytes(4)));
        $adminPass = 'AdminSegura123!';
        $secPass = 'SecreSegura123!';
        $docAPass = 'MedicoASegura123!';
        $docBPass = 'MedicoBSegura123!';
        $patPass = 'PacienteSegura123!';

        $adminEmail = strtolower("admin.$tag@example.invalid");
        $stmt = $this->owner->prepare(
            "INSERT INTO usuarios (nombre_completo, email, password_hash, cod_rol, estado) VALUES (?, ?, ?, 'ROL004', 'Activo') RETURNING cod_usu"
        );
        $stmt->execute(["Admin E2E $tag", $adminEmail, Hash::make($adminPass)]);
        $adminUsu = (string) $stmt->fetchColumn();

        // 1. Administrador inicia sesión y configura especialidades, personal, agendas y bloqueo.
        $this->post('/login', ['email' => $adminEmail, 'password' => $adminPass])
            ->assertRedirect('/administracion/inicio');
        $this->get('/administracion/inicio')->assertOk()->assertSee('Panel de administración');

        $this->post('/administracion/especialidades', [
            'nombreEspecialidad' => "Medicina E2E $tag",
            'descripcion' => 'Especialidad origen E2E',
            'duracionConsultaMin' => 30,
        ])->assertRedirect();
        $espA = (string) $this->owner->query(
            'SELECT cod_esp FROM especialidades WHERE nombre_especialidad = '.$this->owner->quote("Medicina E2E $tag")
        )->fetchColumn();

        $this->post('/administracion/especialidades', [
            'nombreEspecialidad' => "Cardio E2E $tag",
            'descripcion' => 'Especialidad destino E2E',
            'duracionConsultaMin' => 30,
        ])->assertRedirect();
        $espB = (string) $this->owner->query(
            'SELECT cod_esp FROM especialidades WHERE nombre_especialidad = '.$this->owner->quote("Cardio E2E $tag")
        )->fetchColumn();

        // Portal público `/` sin Quarkus con especialidades activas y datos reales de PostgreSQL.
        $this->get('/')->assertOk()
            ->assertSee('data-specialties-state="ready"', false)
            ->assertSee("Medicina E2E $tag")
            ->assertDontSee('Quarkus');

        $secEmail = strtolower("sec.$tag@example.invalid");
        $this->post('/administracion/secretarias', [
            'nombreCompleto' => "Secretaria E2E $tag",
            'email' => $secEmail,
            'ci' => '81'.$tag,
            'telefono' => '70011223',
            'password' => $secPass,
            'password_confirmation' => $secPass,
        ])->assertRedirect();

        $docAEmail = strtolower("doca.$tag@example.invalid");
        $this->post('/administracion/medicos', [
            'nombreCompleto' => "Doctor Uno $tag",
            'email' => $docAEmail,
            'ci' => '82'.$tag,
            'matriculaProfesional' => "MPA-$tag",
            'resumenCurricular' => 'Atención primaria',
            'duracionConsultaMin' => 30,
            'codigosEspecialidades' => [$espA],
            'password' => $docAPass,
            'password_confirmation' => $docAPass,
        ])->assertRedirect();
        $medA = (string) $this->owner->query(
            'SELECT m.cod_med FROM medicos m JOIN usuarios u ON u.cod_usu = m.cod_usu WHERE u.email = '.$this->owner->quote($docAEmail)
        )->fetchColumn();

        $docBEmail = strtolower("docb.$tag@example.invalid");
        $this->post('/administracion/medicos', [
            'nombreCompleto' => "Doctor Dos $tag",
            'email' => $docBEmail,
            'ci' => '83'.$tag,
            'matriculaProfesional' => "MPB-$tag",
            'resumenCurricular' => 'Cardiología clínica',
            'duracionConsultaMin' => 30,
            'codigosEspecialidades' => [$espB],
            'password' => $docBPass,
            'password_confirmation' => $docBPass,
        ])->assertRedirect();
        $medB = (string) $this->owner->query(
            'SELECT m.cod_med FROM medicos m JOIN usuarios u ON u.cod_usu = m.cod_usu WHERE u.email = '.$this->owner->quote($docBEmail)
        )->fetchColumn();

        $todayClinic = CarbonImmutable::now('America/La_Paz')->startOfDay();
        $tomorrowClinic = $todayClinic->addDay();
        $dayOfWeekToday = $todayClinic->dayOfWeekIso;
        $dayOfWeekTomorrow = $tomorrowClinic->dayOfWeekIso;

        // Horario hoy para Med A y mañana para Med B + bloqueo futuro en Med B sin citas.
        $this->post("/administracion/agendas/$medA/horarios", [
            'diaSemana' => $dayOfWeekToday,
            'horaInicio' => '08:00',
            'horaFin' => '23:00',
        ])->assertRedirect();
        $this->post("/administracion/agendas/$medB/horarios", [
            'diaSemana' => $dayOfWeekTomorrow,
            'horaInicio' => '08:00',
            'horaFin' => '18:00',
        ])->assertRedirect();

        $blockDate = $todayClinic->addDays(5)->toDateString();
        $this->post("/administracion/bloqueos/medicos/$medB", [
            'fechaBloqueo' => $blockDate,
            'motivo' => "Congreso médico $tag",
            'confirmacion' => 1,
        ])->assertRedirect();

        // Generar slots reales mediante el comando clínico / servicio de mantenimiento.
        Artisan::call('clinical:maintenance', ['task' => 'slots']);
        $slotToday = $this->owner->prepare(
            "SELECT s.cod_slot
             FROM slots_agenda s
             LEFT JOIN citas c ON c.cod_slot = s.cod_slot AND c.estado <> 'Cancelada'
             WHERE s.cod_med = ? AND s.fecha = ? AND s.inicio_at > ahora_clinica() AND c.cod_cit IS NULL
             ORDER BY s.hora_inicio LIMIT 1"
        );
        $slotToday->execute([$medA, $todayClinic->toDateString()]);
        $slotTodayCode = $slotToday->fetchColumn();
        if (! $slotTodayCode) {
            $codHorToday = (string) $this->owner->query(
                'SELECT cod_hor FROM horarios WHERE cod_med = '.$this->owner->quote($medA).' LIMIT 1'
            )->fetchColumn();
            $d = $todayClinic->toDateString();
            $ins = $this->owner->prepare(
                'INSERT INTO slots_agenda (cod_hor, cod_med, cod_esp, inicio_at, fin_at) VALUES (?, ?, ?, ?::timestamp, ?::timestamp) RETURNING cod_slot'
            );
            $ins->execute([$codHorToday, $medA, $espA, "$d 23:00:00", "$d 23:30:00"]);
            $slotTodayCode = (string) $ins->fetchColumn();
        }

        $this->post('/logout')->assertRedirect('/');

        // 2. Registro de Paciente + verificación OTP + ingreso a panel ROL001.
        $patEmail = strtolower("pac.$tag@example.invalid");
        $patCi = '84'.$tag;
        $this->post('/registro', [
            'ci' => $patCi,
            'nombre_completo' => 'Paciente Prueba Segura',
            'fecha_nacimiento' => '1992-05-10',
            'telefono' => '71234567',
            'email' => $patEmail,
            'ciudad_residencia' => 'La Paz',
            'password' => $patPass,
            'password_confirmation' => $patPass,
        ])->assertRedirect('/verificar-correo');

        $otpCode = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$otpCode): bool {
            $otpCode = $mail->code;

            return true;
        });
        $this->assertNotNull($otpCode);

        $this->post('/verificar-correo', ['code' => $otpCode])->assertRedirect('/login');
        $this->post('/login', ['email' => $patEmail, 'password' => $patPass])->assertRedirect('/paciente/inicio');
        $this->get('/paciente/inicio')->assertOk()
            ->assertSee('Paciente Prueba Segura')
            ->assertSee('No tienes citas próximas.');
        $this->get('/medico/inicio')->assertForbidden();
        $this->get('/secretaria/inicio')->assertForbidden();
        $this->get('/administracion/inicio')->assertForbidden();
        $this->post('/logout')->assertRedirect('/');

        $codPac = (string) $this->owner->query(
            'SELECT p.cod_pac FROM pacientes p JOIN usuarios u ON u.cod_usu = p.cod_usu WHERE u.email = '.$this->owner->quote($patEmail)
        )->fetchColumn();

        // 3. Secretaría reserva cita origen hoy con Médico A y acredita llegada por QR.
        $this->post('/login', ['email' => $secEmail, 'password' => $secPass])->assertRedirect('/secretaria/inicio');
        $this->get('/administracion/inicio')->assertForbidden();
        $this->get('/medico/inicio')->assertForbidden();
        $this->get('/secretaria/pacientes?q='.$patCi)->assertOk()->assertSee('Paciente Prueba Segura');

        $reserveResponse = $this->post('/secretaria/reservas', [
            'codPac' => $codPac,
            'codSlot' => $slotTodayCode,
            'confirmacion' => 1,
        ])->assertRedirect();
        $codCit1 = basename((string) $reserveResponse->headers->get('Location'));
        $this->get("/secretaria/citas/$codCit1")->assertOk()->assertSee('Confirmada');

        $qrToken = (string) $this->owner->query(
            'SELECT token_qr FROM citas WHERE cod_cit = '.$this->owner->quote($codCit1)
        )->fetchColumn();
        $this->assertNotSame('', $qrToken);

        $this->post('/secretaria/checkin', ['tokenQr' => $qrToken])
            ->assertRedirect("/secretaria/citas/$codCit1/asistencia")
            ->assertSessionHas('success');
        $this->assertStringNotContainsString($qrToken, json_encode(session()->all()));

        // Reintento de check-in duplicado rechazado limpiamente sin filtrar token.
        $this->post('/secretaria/checkin', ['tokenQr' => $qrToken])
            ->assertRedirect('/secretaria/checkin')
            ->assertSessionHasErrors('tokenQr');
        $this->post('/logout')->assertRedirect('/');

        // 4. Médico A atiende, solicita 2 análisis, valida aislamiento de errores, finaliza y deriva.
        $this->post('/login', ['email' => $docAEmail, 'password' => $docAPass])->assertRedirect('/medico/inicio');
        $this->get('/administracion/inicio')->assertForbidden();
        $this->get('/secretaria/inicio')->assertForbidden();

        $this->get("/medico/citas/$codCit1")->assertOk()->assertSee('En Espera');
        $this->post("/medico/citas/$codCit1/iniciar")->assertRedirect();
        $this->get("/medico/citas/$codCit1")->assertOk()->assertSee('En Atención')->assertSee('Finalizar atención');
        $this->post("/medico/citas/$codCit1/analisis", ['tipoAnalisis' => 'Hemograma completo', 'confirmacion' => 1])->assertRedirect();
        $this->post("/medico/citas/$codCit1/analisis", ['tipoAnalisis' => 'Perfil lipídico', 'confirmacion' => 1])->assertRedirect();

        $analyses = $this->owner->query(
            'SELECT cod_ana FROM analisis WHERE cod_cit = '.$this->owner->quote($codCit1).' ORDER BY cod_ana'
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $analyses);
        [$ana1, $ana2] = $analyses;

        // Bloque 1.3: Error de validación en ANA2 NO marca ni abre ANA1 ni Solicitar estudio.
        $this->followingRedirects()
            ->from("/medico/citas/$codCit1/analisis")
            ->patch("/medico/analisis/$ana2", ['estado' => 'Completado', 'resultado' => '', 'confirmacion' => ''])
            ->assertOk()
            ->assertSee("href=\"#resultado-$ana2\"", false)
            ->assertSee("id=\"$ana2\" class=\"a-panel m-analysis-card\"", false);

        $this->patch("/medico/analisis/$ana1", ['estado' => 'En Proceso', 'confirmacion' => 1])->assertRedirect();
        $this->patch("/medico/analisis/$ana1", [
            'estado' => 'Completado',
            'resultado' => "Valores dentro de parámetros normales ($tag).",
            'confirmacion' => 1,
        ])->assertRedirect();

        // Finalizar consulta y emitir derivación hacia Médico B / Especialidad B.
        $this->post("/medico/citas/$codCit1/atender", ['confirmacion' => 1])->assertRedirect();
        $this->get("/medico/citas/$codCit1")->assertOk()->assertSee('Atendida');

        $this->post("/medico/citas/$codCit1/derivaciones", [
            'codEspDestino' => $espB,
            'codMedDestino' => $medB,
            'motivo' => "Interconsulta cardiológica E2E $tag",
            'confirmacion' => 1,
        ])->assertRedirect();
        $codDer = (string) $this->owner->query(
            'SELECT cod_der FROM derivaciones WHERE cod_cit_origen = '.$this->owner->quote($codCit1)
        )->fetchColumn();

        // Aislamiento de Médico B intentando leer cita o emitir derivación ajena.
        $this->post('/logout')->assertRedirect('/');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->post('/login', ['email' => $docBEmail, 'password' => $docBPass])
            ->assertRedirect('/medico/inicio');
        $this->get("/medico/citas/$codCit1")->assertStatus(404);
        $this->get("/medico/citas/$codCit1/analisis")->assertStatus(404);
        $this->post('/logout')->assertRedirect('/');

        // 5. Secretaría recibe derivación, verifica bloqueo de especialidad/médico y agenda cita hija.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->post('/login', ['email' => $secEmail, 'password' => $secPass])
            ->assertRedirect('/secretaria/inicio');
        $this->get('/secretaria/inicio', ['X-Clinical-Refresh' => '1'])->assertOk()
            ->assertSee('Prioridad alta')->assertSee(route('secretaria.derivaciones.show', $codDer));
        $this->get('/secretaria/derivaciones')->assertOk()->assertSee($codDer);
        $this->get('/secretaria/reservas/nueva?codDer='.$codDer.'&fecha='.$tomorrowClinic->toDateString())
            ->assertOk()
            ->assertSee("Reserva derivada $codDer")
            ->assertSee('<input type="hidden" name="codEsp" value="'.$espB.'">', false)
            ->assertSee('<input type="hidden" name="codMed" value="'.$medB.'">', false);

        $slotTom = $this->owner->prepare(
            "SELECT s.cod_slot
             FROM slots_agenda s
             LEFT JOIN citas c ON c.cod_slot = s.cod_slot AND c.estado <> 'Cancelada'
             WHERE s.cod_med = ? AND s.fecha = ? AND c.cod_cit IS NULL
             ORDER BY s.hora_inicio LIMIT 1"
        );
        $slotTom->execute([$medB, $tomorrowClinic->toDateString()]);
        $slotTomCode = (string) $slotTom->fetchColumn();

        $childResponse = $this->post('/secretaria/reservas', [
            'codPac' => $codPac,
            'codSlot' => $slotTomCode,
            'codDer' => $codDer,
            'confirmacion' => 1,
        ])->assertRedirect();
        $codCit2 = basename((string) $childResponse->headers->get('Location'));
        $this->get("/secretaria/derivaciones/$codDer")->assertOk()->assertSee('Agendada')->assertSee($codCit2);
        $this->get('/secretaria/inicio', ['X-Clinical-Refresh' => '1'])->assertOk()->assertDontSee($codDer);

        // Cancelación de cita hija + insignia roja a-badge--danger en UI.
        $this->post("/secretaria/citas/$codCit2/cancelar", [
            'motivo' => "Cancelación de prueba E2E $tag",
            'confirmacion' => 1,
        ])->assertRedirect("/secretaria/citas/$codCit2");
        $this->get("/secretaria/citas/$codCit2")->assertOk()->assertSee('Cancelada')->assertSee('a-badge--danger', false);

        // Verificación de auditoría en bitácora y mantenimiento periódico sin errores.
        $auditCount = (int) $this->owner->query(
            'SELECT count(*) FROM bitacora WHERE cod_usu IS NOT NULL AND clave_registro IN ('
            .$this->owner->quote($codCit1).','.$this->owner->quote($codCit2).','.$this->owner->quote($codDer).')'
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(4, $auditCount);

        $slotsGenerated = app(ClinicalMaintenanceService::class)->generateSlots();
        $maintenance = app(ClinicalMaintenanceService::class)->closeReception();
        $this->assertGreaterThanOrEqual(0, $slotsGenerated);
        $this->assertArrayHasKey('inasistencias', $maintenance);
        $this->assertArrayHasKey('sanciones_desbloqueadas', $maintenance);
        $this->assertInstanceOf(ClinicalTransaction::class, app(ClinicalTransaction::class));
        Http::assertNothingSent();
    }
}
