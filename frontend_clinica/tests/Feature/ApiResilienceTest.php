<?php

namespace Tests\Feature;

use App\Services\QuarkusApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiResilienceTest extends TestCase
{
    public function test_authentication_rate_limits_are_scoped_to_each_operation(): void
    {
        Http::fake(['*/auth/password/forgot' => Http::response(['data' => ['message' => 'Solicitud recibida.']], 202)]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('register.store'), [])->assertRedirect();
        }
        $this->post(route('register.store'), [])->assertStatus(429);
        $this->post(route('password.email'), ['email' => 'fiction@example.test'])
            ->assertRedirect()->assertSessionHas('success');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.attempt'), [])->assertRedirect();
        }
        $this->post(route('login.attempt'), [])->assertStatus(429);
    }

    private function localSession(): array
    {
        return ['auth_token' => 'opaque-test-token', 'cod_usu' => 'USU000001', 'cod_rol' => 'ROL002'];
    }

    #[DataProvider('transientStatuses')]
    public function test_upstream_failure_denies_access_without_discarding_the_session(int $status): void
    {
        Http::fake(['*/auth/authorize*' => Http::response([], $status)]);
        $this->withSession($this->localSession())->get(route('secretaria.home'))
            ->assertStatus(503)->assertHeader('Retry-After', '30')->assertSessionHas('auth_token');
    }

    public static function transientStatuses(): array
    {
        return [[500], [502], [503], [404]];
    }

    public function test_connection_failure_preserves_session_but_does_not_open_the_dashboard(): void
    {
        Http::fake(fn () => throw new ConnectionException('Servicio no disponible'));
        $this->withSession($this->localSession())->get(route('secretaria.home'))
            ->assertStatus(503)->assertSessionHas('auth_token')->assertDontSee('Inicio de Secretaria');
    }

    public function test_boolean_query_filters_use_the_quarkus_contract_and_keep_page_zero(): void
    {
        Http::fake(['*' => Http::response(['incidencias' => []], 200)]);
        session()->put($this->localSession());
        app(QuarkusApiClient::class)->get('administracion/pacientes/PAC000001/sanciones', [
            'vigente' => false, 'page' => 0, 'size' => 20, 'tipo' => null,
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'vigente=false')
            && str_contains($request->url(), 'page=0') && ! str_contains($request->url(), 'tipo=')
            && $request->hasHeader('Authorization', 'Bearer opaque-test-token'));
    }

    #[DataProvider('businessStatuses')]
    public function test_client_preserves_api_errors_without_retrying_mutations(int $status): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'CODIGO', 'mensaje' => 'Mensaje administrativo'], $status)]);
        session()->put($this->localSession());
        $response = app(QuarkusApiClient::class)->post('secretaria/citas/CIT00000001/cancelar', ['motivo' => 'Motivo']);
        $this->assertSame($status, $response->status());
        $this->assertSame('CODIGO', $response->json('error'));
        Http::assertSentCount(1);
    }

    public static function businessStatuses(): array
    {
        return [[401], [403], [404], [409], [423]];
    }
}
