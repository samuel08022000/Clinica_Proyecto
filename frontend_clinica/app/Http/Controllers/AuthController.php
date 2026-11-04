<?php

namespace App\Http\Controllers;

use App\Services\QuarkusApiClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    private const ROLE_HOME_ROUTES = [
        'ROL001' => 'paciente.home',
        'ROL002' => 'secretaria.home',
        'ROL003' => 'medico.home',
        'ROL004' => 'administracion.home',
    ];

    public function __construct(private readonly QuarkusApiClient $apiClient) {}

    public function showLogin(): View|RedirectResponse
    {
        if (session()->has(['auth_token', 'cod_usu', 'cod_rol'])) {
            return redirect()->route($this->homeRouteForRole((string) session('cod_rol')));
        }

        $isDemoEnvironment = app()->environment(['local', 'demo']);

        return view('auth.login', [
            'backendAvailable' => true,
            'demoEnabled' => $isDemoEnvironment && config('clinic.demo.enabled'),
            'demoUsers' => $isDemoEnvironment ? config('clinic.demo.users', []) : [],
            'googleEnabled' => false,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string'],
        ]);

        try {
            $response = $this->apiClient->login(
                $request->string('email')->trim()->lower()->toString(),
                $request->string('password')->toString(),
            );
        } catch (ConnectionException) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['authentication' => 'El servicio de acceso no está disponible. Intenta nuevamente en unos minutos.']);
        }

        if (! $response->successful()) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['authentication' => $this->authenticationErrorMessage(
                    (string) data_get($response->json(), 'error.code'),
                    $response->status(),
                )]);
        }

        $payload = $response->json('data');
        $codUsu = data_get($payload, 'user.id');
        $codRol = data_get($payload, 'user.role.code');
        $token = data_get($payload, 'session.accessToken');

        if (! is_string($codUsu) || ! is_string($codRol) || ! isset(self::ROLE_HOME_ROUTES[$codRol])
            || ! is_string($token) || $token === '') {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['authentication' => 'El servicio respondió con un formato no reconocido. No se inició la sesión.']);
        }

        $request->session()->regenerate();
        $request->session()->put([
            'auth_token' => $token,
            'auth_expires_at' => data_get($payload, 'session.expiresAt'),
            'cod_usu' => $codUsu,
            'nombre_usuario' => data_get($payload, 'user.name'),
            'email_usuario' => data_get($payload, 'user.email'),
            'cod_rol' => $codRol,
            'nombre_rol' => data_get($payload, 'user.role.name'),
            'rol_slug' => data_get($payload, 'user.role.slug'),
            'estado_cuenta' => data_get($payload, 'user.accountStatus'),
        ]);

        return redirect()
            ->route(self::ROLE_HOME_ROUTES[$codRol])
            ->with('success', 'Sesión iniciada correctamente.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $revocationFailed = false;

        if ($request->session()->has('auth_token')) {
            try {
                $response = $this->apiClient->logout();
                $revocationFailed = ! $response->successful() && $response->status() !== 401;
            } catch (ConnectionException) {
                $revocationFailed = true;
            }
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = $revocationFailed
            ? 'La sesión local se cerró. Quarkus no pudo confirmar la revocación; el token expirará automáticamente.'
            : 'La sesión se cerró correctamente.';

        return redirect()->route('home')->with('success', $message);
    }

    public function dashboard(Request $request): View
    {
        return view('dashboard.home', [
            'user' => [
                'id' => $request->session()->get('cod_usu'),
                'name' => $request->session()->get('nombre_usuario'),
                'email' => $request->session()->get('email_usuario'),
                'roleCode' => $request->session()->get('cod_rol'),
                'roleName' => $request->session()->get('nombre_rol'),
                'accountStatus' => $request->session()->get('estado_cuenta'),
            ],
        ]);
    }

    public function showRegistration(): View
    {
        return view('auth.register', [
            'registrationAvailable' => false,
            'otpAvailable' => false,
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $request->validate([
            'ci' => ['required', 'string', 'max:30'],
            'nombre_completo' => ['required', 'string', 'min:3', 'max:150'],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:today'],
            'telefono' => ['required', 'string', 'max:25'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'ciudad_residencia' => ['required', 'string', 'max:100'],
            'password' => [
                'required',
                'confirmed',
                Password::min(10)->letters()->mixedCase()->numbers(),
            ],
        ]);

        return back()
            ->withInput($request->except(['password', 'password_confirmation']))
            ->withErrors([
                'registration' => 'El endpoint de registro y el envío OTP aún no están implementados en Quarkus. No se creó ninguna cuenta.',
            ]);
    }

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password', ['recoveryAvailable' => false]);
    }

    public function requestPasswordReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        return back()
            ->withInput($request->only('email'))
            ->withErrors([
                'recovery' => 'La API de recuperación todavía no está disponible. No se envió ningún correo.',
            ]);
    }

    public function showResetPassword(Request $request, ?string $token = null): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
            'resetAvailable' => false,
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        return back()->withErrors([
            'reset' => 'El endpoint para restablecer la contraseña todavía no está disponible. La contraseña no fue modificada.',
        ]);
    }

    public function showEmailVerification(): View
    {
        return view('auth.verify-email', [
            'verificationAvailable' => false,
        ]);
    }

    private function homeRouteForRole(string $role): string
    {
        return self::ROLE_HOME_ROUTES[$role] ?? 'login';
    }

    private function authenticationErrorMessage(string $code, int $status): string
    {
        return match ($code) {
            'INVALID_CREDENTIALS' => 'Correo o contraseña incorrectos.',
            'TOO_MANY_ATTEMPTS' => 'Se alcanzó el límite de intentos. Espera antes de volver a intentarlo.',
            'ACCOUNT_TEMPORARILY_BLOCKED' => 'La cuenta está bloqueada temporalmente. Contacta con la clínica si necesitas ayuda.',
            'ACCOUNT_DISABLED', 'PROFILE_DISABLED', 'ROLE_DISABLED', 'PROFILE_UNAVAILABLE' =>
                'La cuenta no está habilitada. Contacta con la administración de la clínica.',
            'ROLE_NOT_ALLOWED' => 'La cuenta no tiene un rol autorizado para este sistema.',
            default => $status >= 500
                ? 'El servicio de acceso presentó un error. Intenta nuevamente.'
                : 'No fue posible iniciar sesión con los datos proporcionados.',
        };
    }
}
