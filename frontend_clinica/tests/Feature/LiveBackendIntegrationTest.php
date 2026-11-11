<?php

namespace Tests\Feature;

use App\Services\QuarkusApiClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Laravel real y HTTP real hacia Quarkus; solo admite fixtures de una base aislada. */
class LiveBackendIntegrationTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_CLINICA_LIVE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Integración aislada desactivada.');
        }
        $path = getenv('CLINICA_LIVE_FIXTURE');
        if (! is_string($path) || ! is_file($path)) {
            $this->fail('Falta el fixture privado de integración.');
        }
        $this->fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^clinica_fase9_live(?:_[a-z0-9]+)?$/D', $this->fixture['database']);
        $this->assertSame('http://127.0.0.1:18090/api/v1', $this->fixture['apiUrl']);
        config()->set('services.quarkus.base_url', $this->fixture['apiUrl']);
        $this->withoutVite();
    }

    public static function roles(): array
    {
        return [
            ['ROL001', 'paciente.home'], ['ROL002', 'secretaria.home'],
            ['ROL003', 'medico.home'], ['ROL004', 'administracion.home'],
        ];
    }

    #[DataProvider('roles')]
    public function test_laravel_login_dashboard_session_and_remote_logout(string $role, string $home): void
    {
        $user = $this->fixture['users'][$role];
        $this->post(route('login.attempt'), ['email' => $user['email'], 'password' => $user['password']])
            ->assertRedirect(route($home))->assertSessionHas('cod_rol', $role)->assertSessionHas('auth_token');
        $token = session('auth_token');
        $this->get(route($home))->assertOk()->assertSee($user['email']);
        $this->get(route($home))->assertOk()->assertSessionHas('cod_usu', $user['id']);
        $this->post(route('logout'))->assertRedirect(route('home'))->assertSessionMissing('auth_token');
        $this->assertSame(401, Http::withToken($token)->get($this->fixture['apiUrl'].'/auth/me')->status());
    }

    public function test_secretary_cannot_open_admin_panel_and_revoked_session_is_cleared(): void
    {
        $user = $this->fixture['users']['ROL002'];
        $this->post(route('login.attempt'), ['email' => $user['email'], 'password' => $user['password']])->assertRedirect(route('secretaria.home'));
        $this->get(route('administracion.home'))->assertForbidden()->assertSessionHas('auth_token');
        $token = session('auth_token');
        $this->assertSame(200, Http::withToken($token)->post($this->fixture['apiUrl'].'/auth/logout')->status());
        $this->get(route('secretaria.home'))->assertRedirect(route('login'))->assertSessionMissing('auth_token');
        $this->withSession(['auth_token' => bin2hex(random_bytes(32)), 'cod_usu' => $user['id'], 'cod_rol' => 'ROL002'])
            ->get(route('secretaria.home'))->assertRedirect(route('login'))->assertSessionMissing('auth_token');
    }

    public function test_clinic_flows_through_the_existing_laravel_http_client(): void
    {
        // Las pantallas de estos módulos todavía no existen; esta prueba utiliza su cliente HTTP real.
        $api = $this->asApiRole('ROL002');
        $patient = $this->fixture['users']['ROL001'];
        $search = $api->get('secretaria/pacientes', ['q' => $patient['email']]);
        $this->assertSame(200, $search->status());
        $this->assertSame($patient['codPac'], $search->json('pacientes.0.codPac'));
        $catalog = $api->get('secretaria/especialidades');
        $this->assertSame(200, $catalog->status());
        $this->assertContains($this->fixture['codEsp'], array_column($catalog->json(), 'codEsp'));
        $this->assertSame(200, $api->get('secretaria/medicos', ['codEsp' => $this->fixture['codEsp']])->status());
        $slots = $this->availability($api);
        $this->assertNotEmpty($slots);
        foreach ($slots as $slot) {
            $this->assertTrue($slot['horaFin'] <= '10:00:00' || $slot['horaInicio'] >= '10:30:00');
        }
        $selected = $slots[0]['codSlot'];
        $booking = $api->post('secretaria/citas', ['codPac' => $patient['codPac'], 'codSlot' => $selected]);
        $this->assertSame(201, $booking->status());
        $code = $booking->json('codCit');
        $this->assertSame('Ventanilla', $booking->json('canalReserva'));
        $this->assertSame($this->fixture['users']['ROL002']['codSec'], $booking->json('codSec'));
        $this->assertArrayNotHasKey('tokenQr', $booking->json());
        $this->assertSame(200, $api->get('secretaria/citas/'.$code)->status());
        $this->assertSame(409, $api->post('secretaria/citas', ['codPac' => $patient['codPac'], 'codSlot' => $selected])->status());
        $this->assertSame(404, $api->get('secretaria/citas/CIT99999999')->status());
        $this->assertSame(403, $api->post('administracion/pacientes/'.$patient['codPac'].'/sanciones', ['motivo' => 'No autorizado', 'dias' => 1])->status());

        $accreditation = $api->get('secretaria/citas/'.$this->fixture['codCitQr'].'/acreditacion');
        $this->assertSame(200, $accreditation->status());
        $this->assertTrue($accreditation->json('admiteCheckin'));
        $arrival = $api->post('secretaria/checkin', ['tokenQr' => $this->fixture['tokenQr']]);
        $this->assertSame(200, $arrival->status());
        $this->assertSame('En Espera', $arrival->json('estado'));
        $this->assertTrue($arrival->json('checkinRegistrado'));
        $this->assertSame(409, $api->post('secretaria/checkin', ['tokenQr' => $this->fixture['tokenQr']])->status());

        $cancellation = $api->post('secretaria/citas/'.$code.'/cancelar', ['motivo' => 'Cancelación ficticia de integración']);
        $this->assertSame(200, $cancellation->status());
        $this->assertSame('Cancelada', $cancellation->json('estado'));
        $this->assertSame($this->fixture['users']['ROL002']['id'], $cancellation->json('canceladoPorUsu'));
        $this->assertContains($selected, array_column($this->availability($api), 'codSlot'));
        $history = $api->get('secretaria/pacientes/'.$patient['codPac'].'/historial', ['estado' => 'Cancelada']);
        $this->assertSame(200, $history->status());
        $this->assertContains($code, array_column($history->json('citas'), 'codCit'));

        $admin = $this->asApiRole('ROL004');
        $block = $admin->post('administracion/medicos/'.$this->fixture['codMed'].'/bloqueos', [
            'fechaBloqueo' => $this->fixture['fecha'], 'horaInicio' => '09:00', 'horaFin' => '09:30', 'motivo' => 'Indisponibilidad ficticia',
        ]);
        $this->assertSame(201, $block->status());
        $this->assertSame(200, $admin->put('personal/especialidades/'.$this->fixture['codEsp'].'/estado', ['estado' => 'Inactivo'])->status());
        $api = $this->asApiRole('ROL002');
        $this->assertNotContains($this->fixture['codEsp'], array_column($api->get('secretaria/especialidades')->json(), 'codEsp'));
        $this->assertSame([], $this->availability($api));
        $admin = $this->asApiRole('ROL004');
        $this->assertSame(200, $admin->put('personal/especialidades/'.$this->fixture['codEsp'].'/estado', ['estado' => 'Activo'])->status());
        $this->assertSame(200, $admin->put('personal/medicos/'.$this->fixture['codMed'].'/estado', ['estado' => 'Inactivo'])->status());
        $api = $this->asApiRole('ROL002');
        $this->assertSame([], $this->availability($api));
        $admin = $this->asApiRole('ROL004');
        $this->assertSame(200, $admin->put('personal/medicos/'.$this->fixture['codMed'].'/estado', ['estado' => 'Activo'])->status());
        $target = $this->fixture['sanctionPatient'];
        $sanction = $admin->post('administracion/pacientes/'.$target['codPac'].'/sanciones', ['motivo' => 'Sanción ficticia de integración', 'dias' => 1]);
        $this->assertSame(201, $sanction->status());
        $this->assertTrue($sanction->json('vigente'));
        $this->assertSame(200, $admin->get('administracion/pacientes/'.$target['codPac'].'/sanciones', ['vigente' => false])->status());
        $api = $this->asApiRole('ROL002');
        $remaining = $this->availability($api);
        foreach ($remaining as $slot) {
            $this->assertTrue($slot['horaFin'] <= '09:00:00' || $slot['horaInicio'] >= '09:30:00');
        }
        $this->assertFalse($api->get('secretaria/pacientes/'.$target['codPac'])->json('habilitadoParaReservar'));
        $this->assertSame(403, $api->post('secretaria/citas', ['codPac' => $target['codPac'], 'codSlot' => $selected])->status());
        $this->assertSame(423, $api->login($target['email'], $target['password'])->status());
    }

    public function test_registration_otp_and_password_recovery_use_real_backend_without_sending_mail(): void
    {
        $nonce = bin2hex(random_bytes(10));
        $email = $nonce.'@example.test';
        $password = base64_encode(random_bytes(24)).'aA1!';
        $this->post(route('register.store'), [
            'nombre_completo' => 'Paciente de Integracion', 'ci' => 'CI'.$nonce,
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'ciudad_residencia' => 'La Paz',
            'email' => $email, 'password' => $password, 'password_confirmation' => $password,
        ])->assertRedirect(route('verification.email'))->assertSessionHas('registration_verification_token');
        $challenge = session('registration_verification_token');
        $demo = Http::acceptJson()->withHeaders(['X-Demo-Otp-Key' => $this->fixture['demoKey']])
            ->post($this->fixture['apiUrl'].'/auth/otp/demo-code', ['verificationToken' => $challenge]);
        $this->assertSame(200, $demo->status());
        $code = $demo->json('data.code');
        $this->assertTrue(is_string($code) && strlen($code) === 6);
        $this->post(route('verification.email.verify'), ['code' => $code])->assertRedirect(route('login'));
        $this->post(route('login.attempt'), ['email' => $email, 'password' => $password])->assertRedirect(route('paciente.home'));
        $oldToken = session('auth_token');
        $this->post(route('password.email'), ['email' => $email])->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();
        $reset = Http::acceptJson()->withHeaders(['X-Demo-Otp-Key' => $this->fixture['demoKey']])
            ->post($this->fixture['apiUrl'].'/auth/password/demo-link', ['email' => $email]);
        $this->assertSame(200, $reset->status());
        // Do not request or log the URL containing the one-use credential.
        parse_str(parse_url($reset->json('data.resetUrl'), PHP_URL_QUERY), $parameters);
        $this->assertTrue(isset($parameters['token']) && is_string($parameters['token']));
        $newPassword = base64_encode(random_bytes(24)).'aA1!';
        $this->post(route('password.update'), [
            'email' => $email, 'token' => $parameters['token'],
            'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertRedirect(route('login'))->assertSessionMissing('auth_token');
        $this->assertSame(401, Http::withToken($oldToken)->get($this->fixture['apiUrl'].'/auth/me')->status());
        $this->post(route('login.attempt'), ['email' => $email, 'password' => $newPassword])->assertRedirect(route('paciente.home'));
        $this->post(route('logout'))->assertSessionMissing('auth_token');
    }

    private function asApiRole(string $role): QuarkusApiClient
    {
        $api = app(QuarkusApiClient::class);
        $user = $this->fixture['users'][$role];
        $response = $api->login($user['email'], $user['password']);
        $this->assertSame(200, $response->status());
        $token = $response->json('data.session.accessToken');
        $this->assertTrue(is_string($token) && $token !== '');
        session()->put('auth_token', $token);

        return $api;
    }

    private function availability(QuarkusApiClient $api): array
    {
        $response = $api->get('secretaria/disponibilidad', ['codEsp' => $this->fixture['codEsp'], 'codMed' => $this->fixture['codMed'], 'fecha' => $this->fixture['fecha']]);
        $this->assertSame(200, $response->status());

        return $response->json();
    }
}
