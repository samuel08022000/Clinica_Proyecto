<?php

namespace App\Http\Controllers;

use App\Services\QuarkusApiClient;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        return view('auth.login', [
            'googleEnabled' => (bool) config('clinic.features.google_oauth'),
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
            if ($errorCode === 'EMAIL_VERIFICATION_REQUIRED') {
                return redirect()
                    ->route('verification.email')
                    ->withInput($request->only('email'))
                    ->withErrors(['verification' => 'Debes verificar tu correo antes de iniciar sesión.']);
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

        $this->establishSession($request, $payload);

        $linkToken = $request->session()->pull('google_link_token');
        if (is_string($linkToken) && $linkToken !== '' && $codRol === 'ROL001') {
            try {
                $linkResponse = $this->apiClient->linkGoogleAccount($linkToken);
                if (! $linkResponse->successful()) {
                    return redirect()->route(self::ROLE_HOME_ROUTES[$codRol])
                        ->with('warning', 'La sesión se inició, pero no fue posible vincular la cuenta de Google.');
                }
            } catch (ConnectionException) {
                return redirect()->route(self::ROLE_HOME_ROUTES[$codRol])
                    ->with('warning', 'La sesión se inició, pero el servicio de vinculación no respondió.');
            }
        }

        return redirect()
            ->route(self::ROLE_HOME_ROUTES[$codRol])
            ->with('success', 'Sesión iniciada correctamente.');
    }

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        return $this->beginGoogleFlow($request, 'login');
    }

    public function registerWithGoogle(Request $request): RedirectResponse
    {
        return $this->beginGoogleFlow($request, 'register');
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        $flow = $request->session()->pull('google_oauth_flow');
        if (! is_array($flow) || (int) ($flow['expires_at'] ?? 0) < time()) {
            return redirect()->route('login')->withErrors([
                'authentication' => 'La solicitud con Google expiró. Inicia el proceso nuevamente.',
            ]);
        }
        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors([
                'authentication' => 'El acceso con Google fue cancelado o rechazado.',
            ]);
        }
        $state = $request->string('state')->toString();
        if ($state === '' || ! hash_equals((string) ($flow['state'] ?? ''), $state)) {
            return redirect()->route('login')->withErrors([
                'authentication' => 'No fue posible validar la seguridad de la respuesta de Google.',
            ]);
        }
        if (! $request->filled('code')) {
            return redirect()->route('login')->withErrors([
                'authentication' => 'Google no devolvió un código de autorización.',
            ]);
        }

        try {
            $response = $this->apiClient->exchangeGoogleCode([
                'code' => $request->string('code')->toString(),
                'codeVerifier' => (string) ($flow['code_verifier'] ?? ''),
                'nonce' => (string) ($flow['nonce'] ?? ''),
                'redirectUri' => (string) config('clinic.features.google_redirect_uri'),
            ]);
        } catch (ConnectionException) {
            return redirect()->route('login')->withErrors([
                'authentication' => 'No pudimos conectar con el servicio de acceso con Google.',
            ]);
        }
        if (! $response->successful()) {
            return redirect()->route('login')->withErrors([
                'authentication' => $this->googleErrorMessage((string) data_get($response->json(), 'error.code')),
            ]);
        }

        $data = $response->json('data');

        return match (data_get($data, 'status')) {
            'AUTHENTICATED' => $this->finishGoogleLogin($request, data_get($data, 'login')),
            'REGISTRATION_REQUIRED' => $this->continueGoogleRegistration($request, $data),
            'LINK_REQUIRED' => $this->requireLocalLoginForGoogleLink($request, $data),
            'EMAIL_VERIFICATION_REQUIRED' => $this->continueGoogleEmailVerification($request, $data),
            default => redirect()->route('login')->withErrors([
                'authentication' => 'El servicio respondió con un formato no reconocido.',
            ]),
        };
    }

    public function showGoogleRegistration(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('google_registration_token')) {
            return redirect()->route('register')->withErrors([
                'registration' => 'La continuación del registro con Google expiró.',
            ]);
        }

        return view('auth.google-register', [
            'googleName' => $request->session()->get('google_registration_name'),
            'googleEmail' => $request->session()->get('google_registration_email'),
        ]);
    }

    public function completeGoogleRegistration(Request $request): RedirectResponse
    {
        $request->validate([
            'ci' => ['required', 'string', 'min:4', 'max:30'],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:today'],
            'telefono' => ['required', 'string', 'max:25', 'regex:/^\\+?[0-9 ()-]{8,25}$/'],
            'ciudad_residencia' => ['required', 'string', 'min:3', 'max:100'],
        ]);
        $token = $request->session()->get('google_registration_token');
        if (! is_string($token) || $token === '') {
            return redirect()->route('register')->withErrors([
                'registration' => 'La continuación del registro con Google expiró.',
            ]);
        }
        try {
            $response = $this->apiClient->registerGooglePatient([
                'continuationToken' => $token,
                'ci' => $request->string('ci')->trim()->toString(),
                'fechaNacimiento' => $request->date('fecha_nacimiento')?->toDateString(),
                'telefono' => $request->string('telefono')->trim()->toString(),
                'ciudadResidencia' => $request->string('ciudad_residencia')->trim()->toString(),
            ]);
        } catch (ConnectionException) {
            return back()->withInput()->withErrors([
                'registration' => 'No pudimos conectar con el servicio de registro.',
            ]);
        }
        if (! $response->successful()) {
            return back()->withInput()->withErrors([
                'registration' => $this->registrationErrorMessage(
                    (string) data_get($response->json(), 'error.code'),
                    $response->status(),
                ),
            ]);
        }
        $request->session()->forget([
            'google_registration_token',
            'google_registration_name',
            'google_registration_email',
        ]);
        $data = $response->json('data');
        if (data_get($data, 'status') === 'AUTHENTICATED') {
            return $this->finishGoogleLogin($request, data_get($data, 'login'));
        }
        if (data_get($data, 'status') === 'EMAIL_VERIFICATION_REQUIRED') {
            return $this->continueGoogleEmailVerification($request, $data);
        }

        return redirect()->route('login')->withErrors([
            'authentication' => 'El servicio respondió con un formato no reconocido.',
        ]);
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
            'googleEnabled' => (bool) config('clinic.features.google_oauth'),
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

        $startedAt = hrtime(true);
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
        } catch (ConnectionException $exception) {
            $previous = $exception->getPrevious();
            $context = $previous !== null && is_callable([$previous, 'getHandlerContext'])
                ? $previous->getHandlerContext() : [];
            $isTimeout = $previous instanceof NetworkTimeoutException
                || $previous instanceof ConnectTimeoutException
                || $previous instanceof ResponseTimeoutException
                || (int) ($context['errno'] ?? 0) === 28;
            $errno = $isTimeout ? 28 : (int) ($context['errno'] ?? 0);
            Log::warning('Registration transport failure', [
                'transport_errno' => $errno,
                'elapsed_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ]);

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['registration' => $errno === 28
                    ? 'El servicio de registro tardó demasiado en responder. Comprueba tu correo antes de volver a intentarlo.'
                    : 'No pudimos conectar con el servicio de registro. Intenta nuevamente.']);
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
            ->route('verification.email')
            ->with('success', $this->verificationIssuedMessage($response->json('data.verification')));
    }

    public function showEmailOtpVerification(Request $request): View
    {
        return view('auth.verify-email', [
            'hasChallenge' => $request->session()->has('registration_verification_token'),
            'maskedDestination' => $request->session()->get('registration_masked_destination'),
            'otpExpiresAt' => $request->session()->get('registration_otp_expires_at'),
            'resendAvailableAt' => $request->session()->get('registration_resend_available_at'),
            'deliveryMode' => $request->session()->get('registration_delivery_mode'),
            'deliveryConfirmed' => $request->session()->get('registration_delivery_confirmed', false),
        ]);
    }

    public function requestEmailVerification(Request $request): RedirectResponse
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

        return redirect()
            ->route('verification.email')
            ->with('success', $this->verificationIssuedMessage($response->json('data')));
    }

    public function verifyEmailOtp(Request $request): RedirectResponse
    {
        if (! $request->filled('code') && is_array($request->input('code_digits'))) {
            $request->merge(['code' => implode('', $request->input('code_digits'))]);
        }
        $request->validate(['code' => ['required', 'digits:6']]);
        $token = $request->session()->get('registration_verification_token');
        if (! is_string($token) || $token === '') {
            return redirect()->route('verification.email')
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

        return redirect()->route('login')->with('success', 'Correo verificado. Ya puedes iniciar sesión.');
    }

    public function resendEmailOtp(Request $request): RedirectResponse
    {
        $token = $request->session()->get('registration_verification_token');
        if (! is_string($token) || $token === '') {
            return redirect()->route('verification.email')
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

        return back()->with('success', $this->verificationIssuedMessage($response->json('data')));
    }

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password', ['recoveryAvailable' => true]);
    }

    public function requestPasswordReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        try {
            $response = $this->apiClient->requestPasswordReset(
                $request->string('email')->trim()->lower()->toString(),
            );
        } catch (ConnectionException) {
            return back()->withInput($request->only('email'))->withErrors([
                'recovery' => 'No pudimos conectar con el servicio de recuperación.',
            ]);
        }
        if (! $response->successful()) {
            return back()->withInput($request->only('email'))->withErrors([
                'recovery' => $response->status() === 429
                    ? 'Espera antes de solicitar otro enlace de recuperación.'
                    : 'El servicio de recuperación no está disponible. Intenta nuevamente.',
            ]);
        }

        return back()->with('success', (string) data_get(
            $response->json(),
            'data.message',
            'Si la cuenta es elegible, recibirás instrucciones para restablecer la contraseña.',
        ));
    }

    public function showResetPassword(Request $request, ?string $token = null): View
    {
        return view('auth.reset-password', [
            'token' => $token ?: $request->string('token')->toString(),
            'email' => $request->string('email')->toString(),
            'resetAvailable' => true,
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'max:72', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        try {
            $response = $this->apiClient->resetPassword(
                $request->string('email')->trim()->lower()->toString(),
                $request->string('token')->toString(),
                $request->string('password')->toString(),
            );
        } catch (ConnectionException) {
            return back()->withInput($request->only(['email', 'token']))->withErrors([
                'reset' => 'No pudimos conectar con el servicio de recuperación.',
            ]);
        }
        if (! $response->successful()) {
            return back()->withInput($request->only(['email', 'token']))->withErrors([
                'reset' => 'El enlace no es válido, ya fue utilizado o expiró.',
            ]);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success',
            'Contraseña actualizada. Inicia sesión nuevamente.');
    }

    private function beginGoogleFlow(Request $request, string $intent): RedirectResponse
    {
        if (! config('clinic.features.google_oauth')) {
            return redirect()->route($intent === 'register' ? 'register' : 'login')->withErrors([
                'authentication' => 'El acceso con Google todavía no está configurado.',
            ]);
        }
        $state = $this->base64Url(random_bytes(32));
        $nonce = $this->base64Url(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(64));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        try {
            $response = $this->apiClient->googleAuthorizationUrl([
                'state' => $state,
                'nonce' => $nonce,
                'codeChallenge' => $challenge,
                'redirectUri' => (string) config('clinic.features.google_redirect_uri'),
            ]);
        } catch (ConnectionException) {
            return redirect()->route($intent === 'register' ? 'register' : 'login')->withErrors([
                'authentication' => 'No pudimos conectar con el servicio de acceso con Google.',
            ]);
        }
        $authorizationUrl = $response->json('data.authorizationUrl');
        if (! $response->successful() || ! is_string($authorizationUrl)
            || ! str_starts_with($authorizationUrl, 'https://accounts.google.com/')) {
            return redirect()->route($intent === 'register' ? 'register' : 'login')->withErrors([
                'authentication' => $this->googleErrorMessage((string) data_get($response->json(), 'error.code')),
            ]);
        }
        $request->session()->put('google_oauth_flow', [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $verifier,
            'intent' => $intent,
            'expires_at' => time() + 600,
        ]);

        return redirect()->away($authorizationUrl);
    }

    private function finishGoogleLogin(Request $request, mixed $payload): RedirectResponse
    {
        $codRol = data_get($payload, 'user.role.code');
        $token = data_get($payload, 'session.accessToken');
        if (! is_array($payload) || $codRol !== 'ROL001' || ! is_string($token) || $token === '') {
            return redirect()->route('login')->withErrors([
                'authentication' => 'El servicio respondió con un formato no reconocido.',
            ]);
        }
        $this->establishSession($request, $payload);

        return redirect()->route('paciente.home')->with('success', 'Sesión iniciada con Google.');
    }

    private function continueGoogleRegistration(Request $request, mixed $data): RedirectResponse
    {
        $token = data_get($data, 'continuationToken');
        if (! is_string($token) || $token === '') {
            return redirect()->route('register')->withErrors([
                'registration' => 'La continuación del registro con Google no es válida.',
            ]);
        }
        $request->session()->put([
            'google_registration_token' => $token,
            'google_registration_name' => data_get($data, 'suggestedName'),
            'google_registration_email' => data_get($data, 'email'),
        ]);

        return redirect()->route('google.registration');
    }

    private function requireLocalLoginForGoogleLink(Request $request, mixed $data): RedirectResponse
    {
        $token = data_get($data, 'continuationToken');
        if (! is_string($token) || $token === '') {
            return redirect()->route('login')->withErrors([
                'authentication' => 'No fue posible preparar la vinculación con Google.',
            ]);
        }
        $request->session()->put('google_link_token', $token);

        return redirect()->route('login')->with('warning',
            'Ese correo ya tiene una cuenta. Inicia sesión con tu contraseña para confirmar la vinculación con Google.');
    }

    private function continueGoogleEmailVerification(Request $request, mixed $data): RedirectResponse
    {
        if (! $this->storeVerificationChallenge($request, data_get($data, 'verification'))) {
            return redirect()->route('login')->withErrors([
                'verification' => 'No fue posible iniciar la verificación del correo.',
            ]);
        }

        return redirect()->route('verification.email')
            ->with('success', $this->verificationIssuedMessage(data_get($data, 'verification')));
    }

    private function establishSession(Request $request, mixed $payload): void
    {
        $request->session()->regenerate();
        $request->session()->put([
            'auth_token' => data_get($payload, 'session.accessToken'),
            'auth_expires_at' => data_get($payload, 'session.expiresAt'),
            'cod_usu' => data_get($payload, 'user.id'),
            'nombre_usuario' => data_get($payload, 'user.name'),
            'email_usuario' => data_get($payload, 'user.email'),
            'cod_rol' => data_get($payload, 'user.role.code'),
            'nombre_rol' => data_get($payload, 'user.role.name'),
            'rol_slug' => data_get($payload, 'user.role.slug'),
            'estado_cuenta' => data_get($payload, 'user.accountStatus'),
        ]);
    }

    private function googleErrorMessage(string $code): string
    {
        return match ($code) {
            'GOOGLE_OAUTH_NOT_CONFIGURED' => 'El acceso con Google todavía no está configurado.',
            'GOOGLE_AUTHENTICATION_FAILED', 'GOOGLE_ID_TOKEN_INVALID' => 'Google no pudo validar la autorización. Inicia el proceso nuevamente.',
            'GOOGLE_PROVIDER_UNAVAILABLE' => 'Google no está disponible temporalmente.',
            default => 'No fue posible continuar con Google.',
        };
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
            'ACCOUNT_DISABLED', 'PROFILE_DISABLED', 'ROLE_DISABLED', 'PROFILE_UNAVAILABLE' => 'La cuenta no está habilitada. Contacta con la administración de la clínica.',
            'ROLE_NOT_ALLOWED' => 'La cuenta no tiene un rol autorizado para este sistema.',
            'EMAIL_VERIFICATION_REQUIRED' => 'Debes verificar tu correo antes de iniciar sesión.',
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
            'GOOGLE_ACCOUNT_CONFLICT' => 'No fue posible completar el registro con esa identidad de Google.',
            'OTP_PROVIDER_UNAVAILABLE', 'OTP_DEMO_NOT_ALLOWED', 'OTP_SMTP_NOT_CONFIGURED', 'OTP_DELIVERY_FAILED' => 'La cuenta no pudo crearse porque la entrega OTP no está disponible. Intenta nuevamente más tarde.',
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
            'OTP_HOURLY_LIMIT_REACHED', 'TOO_MANY_ATTEMPTS' => 'Se alcanzó el límite temporal de solicitudes. Intenta más tarde.',
            'INVALID_VERIFICATION_CHALLENGE' => 'La solicitud expiró. Inicia nuevamente la verificación.',
            'INVALID_VERIFICATION_CREDENTIALS' => 'No fue posible iniciar la verificación con esos datos.',
            'EMAIL_ALREADY_VERIFIED' => 'El correo ya fue verificado. Puedes iniciar sesión.',
            'OTP_PROVIDER_UNAVAILABLE', 'OTP_DEMO_NOT_ALLOWED', 'OTP_SMTP_NOT_CONFIGURED', 'OTP_DELIVERY_FAILED' => 'La entrega del código por correo no está disponible.',
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
            'registration_delivery_mode' => data_get($data, 'deliveryMode'),
            'registration_delivery_confirmed' => data_get($data, 'deliveryConfirmed') === true,
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
            'registration_delivery_mode',
            'registration_delivery_confirmed',
        ]);
    }

    private function verificationIssuedMessage(mixed $data): string
    {
        if (strtoupper((string) data_get($data, 'deliveryMode')) === 'DEMO') {
            return 'Código DEMO generado localmente. No se envió ningún correo real.';
        }

        return data_get($data, 'deliveryConfirmed') === true
            ? 'Correo de verificación entregado al proveedor SMTP.'
            : 'Código de verificación generado.';
    }
}
