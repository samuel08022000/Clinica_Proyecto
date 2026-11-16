<?php

namespace Tests\Postgres;

use App\Contracts\GoogleIdentityProvider;
use App\Exceptions\AuthDomainException;
use App\Mail\PasswordResetMail;
use App\Mail\VerificationCodeMail;
use App\Services\Auth\ChallengeStore;
use App\Services\Auth\GoogleAuthService;
use App\Services\Auth\OtpService;
use App\Services\Auth\PasswordRecoveryService;
use App\Services\Auth\PatientRegistrationService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthenticationMigrationTest extends TestCase
{
    private PDO $owner;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLINICAL_PG_TESTS') !== '1') {
            $this->markTestSkipped('Requiere PostgreSQL aislado mediante Invoke-Laravel.ps1 -TestDatabase.');
        }
        Artisan::call('migrate', ['--force' => true]);
        config(['session.driver' => 'database', 'session.connection' => 'sqlite', 'mail.default' => 'array',
            'clinic.features.google_oauth' => true]);
        Mail::fake();
        $this->withoutVite();
        $this->owner = new PDO('pgsql:host=127.0.0.1;port=35443;dbname=clinica_laravel_phase1_tests',
            'clinica_owner', getenv('CLINICAL_OWNER_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    #[Test]
    public function all_four_roles_authenticate_with_existing_bcrypt_hashes_and_redirect_correctly(): void
    {
        foreach (['ROL001' => '/paciente/inicio', 'ROL002' => '/secretaria/inicio', 'ROL003' => '/medico/inicio', 'ROL004' => '/administracion/inicio'] as $role => $home) {
            $user = $this->user($role);
            $oldId = session()->getId();
            $response = $this->post('/login', ['email' => $user['email'], 'password' => $user['password']]);
            $response->assertRedirect($home)->assertSessionHas('auth_authority', 'laravel')->assertSessionHas('cod_rol', $role);
            $this->assertNotSame($oldId, session()->getId());
            $this->post('/logout')->assertRedirect('/');
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function invalid_unknown_disabled_profile_and_google_only_accounts_are_rejected(): void
    {
        $valid = $this->user('ROL001');
        $this->post('/login', ['email' => $valid['email'], 'password' => 'Incorrecta123'])->assertSessionHasErrors('authentication');
        $this->post('/login', ['email' => $this->unique().'@example.invalid', 'password' => 'Incorrecta123'])->assertSessionHasErrors('authentication');
        $disabled = $this->user('ROL004', userStatus: 'Inactivo');
        $this->post('/login', ['email' => $disabled['email'], 'password' => $disabled['password']])->assertSessionHasErrors('authentication');
        $profile = $this->user('ROL003', profileStatus: 'Inactivo');
        $this->post('/login', ['email' => $profile['email'], 'password' => $profile['password']])->assertSessionHasErrors('authentication');
        $googleOnly = $this->user('ROL001', password: null);
        $this->post('/login', ['email' => $googleOnly['email'], 'password' => 'Cualquier123'])->assertSessionHasErrors('authentication');
    }

    #[Test]
    public function repeated_login_attempts_are_limited_and_success_clears_failures(): void
    {
        $user = $this->user('ROL001');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user['email'], 'password' => 'Incorrecta123']);
        }
        $this->post('/login', ['email' => $user['email'], 'password' => $user['password']])->assertStatus(429);
        $key = 'clinical-auth:'.hash('sha256', $user['email'].'|127.0.0.1');
        app(RateLimiter::class)->clear($key);
        $this->travel(16)->minutes();
        $this->post('/login', ['email' => $user['email'], 'password' => $user['password']])->assertRedirect('/paciente/inicio');
    }

    #[Test]
    public function middleware_enforces_roles_and_revalidates_status_role_and_expiration(): void
    {
        $patient = $this->user('ROL001');
        $this->post('/login', ['email' => $patient['email'], 'password' => $patient['password']]);
        $this->get('/administracion/inicio')->assertForbidden();
        $this->get('/secretaria/inicio')->assertForbidden();
        $this->get('/medico/inicio')->assertForbidden();
        $this->get('/paciente/inicio')->assertOk();
        $this->withSession(['auth_expires_at' => now()->subSecond()->toIso8601String()])->get('/paciente/inicio')->assertRedirect('/login');

        $doctor = $this->user('ROL003');
        $this->post('/login', ['email' => $doctor['email'], 'password' => $doctor['password']]);
        $this->get('/administracion/inicio')->assertForbidden();
        $this->owner->prepare("UPDATE usuarios SET cod_rol='ROL004' WHERE cod_usu=?")->execute([$doctor['id']]);
        $this->get('/medico/inicio')->assertForbidden();

        $secretary = $this->user('ROL002');
        $this->post('/login', ['email' => $secretary['email'], 'password' => $secretary['password']]);
        $this->get('/medico/inicio')->assertForbidden();
        $this->owner->prepare("UPDATE usuarios SET estado='Inactivo' WHERE cod_usu=?")->execute([$secretary['id']]);
        $this->get('/secretaria/inicio')->assertRedirect('/login');
    }

    #[Test]
    public function logout_invalidates_session_and_protected_access(): void
    {
        $user = $this->user('ROL001');
        $this->post('/login', ['email' => $user['email'], 'password' => $user['password']]);
        $this->post('/logout')->assertRedirect('/');
        $this->get('/paciente/inicio')->assertRedirect('/login');
    }

    #[Test]
    public function registration_is_atomic_audited_and_rejects_duplicates_and_invalid_passwords(): void
    {
        $data = $this->registrationData();
        $response = $this->post('/registro', $data + ['password_confirmation' => $data['password']]);
        $response->assertRedirect('/verificar-correo')->assertSessionHas('registration_verification_token');
        Mail::assertSent(VerificationCodeMail::class, 1);
        $userId = $this->scalar('SELECT cod_usu FROM usuarios WHERE email='.$this->quote($data['email']));
        $this->assertNotFalse($userId);
        $this->assertSame(1, (int) $this->scalar('SELECT count(*) FROM pacientes WHERE cod_usu='.$this->quote($userId)));
        $this->assertGreaterThanOrEqual(1, (int) $this->scalar('SELECT count(*) FROM bitacora WHERE clave_registro='.$this->quote($userId)));
        $this->post('/registro', array_replace($this->registrationData(), ['email' => $data['email'], 'password_confirmation' => 'Segura123A']))->assertSessionHasErrors('registration');
        $this->post('/registro', array_replace($this->registrationData(), ['ci' => $data['ci'], 'password_confirmation' => 'Segura123A']))->assertSessionHasErrors('registration');
        $invalid = $this->registrationData();
        $invalid['password'] = 'debil';
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2'])
            ->post('/registro', $invalid + ['password_confirmation' => 'debil'])
            ->assertSessionHasErrors('password');

        config(['mail.default' => 'log']);
        $rollback = $this->registrationData();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.3'])
            ->post('/registro', $rollback + ['password_confirmation' => $rollback['password']])
            ->assertSessionHasErrors('registration');
        $this->assertSame(0, (int) $this->scalar('SELECT count(*) FROM usuarios WHERE email='.$this->quote($rollback['email'])));
    }

    #[Test]
    public function otp_wrong_attempt_commits_counter_three_attempts_consume_and_correct_code_is_single_use(): void
    {
        $data = $this->registrationData();
        $verification = app(PatientRegistrationService::class)->register($data);
        $mail = $this->lastOtpMail();
        $otp = app(OtpService::class);
        try {
            $otp->verify($verification['verificationToken'], $mail->code === '000000' ? '000001' : '000000');
        } catch (AuthDomainException $e) {
            $this->assertSame('OTP_INVALID_OR_EXPIRED', $e->errorCode);
        }
        $this->assertSame(1, (int) $this->scalar('SELECT intentos FROM verificaciones_otp WHERE cod_usu='.$this->quote($verification['userId']).' ORDER BY creado_en DESC LIMIT 1'));
        for ($i = 0; $i < 2; $i++) {
            try {
                $otp->verify($verification['verificationToken'], $mail->code === '000000' ? '000001' : '000000');
            } catch (AuthDomainException) {
            }
        }
        $this->assertSame(3, (int) $this->scalar('SELECT intentos FROM verificaciones_otp WHERE cod_usu='.$this->quote($verification['userId']).' ORDER BY creado_en DESC LIMIT 1'));
        $this->assertNotFalse($this->scalar('SELECT consumido_en FROM verificaciones_otp WHERE cod_usu='.$this->quote($verification['userId']).' ORDER BY creado_en DESC LIMIT 1'));

        Mail::fake();
        $second = app(PatientRegistrationService::class)->register($this->registrationData());
        $correct = $this->lastOtpMail()->code;
        $this->assertTrue($otp->verify($second['verificationToken'], $correct));
        $this->expectException(AuthDomainException::class);
        $otp->verify($second['verificationToken'], $correct);
    }

    #[Test]
    public function otp_resend_is_limited_and_secrets_do_not_enter_logs(): void
    {
        $verification = app(PatientRegistrationService::class)->register($this->registrationData());
        $code = $this->lastOtpMail()->code;
        try {
            app(OtpService::class)->resend($verification['verificationToken']);
            $this->fail('Reenvío anticipado aceptado');
        } catch (AuthDomainException $e) {
            $this->assertSame('OTP_RESEND_TOO_SOON', $e->errorCode);
        }
        $log = is_file(storage_path('logs/laravel.log')) ? file_get_contents(storage_path('logs/laravel.log')) : '';
        $this->assertStringNotContainsString($code, $log);
    }

    #[Test]
    public function recovery_is_neutral_single_use_updates_bcrypt_audits_and_revokes_sessions(): void
    {
        $user = $this->user('ROL001');
        $service = app(PasswordRecoveryService::class);
        $this->assertSame(PasswordRecoveryService::GENERIC_MESSAGE, $service->request($this->unique().'@example.invalid'));
        $this->assertSame(PasswordRecoveryService::GENERIC_MESSAGE, $service->request($user['email']));
        $mail = null;
        Mail::assertSent(PasswordResetMail::class, function ($item) use (&$mail) {
            $mail = $item;

            return true;
        });
        parse_str((string) parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($mail->resetUrl, PHP_URL_PATH));
        app('db')->connection('sqlite')->table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user['id'],
            'ip_address' => null, 'user_agent' => null, 'payload' => 'x', 'last_activity' => time()]);
        $service->reset($user['email'], $token, 'NuevaSegura123');
        $hash = $this->scalar('SELECT password_hash FROM usuarios WHERE cod_usu='.$this->quote($user['id']));
        $this->assertTrue(Hash::check('NuevaSegura123', $hash));
        $this->assertSame(0, app('db')->connection('sqlite')->table('sessions')->where('user_id', $user['id'])->count());
        $this->assertGreaterThanOrEqual(1, (int) $this->scalar("SELECT count(*) FROM bitacora WHERE nombre_tabla='usuarios' AND clave_registro=".$this->quote($user['id']).' AND cod_usu='.$this->quote($user['id'])));
        $this->expectException(AuthDomainException::class);
        $service->reset($user['email'], $token, 'OtraSegura123');
    }

    #[Test]
    public function expired_recovery_token_is_rejected(): void
    {
        $user = $this->user('ROL001');
        app(PasswordRecoveryService::class)->request($user['email']);
        $mail = null;
        Mail::assertSent(PasswordResetMail::class, function ($item) use (&$mail) {
            $mail = $item;

            return true;
        });
        parse_str((string) parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($mail->resetUrl, PHP_URL_PATH));
        $this->owner->prepare("UPDATE tokens_recuperacion SET expira_en=now()-interval '1 second' WHERE cod_usu=?")->execute([$user['id']]);
        $this->expectException(AuthDomainException::class);
        app(PasswordRecoveryService::class)->reset($user['email'], $token, 'NuevaSegura123');
    }

    #[Test]
    public function google_contract_handles_success_state_failures_claim_reuse_and_unauthorized_link(): void
    {
        $fake = new FakeGoogleIdentityProvider;
        $this->app->instance(GoogleIdentityProvider::class, $fake);
        $start = $this->get('/auth/google')->assertRedirect();
        $flow = session('google_oauth_flow');
        $this->get('/auth/google/callback?state=incorrecto&code=x')->assertRedirect('/login')->assertSessionHasErrors('authentication');
        $this->assertSame(0, $fake->exchanges);

        foreach (['nonce', 'expired', 'signature', 'audience'] as $mode) {
            $fake->mode = $mode;
            $this->get('/auth/google');
            $flow = session('google_oauth_flow');
            $this->get('/auth/google/callback?state='.urlencode($flow['state']).'&code=x')->assertRedirect('/login')->assertSessionHasErrors('authentication');
        }

        $fake->mode = 'valid';
        $fake->identity = ['subject' => $this->unique(), 'email' => $this->unique().'@example.invalid',
            'name' => 'Paciente Google Seguro', 'emailVerified' => true];
        $this->get('/auth/google');
        $flow = session('google_oauth_flow');
        $this->get('/auth/google/callback?state='.urlencode($flow['state']).'&code=ok')->assertRedirect('/registro/google/completar');
        $token = session('google_registration_token');
        $this->post('/registro/google/completar', ['ci' => $this->unique(), 'fecha_nacimiento' => '1990-01-01',
            'telefono' => '70000000', 'ciudad_residencia' => 'La Paz'])->assertRedirect('/paciente/inicio');
        $googleUser = $this->scalar('SELECT cod_usu FROM usuarios WHERE email='.$this->quote($fake->identity['email']));
        $this->assertFalse($this->scalar('SELECT password_hash FROM usuarios WHERE cod_usu='.$this->quote($googleUser)));
        $this->post('/logout');
        $this->post('/login', ['email' => $fake->identity['email'], 'password' => 'Cualquier123'])->assertSessionHasErrors('authentication');
        $this->withSession(['google_registration_token' => $token])->post('/registro/google/completar', [
            'ci' => $this->unique(), 'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'ciudad_residencia' => 'La Paz',
        ])->assertSessionHasErrors('registration');

        $other = $this->user('ROL001');
        $link = app(ChallengeStore::class)->issue('google-link', null, ['subject' => $this->unique(),
            'email' => $fake->identity['email'], 'name' => 'Otra Identidad', 'emailVerified' => true], 600);
        $this->expectException(AuthDomainException::class);
        app(GoogleAuthService::class)->linkAuthenticated($other['id'], $link);
    }

    #[Test]
    public function phase_one_schema_remains_unchanged_and_auth_flows_send_no_quarkus_http(): void
    {
        Http::fake();
        $user = $this->user('ROL001');
        $this->post('/login', ['email' => $user['email'], 'password' => $user['password']])->assertRedirect('/paciente/inicio');
        Http::assertNothingSent();
        $this->assertSame(21, (int) $this->scalar("SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename<>'flyway_schema_history'"));
        $this->assertSame(2, (int) $this->scalar("SELECT count(*) FROM pg_views WHERE schemaname='public'"));
        $this->assertSame(3, (int) $this->scalar('SELECT count(*) FROM flyway_schema_history WHERE success'));
    }

    private function user(string $role, string $userStatus = 'Activo', string $profileStatus = 'Activo', ?string $password = 'Segura123A'): array
    {
        $suffix = $this->unique();
        $email = strtolower($suffix).'@example.invalid';
        $statement = $this->owner->prepare('INSERT INTO usuarios(nombre_completo,email,password_hash,cod_rol,estado) VALUES (?,?,?,?,?) RETURNING cod_usu');
        $statement->execute(['Usuario Sintetico '.$suffix, $email, $password === null ? null : Hash::make($password), $role, $userStatus]);
        $id = $statement->fetchColumn();
        if ($role === 'ROL001') {
            $this->owner->prepare("INSERT INTO pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,estado_cuenta,email_verificado,email_verificado_en) VALUES (?,?,'1990-01-01','70000000','La Paz',?,true,now())")
                ->execute([$id, $suffix, $profileStatus]);
        } elseif ($role === 'ROL002') {
            $this->owner->prepare('INSERT INTO secretarias(cod_usu,ci,telefono,estado) VALUES (?,?,?,?)')->execute([$id, $suffix, '70000000', $profileStatus]);
        } elseif ($role === 'ROL003') {
            $this->owner->prepare('INSERT INTO medicos(cod_usu,ci,matricula_profesional,estado) VALUES (?,?,?,?)')->execute([$id, $suffix, 'MAT-'.$suffix, $profileStatus]);
        }

        return compact('id', 'email', 'password', 'role');
    }

    private function registrationData(): array
    {
        $suffix = $this->unique();

        return ['ci' => $suffix, 'nombre_completo' => 'Paciente Sintetico Seguro', 'fecha_nacimiento' => '1990-01-01',
            'telefono' => '70000000', 'email' => strtolower($suffix).'@example.invalid', 'ciudad_residencia' => 'La Paz', 'password' => 'Segura123A'];
    }

    private function lastOtpMail(): VerificationCodeMail
    {
        $mail = null;
        Mail::assertSent(VerificationCodeMail::class, function ($item) use (&$mail) {
            $mail = $item;

            return true;
        });

        return $mail;
    }

    private function scalar(string $sql): mixed
    {
        return $this->owner->query($sql)->fetchColumn();
    }

    private function quote(string $value): string
    {
        return $this->owner->quote($value);
    }

    private function unique(): string
    {
        return 'T'.strtoupper(bin2hex(random_bytes(8)));
    }
}

final class FakeGoogleIdentityProvider implements GoogleIdentityProvider
{
    public string $mode = 'valid';

    public int $exchanges = 0;

    public array $identity = ['subject' => 'subject', 'email' => 'google@example.invalid', 'name' => 'Paciente Google', 'emailVerified' => true];

    public function available(): bool
    {
        return true;
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge, string $redirectUri): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?state='.urlencode($state);
    }

    public function exchange(string $code, string $codeVerifier, string $expectedNonce, string $redirectUri): array
    {
        $this->exchanges++;
        if ($this->mode !== 'valid') {
            throw new AuthDomainException(match ($this->mode) {
                'expired' => 'GOOGLE_TOKEN_EXPIRED', 'signature' => 'GOOGLE_SIGNATURE_INVALID',
                'audience' => 'GOOGLE_AUDIENCE_INVALID', default => 'GOOGLE_NONCE_INVALID',
            }, 401, 'Token OIDC simulado rechazado.');
        }

        return $this->identity;
    }
}
