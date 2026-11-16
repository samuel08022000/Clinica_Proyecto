<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegistrationOtpFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->withoutVite();
    }

    #[Test]
    public function registration_form_preserves_fields_route_and_csrf(): void
    {
        $response = $this->get('/registro')->assertOk()
            ->assertSee('action="'.route('register.store').'"', false)
            ->assertSee('name="_token"', false);

        foreach (['ci', 'nombre_completo', 'fecha_nacimiento', 'telefono', 'email', 'ciudad_residencia', 'password', 'password_confirmation'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function registration_validation_happens_before_database_or_quarkus_access(): void
    {
        $this->from('/registro')->post('/registro', [
            'email' => 'invalido',
            'password' => 'debil',
            'password_confirmation' => 'distinta',
        ])->assertRedirect('/registro')->assertSessionHasErrors([
            'ci', 'nombre_completo', 'fecha_nacimiento', 'telefono', 'email', 'ciudad_residencia', 'password',
        ]);

        Http::assertNothingSent();
    }

    #[Test]
    public function otp_screen_preserves_verify_resend_and_credential_request_forms(): void
    {
        $this->get('/verificar-correo')->assertOk()
            ->assertSee('action="'.route('verification.email.request').'"', false)
            ->assertSee('name="_token"', false);

        $response = $this->withSession([
            'registration_verification_token' => 'reto-opaco',
        ])->get('/verificar-correo')->assertOk()
            ->assertSee('action="'.route('verification.email.verify').'"', false)
            ->assertSee('action="'.route('verification.email.resend').'"', false);

        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), 'name="_token"'));
        Http::assertNothingSent();
    }

    #[Test]
    public function otp_validation_rejects_malformed_codes_without_calling_quarkus(): void
    {
        $this->from('/verificar-correo')->post('/verificar-correo', ['code' => '12'])
            ->assertRedirect('/verificar-correo')
            ->assertSessionHasErrors('code');

        Http::assertNothingSent();
    }

    #[Test]
    public function otp_screen_does_not_render_a_secret_from_session(): void
    {
        $this->withSession([
            'registration_verification_token' => 'reto-opaco',
            'registration_masked_destination' => 'p***@example.invalid',
        ])->get('/verificar-correo')
            ->assertOk()
            ->assertDontSee('reto-opaco');
    }
}
