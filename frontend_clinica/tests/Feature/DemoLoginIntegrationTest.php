<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemoLoginIntegrationTest extends TestCase
{
    private string $demoPassword;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_DEMO_LOGIN_INTEGRATION') !== '1') {
            $this->markTestSkipped('Integracion real desactivada.');
        }

        $password = getenv('CLINICA_DEMO_PASSWORD_ONCE');
        if (! is_string($password) || $password === '') {
            $this->markTestSkipped('La contrasena demo no fue proporcionada de forma segura.');
        }

        $this->demoPassword = $password;
        $this->withoutVite();
        $quarkusApiUrl = getenv('QUARKUS_API_URL') ?: 'http://127.0.0.1:8080/api/v1';
        config()->set('services.quarkus.base_url', rtrim($quarkusApiUrl, '/'));
    }

    public static function demoUsers(): array
    {
        return [
            'paciente' => [
                'samuel.paciente@unifranz.edu.bo',
                'ROL001',
                'paciente.home',
            ],
            'secretaria' => [
                'angela.secretaria@clinica.bo',
                'ROL002',
                'secretaria.home',
            ],
            'medico' => [
                'dilan.doctor@clinica.bo',
                'ROL003',
                'medico.home',
            ],
            'administracion' => [
                'yesid.admin@clinica.bo',
                'ROL004',
                'administracion.home',
            ],
        ];
    }

    #[DataProvider('demoUsers')]
    public function test_real_login_redirect_authorization_and_logout(
        string $email,
        string $roleCode,
        string $homeRoute,
    ): void {
        $this->post(route('login.attempt'), [
            'email' => $email,
            'password' => $this->demoPassword,
        ])->assertRedirect(route($homeRoute))
            ->assertSessionHas('cod_rol', $roleCode)
            ->assertSessionHas('auth_token');

        $this->get(route($homeRoute))
            ->assertOk()
            ->assertSee($email);

        $this->post(route('logout'))
            ->assertRedirect(route('home'))
            ->assertSessionMissing('auth_token');
    }

    public function test_real_login_rejects_an_incorrect_password(): void
    {
        $incorrectPassword = 'incorrect-'.Str::uuid()->toString();

        $this->from(route('login'))->post(route('login.attempt'), [
            'email' => 'samuel.paciente@unifranz.edu.bo',
            'password' => $incorrectPassword,
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('authentication')
            ->assertSessionMissing('auth_token');
    }
}
