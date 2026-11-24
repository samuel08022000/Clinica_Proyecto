<?php

namespace Tests\Feature;

use App\Exceptions\AuthDomainException;
use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Administration\AdministrationService;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\AuthMailService;
use App\Services\Auth\LoginAttemptService;
use App\Services\Clinical\ClinicalTransaction;
use App\Services\Medico\MedicoAnalisisService;
use App\Services\Medico\MedicoAtencionService;
use App\Services\Medico\MedicoDerivacionService;
use App\Services\Notifications\AppointmentNotificationService;
use App\Services\Paciente\PatientAppointmentService;
use App\Services\Reception\ReceptionService;
use App\Support\ClinicalLog;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\Support\LoggingConnection;
use Tests\Support\LoggingDatabaseManager;
use Tests\Support\RecordingClinicalLogger;
use Tests\TestCase;

require_once __DIR__.'/../Support/LoggingFixtures.php';

final class ClinicalLoggingTest extends TestCase
{
    private LoggingConnection $db;

    private RecordingClinicalLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->db = new LoggingConnection;
        $this->db->passwordHash = password_hash('NeverLogThis1!', PASSWORD_BCRYPT, ['cost' => 4]);
        $manager = new LoggingDatabaseManager($this->db);
        $this->app->instance('db', $manager);
        Usuario::setConnectionResolver($manager);
        $this->logger = new RecordingClinicalLogger;
        Log::swap($this->logger);
        config(['mail.default' => 'array']);
    }

    public static function mutations(): array
    {
        $personal = ['nombreCompleto' => 'PERSONAL_PRIVATE_SENTINEL', 'email' => 'private-sentinel@example.invalid',
            'password' => 'NeverLogThis1!', 'ci' => 'PRIVATE_DOCUMENT', 'telefono' => 'PRIVATE_PHONE',
            'matriculaProfesional' => 'PRIVATE_REGISTRATION', 'resumenCurricular' => 'MEDICAL_PRIVATE_SENTINEL',
            'duracionConsultaMin' => 30, 'codigosEspecialidades' => ['ESP001']];
        $schedule = ['diaSemana' => 1, 'horaInicio' => '08:00', 'horaFin' => '12:00'];
        $reason = ['motivo' => 'MEDICAL_PRIVATE_SENTINEL', 'dias' => 2, 'fechaBloqueo' => '2026-11-20'];

        return [
            'doctor create' => ['admin', 'POST', 'personal/medicos', $personal, 'admin.doctor.created'],
            'doctor update' => ['admin', 'PUT', 'personal/medicos/MED000001', $personal, 'admin.doctor.updated'],
            'doctor state' => ['admin', 'PUT', 'personal/medicos/MED000001/estado', ['estado' => 'Inactivo'], 'admin.staff.status_changed'],
            'secretary create' => ['admin', 'POST', 'personal/secretarias', $personal, 'admin.secretary.created'],
            'secretary update' => ['admin', 'PUT', 'personal/secretarias/SEC000001', $personal, 'admin.secretary.updated'],
            'secretary state' => ['admin', 'PUT', 'personal/secretarias/SEC000001/estado', ['estado' => 'Inactivo'], 'admin.staff.status_changed'],
            'secretary password' => ['admin', 'PUT', 'personal/secretarias/SEC000001/password', $personal, 'admin.secretary.password_changed'],
            'specialty create' => ['admin', 'POST', 'personal/especialidades', ['nombreEspecialidad' => 'PRIVATE_SPECIALTY', 'descripcion' => 'MEDICAL_PRIVATE_SENTINEL', 'duracionConsultaMin' => 30], 'admin.specialty.changed'],
            'specialty update' => ['admin', 'PUT', 'personal/especialidades/ESP001', ['nombreEspecialidad' => 'PRIVATE_SPECIALTY', 'descripcion' => 'MEDICAL_PRIVATE_SENTINEL'], 'admin.specialty.changed'],
            'specialty state' => ['admin', 'PUT', 'personal/especialidades/ESP001/estado', ['estado' => 'Activo'], 'admin.specialty.changed'],
            'schedule create' => ['admin', 'POST', 'administracion/medicos/MED000001/horarios', $schedule, 'admin.schedule.changed'],
            'schedule update' => ['admin', 'PUT', 'administracion/horarios/HOR000001', $schedule, 'admin.schedule.changed'],
            'schedule state' => ['admin', 'PUT', 'administracion/horarios/HOR000001/estado', ['estado' => 'Activo'], 'admin.schedule.changed'],
            'duration update' => ['admin', 'PUT', 'administracion/medicos/MED000001/duracion', ['duracionConsultaMin' => 30], 'admin.schedule.changed'],
            'block create' => ['admin', 'POST', 'administracion/medicos/MED000001/bloqueos', $reason, 'admin.block.created'],
            'sanction create' => ['admin', 'POST', 'administracion/pacientes/PAC000001/sanciones', $reason, 'admin.sanction.changed'],
            'reception reserve' => ['reception', 'POST', 'citas', ['codPac' => 'PAC000001', 'codSlot' => 'SLO000001'], 'reception.appointment.reserved'],
            'reception checkin' => ['reception', 'POST', 'checkin', ['tokenQr' => 'QR_PRIVATE_SENTINEL', 'codCit' => 'CIT00000002'], 'reception.appointment.checked_in'],
            'reception cancel' => ['reception', 'POST', 'citas/CIT00000002/cancelar', $reason, 'reception.appointment.cancelled'],
            'reception reschedule' => ['reception', 'POST', 'citas/CIT00000002/reprogramar', $reason + ['codSlot' => 'SLO000001'], 'reception.appointment.rescheduled'],
            'reception absence' => ['reception', 'POST', 'citas/CIT00000002/inasistencia', [], 'reception.appointment.absence_registered'],
            'patient reserve' => ['patient', 'reserve', '', ['SLO000001'], 'patient.appointment.reserved'],
            'patient cancel' => ['patient', 'cancel', '', ['CIT00000002', 'MEDICAL_PRIVATE_SENTINEL'], 'patient.appointment.cancelled'],
            'patient reschedule' => ['patient', 'reschedule', '', ['CIT00000002', 'SLO000001', 'MEDICAL_PRIVATE_SENTINEL'], 'patient.appointment.rescheduled'],
            'doctor finish' => ['doctor', 'finish', '', ['CIT00000002'], 'doctor.appointment.finished'],
            'doctor referral' => ['referral', 'create', '', ['CIT00000002', ['codEspDestino' => 'ESP001', 'motivo' => 'MEDICAL_PRIVATE_SENTINEL']], 'doctor.referral.processed'],
            'doctor analysis request' => ['analysis', 'request', '', ['CIT00000002', ['tipoAnalisis' => 'MEDICAL_PRIVATE_SENTINEL']], 'doctor.analysis.request_processed'],
            'doctor analysis update' => ['analysis', 'update', '', ['ANA00000001', ['estado' => 'Completado', 'resultado' => 'MEDICAL_PRIVATE_SENTINEL']], 'doctor.analysis.status_changed'],
        ];
    }

    #[DataProvider('mutations')]
    public function test_real_service_emits_one_safe_info_only_after_commit(string $module, string $method, string $path, array $data, string $event): void
    {
        $this->logger->onWrite = function ($level): void {
            if ($level === 'info') {
                $this->assertFalse($this->db->active);
                $this->assertSame(1, $this->db->commits);
            }
        };
        $this->invokeMutation($module, $method, $path, $data);
        $this->assertSame([$event], array_column($this->logger->records, 'event'));
        $this->assertSame('info', $this->logger->records[0]['level']);
        $this->assertSame($this->db->actor(), $this->logger->records[0]['context']['actor_id']);
        $this->assertPrivateValuesAbsent();
    }

    #[DataProvider('mutations')]
    public function test_commit_failure_never_emits_success_or_duplicate_errors(string $module, string $method, string $path, array $data, string $event): void
    {
        $this->db->commitFailure = new RuntimeException('SQL password NeverLogThis1! MEDICAL_PRIVATE_SENTINEL');
        try {
            $this->invokeMutation($module, $method, $path, $data);
            $this->fail('Commit failure was ignored');
        } catch (RuntimeException $error) {
            $this->assertSame($this->db->commitFailure, $error);
            app(ExceptionHandler::class)->report($error);
        }
        $this->assertSame(0, $this->db->commits);
        $this->assertSame([$event.'.failed'], array_column($this->logger->records, 'event'));
        $this->assertSame('error', $this->logger->records[0]['level']);
        $this->assertPrivateValuesAbsent();
    }

    public function test_operational_rejection_is_warning_and_sql_failure_is_sanitized(): void
    {
        $pdo = new PDOException('NeverLogThis1! MEDICAL_PRIVATE_SENTINEL');
        $pdo->errorInfo = ['23505', 0, 'MEDICAL_PRIVATE_SENTINEL'];
        $this->db->operationFailure = new QueryException('clinical', 'INSERT INTO private VALUES (?)', ['NeverLogThis1!'], $pdo);
        try {
            $this->invokeMutation('reception', 'POST', 'citas', ['codPac' => 'PAC000001', 'codSlot' => 'SLO000001']);
            $this->fail('Expected conflict');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(409, $error->status);
            $this->assertNull($error->getPrevious());
        }
        $record = $this->logger->records[0];
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $record['level']);
        $this->assertSame('23505', $record['context']['sqlstate']);
        $this->assertSame('reception.appointment.reserved.rejected', $record['event']);
        $this->assertPrivateValuesAbsent();
    }

    public function test_preview_has_no_success_and_rejection_has_only_one_warning(): void
    {
        $this->actor('ROL002');
        $service = app(ReceptionService::class);
        $service->request('POST', 'checkin/validar', ['tokenQr' => 'QR_PRIVATE_SENTINEL']);
        $this->assertSame([], $this->logger->records);
        $this->db->state = 'En Espera';
        try {
            $service->request('POST', 'checkin/validar', ['tokenQr' => 'QR_PRIVATE_SENTINEL']);
            $this->fail('Expected duplicate checkin rejection');
        } catch (ClinicalOperationException $error) {
            $this->assertSame('CHECKIN_DUPLICADO', $error->errorCode);
        }
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertPrivateValuesAbsent();
    }

    public function test_reused_referral_is_processed_instead_of_falsely_claiming_creation(): void
    {
        $this->db->reuse = true;
        $this->invokeMutation('referral', 'create', '', ['CIT00000002',
            ['codEspDestino' => 'ESP001', 'motivo' => 'MEDICAL_PRIVATE_SENTINEL']]);
        $this->assertSame('doctor.referral.processed', $this->logger->records[0]['event']);
        $this->assertSame('DER00000001', $this->logger->records[0]['context']['entity_id']);
    }

    public function test_login_success_then_logout_are_logged_once_without_credentials_or_session(): void
    {
        $this->post('/login', ['email' => 'private-sentinel@example.invalid', 'password' => 'NeverLogThis1!'])
            ->assertRedirect(route('administracion.home'))->assertSessionHasNoErrors();
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertSame(['auth.login.success', 'auth.logout.success'], array_column($this->logger->records, 'event'));
        $this->assertSame(['info', 'info'], array_column($this->logger->records, 'level'));
        $this->assertPrivateValuesAbsent();
    }

    public function test_invalid_password_only_emits_failed_warning(): void
    {
        $this->post('/login', ['email' => 'private-sentinel@example.invalid', 'password' => 'IncorrectSecret9!'])
            ->assertSessionHasErrors('authentication');
        $this->assertGuest();
        $this->assertSame(['auth.login.failed'], array_column($this->logger->records, 'event'));
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertPrivateValuesAbsent();
        $this->assertStringNotContainsString('IncorrectSecret9!', json_encode($this->logger->records));
    }

    public function test_rate_limit_emits_blocked_without_also_emitting_failed(): void
    {
        $attempts = app(LoginAttemptService::class);
        for ($i = 0; $i < 5; $i++) {
            $attempts->failure('private-sentinel@example.invalid', '198.51.100.77');
        }
        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.77']);
        try {
            app(AuthenticationService::class)->login($request, 'private-sentinel@example.invalid', 'NeverLogThis1!');
            $this->fail('Expected rate limit');
        } catch (AuthDomainException $error) {
            $this->assertSame(429, $error->status);
        }
        $this->assertSame(['auth.login.blocked'], array_column($this->logger->records, 'event'));
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertPrivateValuesAbsent();
        $this->assertStringNotContainsString('198.51.100.77', json_encode($this->logger->records));
    }

    public function test_verified_login_uses_the_same_success_event_without_double_logging(): void
    {
        $request = Request::create('/auth/google/callback');
        $request->setLaravelSession(app('session.store'));
        app(AuthenticationService::class)->trustedLogin($request, $this->db->actor());
        $this->assertSame(['auth.login.success'], array_column($this->logger->records, 'event'));
        $this->assertSame('verified_identity', $this->logger->records[0]['context']['action']);
        $this->assertPrivateValuesAbsent();
    }

    public function test_session_failure_does_not_emit_a_login_success(): void
    {
        try {
            app(AuthenticationService::class)->trustedLogin(Request::create('/auth/google/callback'), $this->db->actor());
            $this->fail('Expected missing session failure');
        } catch (RuntimeException) {
            $this->assertSame([], $this->logger->records);
        }
    }

    public function test_global_reporter_suppresses_raw_exception_and_preserves_shouldntreport(): void
    {
        $error = new QueryException('clinical', 'SELECT PRIVATE_SQL WHERE token=?', ['QR_PRIVATE_SENTINEL'],
            new PDOException('NeverLogThis1! MEDICAL_PRIVATE_SENTINEL'));
        $handler = app(ExceptionHandler::class);
        $handler->report($error);
        $handler->report($error);
        $handler->report(new ClinicalOperationException(500, 'MEDICAL_PRIVATE_SENTINEL'));
        $this->assertSame(['app.exception.failed'], array_column($this->logger->records, 'event'));
        $this->assertSame('error', $this->logger->records[0]['level']);
        $this->assertPrivateValuesAbsent();
        $this->assertStringNotContainsString('PRIVATE_SQL', json_encode($this->logger->records));
        $this->assertArrayNotHasKey('exception', $this->logger->records[0]['context']);
    }

    public function test_context_allowlist_rejects_nested_or_sensitive_inputs(): void
    {
        ClinicalLog::event('patient.appointment.reserved', [
            'entity_id' => 'CIT00000002', 'actor_id' => 'private-sentinel@example.invalid',
            'password' => 'NeverLogThis1!', 'token' => 'QR_PRIVATE_SENTINEL', 'code' => '012345',
            'request' => ['Authorization' => 'Bearer SECRET'], 'resultado' => 'MEDICAL_PRIVATE_SENTINEL',
            'exception_type' => 'private-sentinel@example.invalid', 'action' => 'MEDICAL_PRIVATE_SENTINEL',
        ]);
        $this->assertSame(['entity_id' => 'CIT00000002'], $this->logger->records[0]['context']);
        $this->assertPrivateValuesAbsent();
    }

    public function test_unavailable_logging_destination_cannot_change_a_committed_result(): void
    {
        $this->actor('ROL004');
        $this->logger->onWrite = static fn () => throw new RuntimeException('Log destination unavailable');
        $result = app(ClinicalTransaction::class)->run(static fn () => 'ESP001', 'admin.specialty.changed');
        $this->assertSame('ESP001', $result);
        $this->assertSame(1, $this->db->commits);
    }

    public function test_success_in_nested_transaction_is_deferred_until_outer_commit(): void
    {
        $connection = new SQLiteConnection(new \PDO('sqlite::memory:'));
        $connection->setTransactionManager(new DatabaseTransactionsManager);
        $connection->transaction(function () use ($connection): void {
            $connection->transaction(function () use ($connection): void {
                ClinicalLog::afterCommit($connection, 'notification.mail.transport_accepted', ['entity_id' => 'CIT00000002']);
                $this->assertSame([], $this->logger->records);
            });
            $this->assertSame([], $this->logger->records);
        });
        $this->assertSame(['notification.mail.transport_accepted'], array_column($this->logger->records, 'event'));
    }

    public function test_outer_rollback_discards_deferred_success(): void
    {
        $connection = new SQLiteConnection(new \PDO('sqlite::memory:'));
        $connection->setTransactionManager(new DatabaseTransactionsManager);
        try {
            $connection->transaction(function () use ($connection): void {
                ClinicalLog::afterCommit($connection, 'patient.notification.retry_requested', ['entity_id' => 'CIT00000002']);
                throw new RuntimeException('Synthetic rollback');
            });
        } catch (RuntimeException) {
            $this->assertSame([], $this->logger->records);
        }
    }

    public function test_password_recovery_transport_failure_is_safe_and_still_propagates(): void
    {
        $this->mailTransport(true);
        try {
            app(AuthMailService::class)->sendPasswordReset('private-sentinel@example.invalid', 'https://example.invalid/QR_PRIVATE_SENTINEL');
            $this->fail('Expected mail failure');
        } catch (TransportException) {
            $this->assertSame(['auth.password_reset.mail_failed'], array_column($this->logger->records, 'event'));
            $this->assertSame('warning', $this->logger->records[0]['level']);
            $this->assertPrivateValuesAbsent();
        }
    }

    public function test_notification_acceptance_means_transport_acceptance_after_commit(): void
    {
        $this->mailTransport(false);
        $result = app(AppointmentNotificationService::class)->process(1);
        $this->assertSame(1, $result['enviadas']);
        $this->assertSame(1, $this->db->commits);
        $this->assertSame(['notification.mail.transport_accepted'], array_column($this->logger->records, 'event'));
        $this->assertSame('info', $this->logger->records[0]['level']);
        $this->assertSame('array', $this->logger->records[0]['context']['transport']);
        $this->assertPrivateValuesAbsent();
    }

    public function test_notification_failure_has_warning_without_acceptance_or_success(): void
    {
        $this->mailTransport(true);
        $result = app(AppointmentNotificationService::class)->process(1);
        $this->assertSame(1, $result['errores']);
        $this->assertSame(0, $this->db->commits);
        $this->assertSame(['notification.mail.failed'], array_column($this->logger->records, 'event'));
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertPrivateValuesAbsent();
    }

    public function test_retry_is_only_logged_after_a_real_update_and_not_after_already_sent(): void
    {
        $this->actor('ROL001');
        app(AppointmentNotificationService::class)->retryConfirmation('CIT00000002');
        $this->assertSame(['patient.notification.retry_requested'], array_column($this->logger->records, 'event'));
        $this->logger->records = [];
        $this->db->changed = 0;
        try {
            app(AppointmentNotificationService::class)->retryConfirmation('CIT00000002');
            $this->fail('Expected retry conflict');
        } catch (ClinicalOperationException) {
            $this->assertSame(['patient.notification.retry_rejected'], array_column($this->logger->records, 'event'));
            $this->assertSame('warning', $this->logger->records[0]['level']);
        }
        $this->logger->records = [];
        $this->db->noticeState = 'Enviada';
        app(AppointmentNotificationService::class)->retryConfirmation('CIT00000002');
        $this->assertSame([], $this->logger->records);
        $this->assertPrivateValuesAbsent();
    }

    private function actor(string $role): void
    {
        $this->db->role = $role;
        $user = new Usuario;
        $user->cod_usu = $this->db->actor();
        $user->cod_rol = $role;
        $user->estado = 'Activo';
        $this->actingAs($user);
    }

    private function invokeMutation(string $module, string $method, string $path, array $data): mixed
    {
        $this->actor(match ($module) {
            'admin' => 'ROL004', 'reception' => 'ROL002', 'patient' => 'ROL001', default => 'ROL003',
        });
        $this->db->state = match ($module) {
            'doctor', 'analysis' => 'En Espera', 'referral' => 'Atendida', default => 'Confirmada',
        };
        $service = app(match ($module) {
            'admin' => AdministrationService::class, 'reception' => ReceptionService::class,
            'patient' => PatientAppointmentService::class, 'doctor' => MedicoAtencionService::class,
            'referral' => MedicoDerivacionService::class, 'analysis' => MedicoAnalisisService::class,
        });

        return in_array($module, ['admin', 'reception'], true)
            ? $service->request($method, $path, $data)
            : $service->{$method}(...$data);
    }

    private function assertPrivateValuesAbsent(): void
    {
        $serialized = json_encode($this->logger->records, JSON_THROW_ON_ERROR);
        foreach (['NeverLogThis1!', 'private-sentinel@example.invalid', 'PERSONAL_PRIVATE_SENTINEL',
            'MEDICAL_PRIVATE_SENTINEL', 'QR_PRIVATE_SENTINEL', 'PRIVATE_DOCUMENT', 'PRIVATE_PHONE',
            'PRIVATE_REGISTRATION', 'PRIVATE_SPECIALTY', '$2y$', '012345'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    private function mailTransport(bool $fail): void
    {
        Mail::swap(new class($fail)
        {
            public function __construct(private bool $fail) {}

            public function to(string $recipient): self
            {
                return $this;
            }

            public function send(mixed $mail): SentMessage
            {
                if ($this->fail) {
                    throw new TransportException('NeverLogThis1! MEDICAL_PRIVATE_SENTINEL QR_PRIVATE_SENTINEL');
                }
                $email = (new Email)->from('fixture@example.invalid')->to('recipient@example.invalid')->text('Synthetic fixture');

                return new SentMessage(new \Symfony\Component\Mailer\SentMessage($email, Envelope::create($email)));
            }
        });
    }
}
