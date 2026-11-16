<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** E2E HTTP con ambos servidores; no sustituye una revisión visual en un navegador. */
class LiveHttpStackTest extends TestCase
{
    #[DataProvider('roles')]
    public function test_http_cookies_csrf_role_isolation_and_logout(string $role, string $home): void
    {
        if (getenv('RUN_CLINICA_HTTP_STACK') !== '1') {
            $this->markTestSkipped('Servidores aislados no activados.');
        }
        $path = getenv('CLINICA_LIVE_FIXTURE');
        $this->assertTrue(is_string($path) && is_file($path));
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^clinica_fase9_live(?:_[a-z0-9]+)?$/D', $fixture['database']);
        $this->assertSame('http://127.0.0.1:18091', $fixture['laravelUrl']);
        $user = $fixture['users'][$role];
        $cookies = new CookieJar;
        $http = new Client(['base_uri' => $fixture['laravelUrl'], 'cookies' => $cookies,
            'http_errors' => false, 'allow_redirects' => false, 'connect_timeout' => 3, 'timeout' => 15]);
        $form = $http->get('/login');
        $this->assertSame(200, $form->getStatusCode());
        $csrf = $this->csrf((string) $form->getBody());
        $missingCsrf = $http->post('/login', ['form_params' => ['email' => $user['email'], 'password' => $user['password']]]);
        $this->assertSame(419, $missingCsrf->getStatusCode());
        $csrf = $this->csrf((string) $http->get('/login')->getBody());
        $login = $http->post('/login', ['form_params' => ['_token' => $csrf, 'email' => $user['email'], 'password' => $user['password']]]);
        $this->assertSame(302, $login->getStatusCode());
        $this->assertTrue(str_ends_with($login->getHeaderLine('Location'), $home));
        $dashboard = $http->get($home);
        $this->assertSame(200, $dashboard->getStatusCode());
        $html = (string) $dashboard->getBody();
        $this->assertTrue(str_contains($html, $user['email']));
        $this->assertSame(200, $http->get($home)->getStatusCode());
        $other = $role === 'ROL004' ? '/secretaria/inicio' : '/administracion/inicio';
        $this->assertSame(403, $http->get($other)->getStatusCode());
        $otherWrite = $role === 'ROL004' ? '/secretaria/checkin' : '/administracion/medicos';
        $this->assertSame(403, $http->post($otherWrite, ['form_params' => [
            '_token' => $this->csrf($html), 'tokenQr' => 'unreachable-role-probe',
        ]])->getStatusCode());
        $this->assertTrue(count(array_filter($cookies->toArray(), fn (array $cookie): bool => $cookie['HttpOnly'] ?? false)) > 0);
        $logout = $http->post('/logout', ['form_params' => ['_token' => $this->csrf($html)]]);
        $this->assertSame(302, $logout->getStatusCode());
        $this->assertSame(302, $http->get($home)->getStatusCode());
    }

    public static function roles(): array
    {
        return [['ROL001', '/paciente/inicio'], ['ROL002', '/secretaria/inicio'], ['ROL003', '/medico/inicio'], ['ROL004', '/administracion/inicio']];
    }

    public function test_real_network_failure_keeps_cookie_session_and_allows_recovery(): void
    {
        if (getenv('RUN_CLINICA_HTTP_STACK') !== '1') {
            $this->markTestSkipped('Servidores aislados no activados.');
        }
        $path = getenv('CLINICA_LIVE_FIXTURE');
        $this->assertTrue(is_string($path) && is_file($path));
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^clinica_fase9_live(?:_[a-z0-9]+)?$/D', $fixture['database']);
        $this->assertSame('http://127.0.0.1:18091', $fixture['laravelUrl']);
        $user = $fixture['users']['ROL002'];
        $cookies = new CookieJar;
        $options = ['cookies' => $cookies, 'http_errors' => false, 'allow_redirects' => false, 'connect_timeout' => 3, 'timeout' => 15];
        $healthy = new Client(['base_uri' => $fixture['laravelUrl']] + $options);
        $faulty = new Client(['base_uri' => 'http://127.0.0.1:18092'] + $options);
        $csrf = $this->csrf((string) $healthy->get('/login')->getBody());
        $this->assertSame(302, $healthy->post('/login', ['form_params' => ['_token' => $csrf, 'email' => $user['email'], 'password' => $user['password']]])->getStatusCode());
        $authorized = $healthy->get('/secretaria/inicio');
        $this->assertSame(200, $authorized->getStatusCode());
        $unavailable = $faulty->get('/secretaria/inicio');
        $this->assertSame(503, $unavailable->getStatusCode());
        $this->assertSame('30', $unavailable->getHeaderLine('Retry-After'));
        $blockedWrite = $faulty->post('/secretaria/checkin', ['form_params' => [
            '_token' => $this->csrf((string) $authorized->getBody()), 'tokenQr' => 'unreachable-transport-probe',
        ]]);
        $this->assertSame(503, $blockedWrite->getStatusCode());
        $this->assertSame('30', $blockedWrite->getHeaderLine('Retry-After'));
        $recovered = $healthy->get('/secretaria/inicio');
        $this->assertSame(200, $recovered->getStatusCode());
        $this->assertSame(302, $healthy->post('/logout', ['form_params' => ['_token' => $this->csrf((string) $recovered->getBody())]])->getStatusCode());
    }

    private function csrf(string $html): string
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $node = (new DOMXPath($document))->query('//input[@name="_token"]')->item(0);
        $this->assertNotNull($node);

        return $node->getAttribute('value');
    }
}
