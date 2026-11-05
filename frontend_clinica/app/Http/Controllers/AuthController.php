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
            $errorCode = (string) data_get($response->json(), 'error.code');
            if ($errorCode === 'PHONE_VERIFICATION_REQUIRED') {
                return redirect()
                    ->route('verification.phone')
                    ->withInput($request->only('email'))
                    ->withErrors(['verification' => 'Debes verificar tu teléfono antes de iniciar sesión.']);
            }

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['authentication' => $this->authenticationErrorMessage(
                    $errorCode,
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
            'registrationAvailable' => true,
            'otpAvailable' => true,
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $request->validate([
            'ci' => ['required', 'string', 'min:4', 'max:30'],
            'nombre_completo' => ['required', 'string', 'min:3', 'max:150', "regex:/^[\\pL][\\pL .'\\-]{1,148}[\\pL.]$/u"],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:today'],
            'telefono' => ['required', 'string', 'max:25', 'regex:/^\\+?[0-9 ()-]{8,25}$/'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'ciudad_residencia' => ['required', 'string', 'min:3', 'max:100'],
            'password' => [
                'required',
                'confirmed',
                'max:72',
                Password::min(10)->letters()->mixedCase()->numbers(),
            ],
        ]);

        try {
            $response = $this->apiClient->registerPatient([
                'nombreCompleto' => $request->string('nombre_completo')->trim()->toString(),
                'email' => $request->string('email')->trim()->lower()->toString(),
                'password' => $request->string('password')->toString(),
                'ci' => $request->string('ci')->trim()->toString(),
                'fechaNacimiento' => $request->date('fecha_nacimiento')?->toDateString(),
                'telefono' => $request->string('telefono')->trim()->toString(),
                'ciudadResidencia' => $request->string('ciudad_residencia')->trim()->toString(),
            ]);
        } catch (ConnectionException) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['registration' => 'No pudimos conectar con el servicio de registro. Intenta nuevamente.']);
        }

        if (! $response->successful()) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['registration' => $this->registrationErrorMessage(
                    (string) data_get($response->json(), 'error.code'),
                    $response->status(),
                )]);
        }

        if (! $this->storeVerificationChallenge($request, $response->json('data.verification'))) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['registration' => 'El servicio respondió con un formato no reconocido.']);
        }

        return redirect()
            ->route('verification.phone')
            ->with('success', 'Cuenta creada. Ingresa el código enviado a tu teléfono.');
    }

    public function showPhoneVerification(Request $request): View
    {
        return view('auth.verify-phone', [
            'hasChallenge' => $request->session()->has('registration_verification_token'),
            'maskedDestination' => $request->session()->get('registration_masked_destination'),
            'otpExpiresAt' => $request->session()->get('registration_otp_expires_at'),
            'resendAvailableAt' => $request->session()->get('registration_resend_available_at'),
        ]);
    }

    public function requestPhoneVerification(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string'],
        ]);

        try {
            $response = $this->apiClient->requestOtp(
                $request->string('email')->trim()->lower()->toString(),
                $request->string('password')->toString(),
            );
        } catch (ConnectionException) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['verification' => 'No pudimos conectar con el servicio de verificación.']);
        }

        if (! $response->successful()) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['verification' => $this->otpErrorMessage(
                    (string) data_get($response->json(), 'error.code'),
                    $response->status(),
                )]);
        }

        if (! $this->storeVerificationChallenge($request, $response->json('data'))) {
            return back()->withErrors(['verification' => 'El servicio respondió con un formato no reconocido.']);
        }

        return redirect()->route('verification.phone')->with('success', 'Se envió un nuevo código de verificación.');
    }

    public function verifyPhoneOtp(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $token = $request->session()->get('registration_verification_token');
        if (! is_string($token) || $token === '') {
            return redirect()->route('verification.phone')
                ->withErrors(['verification' => 'La solicitud expiró. Inicia nuevamente la verificación.']);
        }

        try {
            $response = $this->apiClient->verifyOtp($token, $request->string('code')->toString());
        } catch (ConnectionException) {
            return back()->withErrors(['verification' => 'No pudimos conectar con el servicio de verificación.']);
        }

        if (! $response->successful() || data_get($response->json(), 'data.verified') !== true) {
            return back()->withErrors(['verification' => $this->otpErrorMessage(
                (string) data_get($response->json(), 'error.code'),
                $response->status(),
            )]);
        }

        $this->forgetVerificationChallenge($request);

        return redirect()->route('login')->with('success', 'Teléfono verificado. Ya puedes iniciar sesión.');
    }

    public function resendPhoneOtp(Request $request): RedirectResponse
    {
        $token = $request->session()->get('registration_verification_token');
        if (! is_string($token) || $token === '') {
            return redirect()->route('verification.phone')
                ->withErrors(['verification' => 'La solicitud expiró. Inicia nuevamente la verificación.']);
        }

        try {
            $response = $this->apiClient->resendOtp($token);
        } catch (ConnectionException) {
            return back()->withErrors(['verification' => 'No pudimos reenviar el código. Intenta nuevamente.']);
        }

        if (! $response->successful()) {
            return back()->withErrors(['verification' => $this->otpErrorMessage(
                (string) data_get($response->json(), 'error.code'),
                $response->status(),
            )]);
        }

        if (! $this->storeVerificationChallenge($request, $response->json('data'))) {
            return back()->withErrors(['verification' => 'El servicio respondió con un formato no reconocido.']);
        }

        return back()->with('success', 'Se emitió un nuevo código. El anterior dejó de ser válido.');
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
            'PHONE_VERIFICATION_REQUIRED' => 'Debes verificar tu teléfono antes de iniciar sesión.',
            default => $status >= 500
                ? 'El servicio de acceso presentó un error. Intenta nuevamente.'
                : 'No fue posible iniciar sesión con los datos proporcionados.',
        };
    }

    private function registrationErrorMessage(string $code, int $status): string
    {
        return match ($code) {
            'EMAIL_ALREADY_REGISTERED' => 'El correo ya está registrado.',
            'DOCUMENT_ALREADY_REGISTERED' => 'El documento ya está registrado.',
            'OTP_PROVIDER_UNAVAILABLE', 'OTP_DEMO_NOT_ALLOWED' =>
                'La cuenta no pudo crearse porque la entrega OTP no está disponible. Intenta nuevamente más tarde.',
            'VALIDATION_ERROR' => 'Revisa los datos ingresados e intenta nuevamente.',
            default => $status >= 500
                ? 'El servicio de registro presentó un error. Intenta nuevamente.'
                : 'No fue posible completar el registro.',
        };
    }

    private function otpErrorMessage(string $code, int $status): string
    {
        return match ($code) {
            'OTP_INVALID_OR_EXPIRED' => 'El código no es válido, expiró o alcanzó el límite de intentos.',
            'OTP_FORMAT_INVALID' => 'Ingresa los seis dígitos del código.',
            'OTP_RESEND_TOO_SOON' => 'Espera al menos 60 segundos antes de solicitar otro código.',
            'OTP_HOURLY_LIMIT_REACHED', 'TOO_MANY_ATTEMPTS' =>
                'Se alcanzó el límite temporal de solicitudes. Intenta más tarde.',
            'INVALID_VERIFICATION_CHALLENGE' => 'La solicitud expiró. Inicia nuevamente la verificación.',
            'INVALID_VERIFICATION_CREDENTIALS' => 'No fue posible iniciar la verificación con esos datos.',
            'PHONE_ALREADY_VERIFIED' => 'El teléfono ya fue verificado. Puedes iniciar sesión.',
            'OTP_PROVIDER_UNAVAILABLE', 'OTP_DEMO_NOT_ALLOWED' => 'La entrega del código no está disponible.',
            default => $status >= 500
                ? 'El servicio de verificación presentó un error. Intenta nuevamente.'
                : 'No fue posible procesar la verificación.',
        };
    }

    private function storeVerificationChallenge(Request $request, mixed $data): bool
    {
        $token = data_get($data, 'verificationToken');
        if (! is_string($token) || $token === '') {
            return false;
        }
        $request->session()->put([
            'registration_verification_token' => $token,
            'registration_masked_destination' => data_get($data, 'maskedDestination'),
            'registration_otp_expires_at' => data_get($data, 'otpExpiresAt'),
            'registration_resend_available_at' => data_get($data, 'resendAvailableAt'),
            'registration_verification_expires_at' => data_get($data, 'verificationExpiresAt'),
        ]);

        return true;
    }

    private function forgetVerificationChallenge(Request $request): void
    {
        $request->session()->forget([
            'registration_verification_token',
            'registration_masked_destination',
            'registration_otp_expires_at',
            'registration_resend_available_at',
            'registration_verification_expires_at',
        ]);
    }
}
