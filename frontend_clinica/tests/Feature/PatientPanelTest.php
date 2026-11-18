<?php

namespace Tests\Feature;

use App\Contracts\PatientAppointmentManager;
use App\Contracts\PatientAvailabilityProvider;
use App\Contracts\PatientProfileProvider;
use App\Exceptions\AuthDomainException;
use App\Models\Usuario;
use App\Services\Auth\AccountStatusService;
use App\Services\Notifications\AppointmentNotificationService;
use Illuminate\Database\QueryException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalVisualSnapshots;
use Tests\TestCase;

class PatientPanelTest extends TestCase
{
    private string $accountRole = 'ROL001';

    private ?int $accountStatus = null;

    private bool $databaseOffline = false;

    private MockInterface $appointments;

    private MockInterface $availability;

    private MockInterface $profiles;

    private MockInterface $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->notifications = $this->partialMock(AppointmentNotificationService::class);
        $this->notifications->shouldReceive('sendConfirmation')->byDefault()->andReturn('Pendiente');

        $status = Mockery::mock(AccountStatusService::class);
        $status->shouldReceive('requireActive')->andReturnUsing(function (string $userId): object {
            if ($this->databaseOffline) {
                throw new QueryException('clinical', 'SELECT 1', [], new \PDOException('SQLSTATE[08006] connection refused'));
            }
            if ($this->accountStatus === 401) {
                throw new AuthDomainException('ACCOUNT_NOT_FOUND', 401, 'La cuenta ya no está disponible.');
            }
            if ($this->accountStatus === 403) {
                throw new AuthDomainException('ACCOUNT_DISABLED', 403, 'La cuenta no está habilitada.');
            }

            return (object) [
                'cod_usu' => $userId,
                'nombre_completo' => 'Rosa Mendoza',
                'email' => 'rosa@example.test',
                'cod_rol' => $this->accountRole,
                'nombre_rol' => match ($this->accountRole) {
                    'ROL001' => 'Paciente',
                    'ROL002' => 'Secretaria',
                    'ROL003' => 'Médico',
                    'ROL004' => 'Administrador',
                    default => 'Desconocido',
                },
                'usuario_estado' => 'Activo',
                'rol_estado' => 'Activo',
                'perfil_presente' => true,
                'perfil_estado' => 'Activo',
                'email_verificado' => true,
                'bloqueado_hasta' => null,
            ];
        });
        $this->app->instance(AccountStatusService::class, $status);

        $this->profiles = $this->mock(PatientProfileProvider::class);
        $this->profiles->shouldReceive('profile')->byDefault()->andReturn($this->profileData());

        $this->availability = $this->mock(PatientAvailabilityProvider::class);
        $this->availability->shouldReceive('specialties')->byDefault()->andReturn([
            ['codEsp' => 'ESP001', 'nombreEspecialidad' => 'Cardiología', 'duracionConsultaMin' => 30],
        ]);
        $this->availability->shouldReceive('doctors')->byDefault()->andReturn([
            ['codMed' => 'MED001', 'nombreCompleto' => 'Dra. Ana Flores', 'duracionConsultaMin' => 30],
        ]);
        $this->availability->shouldReceive('slots')->byDefault()->andReturn([
            'fecha' => '2026-11-17',
            'codEsp' => 'ESP001',
            'codMed' => null,
            'habilitadoParaReservar' => true,
            'slots' => [[
                'codSlot' => 'SLOT0001',
                'codMed' => 'MED001',
                'nombreMedico' => 'Dra. Ana Flores',
                'codEsp' => 'ESP001',
                'nombreEspecialidad' => 'Cardiología',
                'fecha' => '2026-11-17',
                'horaInicio' => '09:00:00',
                'horaFin' => '09:30:00',
                'estadoDisponibilidad' => 'Disponible',
            ]],
        ]);

