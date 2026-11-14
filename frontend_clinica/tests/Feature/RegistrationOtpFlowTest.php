<?php

namespace Tests\Feature;

use GuzzleHttp\Exception\NetworkTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RegistrationOtpFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_registration_validates_required_fields_before_calling_quarkus(): void
    {
        Http::fake();

        $this->post(route('register.store'), [])
            ->assertSessionHasErrors([
                'ci',
                'nombre_completo',
                'fecha_nacimiento',
                'telefono',
                'email',
                'ciudad_residencia',
                'password',
            ]);

        Http::assertNothingSent();
    }

    public function test_successful_registration_stores_only_the_opaque_challenge(): void
    {
        Http::fake([
            '*/auth/register' => Http::response(['data' => [
                'patientId' => 'PAC000100',
                'roleCode' => 'ROL001',
                'emailVerificationRequired' => true,
                'verification' => $this->challengeData(),
            ]], 201),
        ]);

        $this->post(route('register.store'), $this->registrationData())
            ->assertRedirect(route('verification.email'))
            ->assertSessionHas('registration_verification_token', 'opaque-verification-token')
            ->assertSessionHas('registration_masked_destination', 'n***@clinica.bo')
            ->assertSessionHas('registration_delivery_mode', 'DEMO')
            ->assertSessionHas('registration_delivery_confirmed', false)
            ->assertSessionMissing('password');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://quarkus.test/api/v1/auth/register'
            && ! $request->hasHeader('Authorization')
            && $request['email'] === 'nuevo@clinica.bo'
            && $request['password'] === 'PacienteSegura123');
    }

    public function test_duplicate_email_is_presented_without_losing_non_sensitive_input(): void
    {
        Http::fake([
            '*/auth/register' => Http::response([
                'error' => ['code' => 'EMAIL_ALREADY_REGISTERED', 'message' => 'Backend'],
            ], 409),
        ]);

        $this->from(route('register'))->post(route('register.store'), $this->registrationData())
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('registration')
            ->assertSessionHasInput('email', 'nuevo@clinica.bo')
            ->assertSessionMissing('password');
    }

    public function test_duplicate_document_and_provider_failure_are_reported_safely(): void
    {
        foreach ([
            ['DOCUMENT_ALREADY_REGISTERED', 409],
            ['OTP_PROVIDER_UNAVAILABLE', 503],
        ] as [$code, $status]) {
            Http::fake([
                '*/auth/register' => Http::response([
                    'error' => ['code' => $code, 'message' => 'Detalle interno'],
                ], $status),
            ]);

            $this->from(route('register'))->post(route('register.store'), $this->registrationData())
                ->assertRedirect(route('register'))
                ->assertSessionHasErrors('registration')
                ->assertSessionMissing('registration_verification_token');
        }
    }

    public function test_invalid_password_is_rejected_before_calling_quarkus(): void
    {
        Http::fake();
        $data = $this->registrationData();
        $data['password'] = 'debil';
        $data['password_confirmation'] = 'debil';

        $this->post(route('register.store'), $data)->assertSessionHasErrors('password');

        Http::assertNothingSent();
    }

    public function test_registration_waits_for_smtp_without_extending_the_connection_timeout(): void
    {
        config()->set('services.quarkus.timeout', 8);
        config()->set('services.quarkus.registration_timeout', 30);
        config()->set('services.quarkus.connect_timeout', 3);
        Http::fake(function (Request $request, array $options) {
            $this->assertEquals(30, $options['timeout']);
            $this->assertEquals(3, $options['connect_timeout']);
            $verification = $this->challengeData();
            $verification['deliveryMode'] = 'SMTP';
            $verification['deliveryConfirmed'] = true;

            return Http::response(['data' => ['verification' => $verification]], 201);
        });

        $this->post(route('register.store'), $this->registrationData())
            ->assertRedirect(route('verification.email'))
            ->assertSessionHas('registration_delivery_mode', 'SMTP')
            ->assertSessionHas('registration_delivery_confirmed', true);
        Http::assertSentCount(1);
    }

    public function test_registration_timeout_is_distinguished_and_logs_only_safe_metadata(): void
    {
        $this->withoutExceptionHandling();
        Log::spy();
        $cause = new NetworkTimeoutException('Internal transport detail',
            new \GuzzleHttp\Psr7\Request('POST', 'http://quarkus.test/api/v1/auth/register'));
        $calls = 0;
        Http::fake(function () use ($cause, &$calls) {
            $calls++;
            throw new ConnectionException('Internal transport detail', 0, $cause);
        });

        $this->from(route('register'))->post(route('register.store'), $this->registrationData())
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['registration' => 'El servicio de registro tardó demasiado en responder. Comprueba tu correo antes de volver a intentarlo.'])
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');
        $this->assertSame(1, $calls);
        Log::shouldHaveReceived('warning')->once()->with('Registration transport failure',
            \Mockery::on(fn (array $context): bool => array_keys($context) === ['transport_errno', 'elapsed_ms']
                && $context['transport_errno'] === 28 && $context['elapsed_ms'] >= 0));
    }

    public function test_smtp_http_failures_are_not_presented_as_connection_failures(): void
    {
        foreach (['OTP_DELIVERY_FAILED', 'OTP_SMTP_NOT_CONFIGURED'] as $code) {
            Http::fake(['*/auth/register' => Http::response([
                'error' => ['code' => $code, 'message' => 'Private provider detail'],
            ], 503)]);
            $this->from(route('register'))->post(route('register.store'), $this->registrationData())
                ->assertRedirect(route('register'))
                ->assertSessionHasErrors(['registration' => 'La cuenta no pudo crearse porque la entrega OTP no está disponible. Intenta nuevamente más tarde.']);
        }
    }

    public function test_registration_connection_failure_is_recoverable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Quarkus no disponible'));

        $this->from(route('register'))->post(route('register.store'), $this->registrationData())
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('registration')
            ->assertSessionHasInput('ci', 'CI-9001');
    }

    public function test_email_verification_success_joins_the_six_digits_and_redirects_to_login(): void
    {
        Http::fake([
            '*/auth/otp/verify' => Http::response([
                'data' => ['verified' => true, 'nextAction' => 'LOGIN'],
            ], 200),
        ]);

        $this->withSession($this->verificationSession())
            ->post(route('verification.email.verify'), [
                'code_digits' => ['1', '2', '3', '4', '5', '6'],
            ])
            ->assertRedirect(route('login'))
            ->assertSessionMissing('registration_verification_token')
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://quarkus.test/api/v1/auth/otp/verify'
            && $request['verificationToken'] === 'opaque-verification-token'
            && $request['code'] === '123456');
    }

    public function test_incorrect_otp_keeps_challenge_for_another_allowed_attempt(): void
    {
        Http::fake([
            '*/auth/otp/verify' => Http::response([
                'error' => ['code' => 'OTP_INVALID_OR_EXPIRED', 'message' => 'Backend'],
            ], 422),
        ]);

        $this->withSession($this->verificationSession())
            ->from(route('verification.email'))
            ->post(route('verification.email.verify'), ['code' => '000000'])
            ->assertRedirect(route('verification.email'))
            ->assertSessionHasErrors('verification')
            ->assertSessionHas('registration_verification_token', 'opaque-verification-token');
    }

    public function test_otp_connection_failure_keeps_challenge_and_allows_retry(): void
    {
        Http::fake(fn () => throw new ConnectionException('Quarkus no disponible'));

        $this->withSession($this->verificationSession())
            ->post(route('verification.email.verify'), ['code' => '123456'])
            ->assertSessionHasErrors('verification')
            ->assertSessionHas('registration_verification_token', 'opaque-verification-token');
    }

    public function test_resend_replaces_timing_metadata_without_exposing_the_code(): void
    {
        $newChallenge = $this->challengeData();
        $newChallenge['otpExpiresAt'] = '2026-11-04T12:10:00Z';
        Http::fake([
            '*/auth/otp/resend' => Http::response(['data' => $newChallenge], 202),
        ]);

        $this->withSession($this->verificationSession())
            ->post(route('verification.email.resend'))
            ->assertRedirect()
            ->assertSessionHas('registration_otp_expires_at', '2026-11-04T12:10:00Z')
            ->assertSessionHas('success');

        $this->assertArrayNotHasKey('code', $newChallenge);
    }

    public function test_resend_limit_is_shown_without_removing_the_challenge(): void
    {
        Http::fake([
            '*/auth/otp/resend' => Http::response([
                'error' => ['code' => 'OTP_RESEND_TOO_SOON', 'message' => 'Backend'],
            ], 429),
        ]);

        $this->withSession($this->verificationSession())
            ->post(route('verification.email.resend'))
            ->assertSessionHasErrors('verification')
            ->assertSessionHas('registration_verification_token', 'opaque-verification-token');
    }

    public function test_pending_patient_can_resume_verification_with_credentials(): void
    {
        Http::fake([
            '*/auth/otp/request' => Http::response(['data' => $this->challengeData()], 202),
        ]);

        $this->post(route('verification.email.request'), [
            'email' => 'nuevo@clinica.bo',
            'password' => 'PacienteSegura123',
        ])->assertRedirect(route('verification.email'))
            ->assertSessionHas('registration_verification_token', 'opaque-verification-token');
    }

    public function test_verification_page_never_renders_an_otp_value(): void
    {
        $this->withSession($this->verificationSession())
            ->get(route('verification.email'))
            ->assertOk()
            ->assertSee('Confirma tu correo')
            ->assertSee('Modo DEMO local')
            ->assertSee('No se envió un correo real')
            ->assertSee('name="_token"', false)
            ->assertDontSee('123456');
    }

    private function registrationData(): array
    {
        return [
            'ci' => 'CI-9001',
            'nombre_completo' => 'Paciente Prueba',
            'fecha_nacimiento' => '1995-04-12',
            'telefono' => '+59170000678',
            'email' => 'nuevo@clinica.bo',
            'ciudad_residencia' => 'La Paz',
            'password' => 'PacienteSegura123',
            'password_confirmation' => 'PacienteSegura123',
        ];
    }

    private function challengeData(): array
    {
        return [
            'verificationToken' => 'opaque-verification-token',
            'maskedDestination' => 'n***@clinica.bo',
            'otpExpiresAt' => '2026-11-04T12:05:00Z',
            'resendAvailableAt' => '2026-11-04T12:01:00Z',
            'verificationExpiresAt' => '2026-11-04T12:30:00Z',
            'deliveryMode' => 'DEMO',
            'deliveryConfirmed' => false,
        ];
    }

    private function verificationSession(): array
    {
        return [
            'registration_verification_token' => 'opaque-verification-token',
            'registration_masked_destination' => 'n***@clinica.bo',
            'registration_otp_expires_at' => '2026-11-04T12:05:00Z',
            'registration_resend_available_at' => '2026-11-04T12:01:00Z',
            'registration_verification_expires_at' => '2026-11-04T12:30:00Z',
            'registration_delivery_mode' => 'DEMO',
            'registration_delivery_confirmed' => false,
        ];
    }
}