        $this->appointments = $this->mock(PatientAppointmentManager::class);
        $this->appointments->shouldReceive('list')->byDefault()->andReturnUsing(function (array $filters): array {
            $scope = $filters['alcance'] ?? 'todas';
            $state = $filters['estado'] ?? null;
            $appointments = match (true) {
                $state === 'Atendida' => [$this->appointment('CIT0002', 'Atendida', true)],
                $state === 'Cancelada' => [$this->appointment('CIT0003', 'Cancelada', true)],
                $scope === 'proximas' => [$this->appointment()],
                $scope === 'pasadas' => [
                    $this->appointment('CIT0002', 'Atendida', true),
                    $this->appointment('CIT0003', 'Cancelada', true),
                ],
                default => [$this->appointment()],
            };

            return [
                'citas' => $appointments,
                'alcance' => $scope,
                'page' => 0,
                'size' => $filters['size'] ?? 20,
                'hayMas' => false,
            ];
        });
        $this->appointments->shouldReceive('detail')->byDefault()->andReturnUsing(
            fn (string $code): array => $this->appointment($code) + [
                'puedeCancelar' => true,
                'puedeReprogramar' => true,
                'puedeVerPaseQr' => true,
            ],
        );
    }

    public function test_pending_mail_does_not_claim_delivery_after_booking(): void
    {
        $this->appointments->shouldReceive('reserve')->once()->with('SLOT0001')->andReturn($this->appointment('CIT0004'));
        $this->patient()->post(route('paciente.citas.store'), ['codSlot' => 'SLOT0001'])
            ->assertRedirect(route('paciente.citas.show', 'CIT0004'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'pendiente') && ! str_contains($message, 'enviamos'));
    }

    public function test_qr_email_cooldown_returns_to_the_detail_and_shows_the_remaining_wait(): void
    {
        $this->notifications->shouldReceive('retryConfirmation')->once()->with('CIT0001')->andReturnNull();
        $this->notifications->shouldReceive('sendConfirmation')->once()->with('CIT0001')->andReturn('Pendiente');
        $this->appointments->shouldReceive('detail')->with('CIT0001')->andReturn($this->appointment() + ['correoEstado' => 'Pendiente', 'correoIntentos' => 2]);
        $this->patient()->post(route('paciente.citas.correo-qr', 'CIT0001'))->assertRedirect(route('paciente.citas.show', 'CIT0001'));
        $this->post(route('paciente.citas.correo-qr', 'CIT0001'))->assertStatus(303)
            ->assertRedirect(route('paciente.citas.show', 'CIT0001'))->assertSessionHasErrors('correoQr')->assertHeader('Retry-After');
        $detail = $this->get(route('paciente.citas.show', 'CIT0001'))->assertOk()
            ->assertViewHas('qrRetryAfter', fn ($seconds) => $seconds > 0 && $seconds <= 120)
            ->assertSee('Podrás reintentar en')->assertSee('data-qr-resend-form', false)->assertDontSee('Too Many Requests');
        ClinicalVisualSnapshots::save('qr-mail-wait', $detail->getContent());
    }

    public function test_qr_email_limit_is_per_appointment_and_json_reports_the_cooldown(): void
    {
        $this->notifications->shouldReceive('retryConfirmation')->twice()->andReturnNull();
        $this->notifications->shouldReceive('sendConfirmation')->with('CIT0001')->once()->andReturn('Enviada');
        $this->notifications->shouldReceive('sendConfirmation')->with('CIT0002')->once()->andReturn('Enviada');
        $this->patient()->post(route('paciente.citas.correo-qr', 'CIT0001'))->assertRedirect();
        $this->postJson(route('paciente.citas.correo-qr', 'CIT0001'))->assertStatus(429)
            ->assertJsonPath('retryAfter', fn ($seconds) => $seconds > 0 && $seconds <= 120)->assertHeader('Retry-After');
        $this->post(route('paciente.citas.correo-qr', 'CIT0002'))->assertRedirect()->assertSessionHas('success');
    }

    public function test_qr_email_retry_is_allowed_after_the_cooldown_expires(): void
    {
        $this->notifications->shouldReceive('retryConfirmation')->twice()->with('CIT0001')->andReturnNull();
        $this->notifications->shouldReceive('sendConfirmation')->twice()->with('CIT0001')->andReturn('Enviada');
        $this->patient()->post(route('paciente.citas.correo-qr', 'CIT0001'))->assertRedirect();
        $this->travel(121)->seconds();
        $this->post(route('paciente.citas.correo-qr', 'CIT0001'))->assertRedirect()->assertSessionHas('success');
    }

    public function test_booking_sends_the_qr_after_the_reservation_is_confirmed(): void
    {
        $confirmed = false;
        $this->appointments->shouldReceive('reserve')->once()->with('SLOT0001')->andReturnUsing(function () use (&$confirmed) {
            $confirmed = true;

            return $this->appointment('CIT0004');
        });
        $this->notifications->shouldReceive('sendConfirmation')->once()->with('CIT0004')->andReturnUsing(function () use (&$confirmed) {
            $this->assertTrue($confirmed);

            return 'Enviada';
        });
        $this->patient()->post(route('paciente.citas.store'), ['codSlot' => 'SLOT0001'])
            ->assertRedirect(route('paciente.citas.show', 'CIT0004'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'aceptado'));
    }

    public function test_recoverable_mail_status_is_rendered_without_hiding_the_appointment(): void
    {
        $this->appointments->shouldReceive('detail')->with('CIT0001')->andReturn($this->appointment() + [
            'correoEstado' => 'Pendiente', 'correoIntentos' => 1, 'puedeVerPaseQr' => false,
        ]);
        $this->patient()->get(route('paciente.citas.show', 'CIT0001'))->assertOk()
            ->assertSee('Error recuperable')->assertSee('Reintentar correo')->assertSee('Progreso de cita');
        ClinicalVisualSnapshots::save('paciente', $this->get(route('paciente.citas.show', 'CIT0001'))->getContent());
    }

    public function test_guest_is_redirected_to_login_without_contacting_clinical_db(): void
    {
        $this->databaseOffline = true;

        $this->get(route('paciente.home'))->assertRedirect(route('login'));
    }

    public function test_notifications_require_the_patient_role_and_render_only_service_owned_notices(): void
    {
        $notifications = $this->mock(AppointmentNotificationService::class);
        $notifications->shouldReceive('patientNotices')->once()->andReturn([
            ['cod_not' => 1, 'cod_cit' => 'CIT0001', 'tipo_alerta' => 'Confirmacion', 'mensaje' => 'Aviso propio de prueba', 'creado_en' => '2026-11-17 10:00'],
        ]);
        $this->get(route('paciente.notificaciones'))->assertRedirect(route('login'));
        $this->accountRole = 'ROL003';
        $this->patient('ROL003')->get(route('paciente.notificaciones'))->assertForbidden();
        $this->accountRole = 'ROL001';
        $this->patient()->get(route('paciente.notificaciones'))->assertOk()->assertSee('Aviso propio de prueba')->assertSee('CIT0001');
    }

    public function test_patient_sees_original_navigation_and_laravel_backed_dashboard(): void
    {
        $this->patient()->get(route('paciente.home'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewIs('paciente.home')
            ->assertSee('Hola, Rosa')
            ->assertSee('Tu próxima cita')
            ->assertSee('Agendar cita')
            ->assertSee('Mis citas')
            ->assertSee('Mi perfil')
            ->assertSee('action="'.route('logout').'"', false)
            ->assertDontSee('administracion/')
            ->assertDontSee('secretaria/');
    }

    public function test_patient_can_search_availability_and_reserve_without_client_patient_identifier(): void
    {
        $date = now('America/La_Paz')->addDay()->format('Y-m-d');
        $this->appointments->shouldReceive('reserve')->once()->with('SLOT0001')
            ->andReturn($this->appointment('CIT0004'));

        $this->patient()->get(route('paciente.citas.nueva', [
            'codEsp' => 'ESP001',
            'codMed' => 'MED001',
            'fecha' => $date,
        ]))
            ->assertOk()
            ->assertViewIs('paciente.nueva-cita')
            ->assertSee('Cardiología')
            ->assertSee('Dra. Ana Flores')
            ->assertSee('SLOT0001', false);

        $this->patient()->post(route('paciente.citas.store'), [
            'codSlot' => 'SLOT0001',
            'codPac' => 'PACIENTE-AJENO',
        ])->assertRedirect(route('paciente.citas.show', 'CIT0004'));
    }

    public function test_patient_can_list_and_open_own_appointment_detail(): void
    {
        $this->patient()->get(route('paciente.citas'))
            ->assertOk()
            ->assertViewIs('paciente.citas')
            ->assertSee('Mis citas')
            ->assertSee('Cardiología')
            ->assertSee(route('paciente.citas.show', 'CIT0001'), false);

        $this->patient()->get(route('paciente.citas.show', 'CIT0001'))
            ->assertOk()
            ->assertViewIs('paciente.detalle')
            ->assertSee('Detalle de cita')
            ->assertSee('CIT0001');
    }

    public function test_cancel_requires_reason_and_confirmation(): void
    {
        $this->appointments->shouldReceive('cancel')->never();

        $this->patient()->from(route('paciente.citas.show', 'CIT0001'))
            ->post(route('paciente.citas.cancel', 'CIT0001'), [])
            ->assertRedirect(route('paciente.citas.show', 'CIT0001'))
            ->assertInvalid([
                'motivo' => 'El campo motivo es obligatorio.',
                'confirmacion' => 'Debes confirmar la operación.',
            ]);
    }

    public function test_patient_can_cancel_an_owned_mutable_appointment(): void
    {
        $this->appointments->shouldReceive('cancel')->once()
            ->with('CIT0001', 'Viaje impostergable')
            ->andReturn($this->appointment('CIT0001', 'Cancelada', true));

        $this->patient()->post(route('paciente.citas.cancel', 'CIT0001'), [
            'motivo' => 'Viaje impostergable',
            'confirmacion' => '1',
            'codPac' => 'PACIENTE-AJENO',
        ])->assertRedirect(route('paciente.citas', ['tab' => 'canceladas']));
    }

    public function test_patient_can_search_and_confirm_a_reschedule(): void
    {
        $date = now('America/La_Paz')->addDay()->format('Y-m-d');
        $this->appointments->shouldReceive('reschedule')->once()
            ->with('CIT0001', 'SLOT0001', 'Cambio de horario laboral')
            ->andReturn($this->appointment('CIT0005'));

        $this->patient()->get(route('paciente.citas.reprogramar', [
            'cita' => 'CIT0001',
            'codMed' => 'MED001',
            'fecha' => $date,
        ]))
            ->assertOk()
            ->assertViewIs('paciente.reprogramar')
            ->assertSee('Reprogramar cita')
            ->assertSee('SLOT0001', false);

        $this->patient()->post(route('paciente.citas.reschedule', 'CIT0001'), [
            'codSlotNuevo' => 'SLOT0001',
            'motivo' => 'Cambio de horario laboral',
            'confirmacion' => 'yes',
            'codPac' => 'PACIENTE-AJENO',
        ])->assertRedirect(route('paciente.citas.show', 'CIT0005'));
    }

    public function test_patient_can_consult_profile_without_editable_patient_identifier(): void
    {
        $this->patient()->get(route('paciente.perfil'))
            ->assertOk()
            ->assertViewIs('paciente.perfil')
            ->assertSee('Rosa Mendoza')
            ->assertSee('rosa@example.test')
            ->assertDontSee('name="codPac"', false)
            ->assertDontSee('paciente.perfil.update');
    }

    public static function otherRoles(): array
    {
        return [
            'secretaria' => ['ROL002'],
            'medico' => ['ROL003'],
            'administrador' => ['ROL004'],
        ];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_open_the_patient_panel(string $role): void
    {
        $this->accountRole = $role;

        $this->patient($role)->get(route('paciente.home'))->assertForbidden();
    }

    public function test_session_patient_role_cannot_override_a_different_validated_account_role(): void
    {
        $this->accountRole = 'ROL002';

        $this->patient('ROL001')->get(route('paciente.home'))->assertForbidden();
    }

    public function test_revoked_patient_session_is_removed(): void
    {
        $this->accountStatus = 401;

        $this->patient()->get(route('paciente.home'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('cod_usu')
            ->assertSessionMissing('cod_rol');
    }

    public function test_unavailable_authorizer_returns_private_503_error_page(): void
    {
        $this->databaseOffline = true;

        $this->patient()->get(route('paciente.home'))
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '30')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('No fue posible validar la sesión')
            ->assertDontSee('Tu próxima cita');
    }

    private function patient(string $role = 'ROL001'): static
    {
        $user = new Usuario;
        $user->forceFill([
            'cod_usu' => 'USU000001',
            'cod_rol' => $role,
            'nombre_completo' => 'Rosa Mendoza',
            'email' => 'rosa@example.test',
            'estado' => 'Activo',
        ]);

        return $this->actingAs($user, 'web')->withSession([
            'auth_authority' => 'laravel',
            'auth_expires_at' => now()->addMinutes(120)->toIso8601String(),
            'cod_usu' => 'USU000001',
            'cod_rol' => $role,
            'nombre_usuario' => 'Rosa Mendoza',
            'email_usuario' => 'rosa@example.test',
            'nombre_rol' => 'Paciente',
            'rol_slug' => 'paciente',
            'estado_cuenta' => 'Activo',
        ]);
    }

    /** @return array<string, mixed> */
    private function profileData(): array
    {
        return [
            'codPac' => 'PAC000001',
            'codUsu' => 'USU000001',
            'nombreCompleto' => 'Rosa Mendoza',
            'email' => 'rosa@example.test',
            'ci' => '1234567',
            'fechaNacimiento' => '1990-05-10',
            'telefono' => '+591 70000000',
            'ciudadResidencia' => 'La Paz',
            'estadoCuenta' => 'Activo',
            'bloqueadoHasta' => null,
            'emailVerificado' => true,
            'estadoReserva' => 'Activo',
            'habilitadoParaReservar' => true,
            'sancionVigente' => null,
            'resumen' => [
                'citasProximas' => 1,
                'derivacionesPendientes' => 0,
                'incidenciasRegistradas' => 0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function appointment(
        string $code = 'CIT0001',
        string $state = 'Confirmada',
        bool $expired = false,
    ): array {
        return [
            'codCit' => $code,
            'codSlot' => 'SLOT0001',
            'codPac' => 'PAC000001',
            'codMed' => 'MED001',
            'nombreMedico' => 'Dra. Ana Flores',
            'codEsp' => 'ESP001',
            'nombreEspecialidad' => 'Cardiología',
            'fecha' => $expired ? '2026-01-10' : '2026-11-20',
            'horaInicio' => '09:00:00',
            'horaFin' => '09:30:00',
            'estado' => $state,
            'canalReserva' => 'Web',
            'motivoCancelacion' => null,
            'motivoReprogramacion' => null,
            'canceladoEn' => null,
            'horaLlegadaCheckin' => null,
            'fechaAtencion' => null,
            'codCitPadre' => null,
            'creadoEn' => '2026-11-16 09:00:00',
            'qrDisponible' => $state === 'Confirmada',
            'esVencida' => $expired,
        ];
    }
}
