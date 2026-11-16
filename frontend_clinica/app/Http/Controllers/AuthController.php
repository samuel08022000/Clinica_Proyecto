<?php

namespace App\Http\Controllers;

use App\Exceptions\AuthDomainException;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\GoogleAuthService;
use App\Services\Auth\OtpService;
use App\Services\Auth\PasswordRecoveryService;
use App\Services\Auth\PatientRegistrationService;
use App\Services\Auth\SessionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class AuthController extends Controller
{
    private const ROLE_HOME_ROUTES = [
        'ROL001' => 'paciente.home', 'ROL002' => 'secretaria.home',
        'ROL003' => 'medico.home', 'ROL004' => 'administracion.home',
    ];

    public function __construct(
        private AuthenticationService $authentication,
        private PatientRegistrationService $registration,
        private OtpService $otp,
        private GoogleAuthService $google,
        private PasswordRecoveryService $recovery,
        private SessionService $sessions,
    ) {}

    public function showLogin(Request $request): View|RedirectResponse
    {
        if ($request->user() && $request->session()->get('auth_authority') === 'laravel') {
            return redirect()->route($this->homeRouteForRole((string) $request->session()->get('cod_rol')));
        }

        return view('auth.login', ['googleEnabled' => $this->google->available()]);
    }

    public function login(Request $request): RedirectResponse
    {
        $valid = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'password' => ['required', 'string']]);
        try {
            $account = $this->authentication->login($request, $valid['email'], $valid['password']);
            $linkToken = $request->session()->pull('google_link_token');
            if (is_string($linkToken) && $linkToken !== '') {
                try {
                    $this->google->linkAuthenticated($account->cod_usu, $linkToken);
                } catch (AuthDomainException) {
                    return redirect()->route($this->homeRouteForRole($account->cod_rol))
                        ->with('warning', 'La sesión se inició, pero no fue posible vincular la cuenta de Google.');
                }
            }

            return redirect()->route($this->homeRouteForRole($account->cod_rol))->with('success', 'Sesión iniciada correctamente.');
        } catch (AuthDomainException $error) {
            if ($error->errorCode === 'EMAIL_VERIFICATION_REQUIRED') {
                return redirect()->route('verification.email')->withInput($request->only('email'))
                    ->withErrors(['verification' => 'Debes verificar tu correo antes de iniciar sesión.']);
            }

            return back()->withInput($request->only('email'))
                ->withErrors(['authentication' => $this->authenticationError($error)]);
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->sessions->logout($request);

        return redirect()->route('home')->with('success', 'La sesión se cerró correctamente.');
    }

    public function dashboard(Request $request): View
    {
        return view('dashboard.home', ['user' => [
            'id' => $request->session()->get('cod_usu'), 'name' => $request->session()->get('nombre_usuario'),
            'email' => $request->session()->get('email_usuario'), 'roleCode' => $request->session()->get('cod_rol'),
            'roleName' => $request->session()->get('nombre_rol'), 'accountStatus' => $request->session()->get('estado_cuenta'),
        ]]);
    }

    public function showRegistration(): View
    {
        return view('auth.register', ['registrationAvailable' => true, 'otpAvailable' => true, 'googleEnabled' => $this->google->available()]);
    }

    public function register(Request $request): RedirectResponse
    {
        $request->validate($this->registrationRules());
        try {
            $verification = $this->registration->register($request->only([
                'ci', 'nombre_completo', 'fecha_nacimiento', 'telefono', 'email', 'ciudad_residencia', 'password',
            ]));
            $this->storeVerificationChallenge($request, $verification);

            return redirect()->route('verification.email')->with('success', $this->verificationIssuedMessage());
        } catch (AuthDomainException $error) {
            return back()->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['registration' => $this->registrationError($error)]);
        }
    }

    public function showEmailOtpVerification(Request $request): View
    {
        return view('auth.verify-email', [
            'hasChallenge' => $request->session()->has('registration_verification_token'),
            'maskedDestination' => $request->session()->get('registration_masked_destination'),
            'otpExpiresAt' => $request->session()->get('registration_otp_expires_at'),
            'resendAvailableAt' => $request->session()->get('registration_resend_available_at'),
            'deliveryMode' => $request->session()->get('registration_delivery_mode'),
            'deliveryConfirmed' => false,
        ]);
    }

    public function requestEmailVerification(Request $request): RedirectResponse
    {
        $valid = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'password' => ['required', 'string']]);
        try {
            $data = $this->otp->request($valid['email'], $valid['password'], $request->ip() ?: 'unknown');
            $this->storeVerificationChallenge($request, $data);

            return redirect()->route('verification.email')->with('success', $this->verificationIssuedMessage());
        } catch (AuthDomainException $error) {
            return back()->withInput($request->only('email'))->withErrors(['verification' => $this->otpError($error)]);
        }
    }

    public function verifyEmailOtp(Request $request): RedirectResponse
    {
        if (! $request->filled('code') && is_array($request->input('code_digits'))) {
            $digits = $request->input('code_digits');
            $allScalar = array_reduce($digits, static fn (bool $ok, mixed $d): bool => $ok && is_scalar($d), true);
            $request->merge(['code' => $allScalar ? implode('', array_map('strval', $digits)) : $digits]);
        }
        $valid = $request->validate(['code' => ['required', 'digits:6']]);
        $token = (string) $request->session()->get('registration_verification_token', '');
        try {
            $this->otp->verify($token, $valid['code']);
            $this->forgetVerificationChallenge($request);

            return redirect()->route('login')->with('success', 'Correo verificado. Ya puedes iniciar sesión.');
        } catch (AuthDomainException $error) {
            return back()->withErrors(['verification' => $this->otpError($error)]);
        }
    }

    public function resendEmailOtp(Request $request): RedirectResponse
    {
        try {
            $data = $this->otp->resend((string) $request->session()->get('registration_verification_token', ''));
            $this->storeVerificationChallenge($request, $data);

            return back()->with('success', $this->verificationIssuedMessage());
        } catch (AuthDomainException $error) {
            return back()->withErrors(['verification' => $this->otpError($error)]);
        }
    }

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password', ['recoveryAvailable' => true]);
    }

    public function requestPasswordReset(Request $request): RedirectResponse
    {
        $valid = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        return back()->with('success', $this->recovery->request($valid['email']));
    }

    public function showResetPassword(Request $request, ?string $token = null): View
    {
        return view('auth.reset-password', ['token' => $token ?: $request->string('token')->toString(),
            'email' => $request->string('email')->toString(), 'resetAvailable' => true]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $valid = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'max:72', Password::min(10)->letters()->mixedCase()->numbers()]]);
        try {
            $this->recovery->reset($valid['email'], $valid['token'], $valid['password']);
            $this->sessions->logout($request);

            return redirect()->route('login')->with('success', 'Contraseña actualizada. Inicia sesión nuevamente.');
        } catch (AuthDomainException $error) {
            return back()->withInput($request->only(['email', 'token']))->withErrors(['reset' => $error->getMessage()]);
        }
    }

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        return $this->beginGoogle($request, 'login');
    }

    public function registerWithGoogle(Request $request): RedirectResponse
    {
        return $this->beginGoogle($request, 'register');
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        $flow = $request->session()->pull('google_oauth_flow');
        if (! is_array($flow) || ($flow['expires_at'] ?? 0) < time()) {
            return redirect()->route('login')->withErrors(['authentication' => 'La solicitud con Google expiró. Inicia el proceso nuevamente.']);
        }
        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors(['authentication' => 'El acceso con Google fue cancelado o rechazado.']);
        }
        if (! hash_equals((string) ($flow['state'] ?? ''), $request->string('state')->toString())) {
            return redirect()->route('login')->withErrors(['authentication' => 'No fue posible validar la seguridad de la respuesta de Google.']);
        }
        if (! $request->filled('code')) {
            return redirect()->route('login')->withErrors(['authentication' => 'Google no devolvió un código de autorización.']);
        }
        try {
            $result = $this->google->exchange($request, $request->string('code')->toString(), $flow);

            return match ($result['status']) {
                'AUTHENTICATED' => redirect()->route('paciente.home')->with('success', 'Sesión iniciada con Google.'),
                'REGISTRATION_REQUIRED' => $this->googleRegistration($request, $result),
                'LINK_REQUIRED' => $this->googleLink($request, $result),
                'EMAIL_VERIFICATION_REQUIRED' => $this->googleVerification($request, $result),
                default => throw new AuthDomainException('GOOGLE_AUTHENTICATION_FAILED', 401, 'No fue posible continuar con Google.'),
            };
        } catch (AuthDomainException $error) {
            return redirect()->route('login')->withErrors(['authentication' => $this->googleError($error)]);
        }
    }

    public function showGoogleRegistration(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('google_registration_token')) {
            return redirect()->route('register')->withErrors(['registration' => 'La continuación del registro con Google expiró.']);
        }

        return view('auth.google-register', ['googleName' => $request->session()->get('google_registration_name'),
            'googleEmail' => $request->session()->get('google_registration_email')]);
    }

    public function completeGoogleRegistration(Request $request): RedirectResponse
    {
        $valid = $request->validate(['ci' => ['required', 'string', 'min:4', 'max:30'],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:'.$this->clinicalToday()],
            'telefono' => ['required', 'string', 'max:25', 'regex:/^\+?[0-9 ()-]{8,25}$/'],
            'ciudad_residencia' => ['required', 'string', 'min:3', 'max:100']]);
        $token = (string) $request->session()->pull('google_registration_token', '');
        try {
            $result = $this->google->register($request, $token, $valid);
            if ($result['status'] === 'EMAIL_VERIFICATION_REQUIRED') {
                $this->storeVerificationChallenge($request, $result['verification']);

                return redirect()->route('verification.email')->with('success', $this->verificationIssuedMessage());
            }

            return redirect()->route('paciente.home')->with('success', 'Cuenta creada e inicio de sesión completado con Google.');
        } catch (AuthDomainException $error) {
            return back()->withInput()->withErrors(['registration' => $this->registrationError($error)]);
        }
    }

    private function beginGoogle(Request $request, string $intent): RedirectResponse
    {
        try {
            $state = Str::random(64);
            $nonce = Str::random(64);
            $verifier = Str::random(96);
            $flow = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
                'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'intent' => $intent, 'expires_at' => time() + 600];
            $url = $this->google->authorizationUrl($flow);
            if (! str_starts_with($url, 'https://accounts.google.com/')) {
                throw new AuthDomainException('GOOGLE_AUTHENTICATION_FAILED', 401, 'URL de autorización no válida.');
            }
            $request->session()->put('google_oauth_flow', $flow);

            return redirect()->away($url);
        } catch (AuthDomainException $error) {
            return redirect()->route($intent === 'register' ? 'register' : 'login')->withErrors(['authentication' => $this->googleError($error)]);
        }
    }

    private function googleRegistration(Request $request, array $result): RedirectResponse
    {
        $request->session()->put(['google_registration_token' => $result['continuationToken'],
            'google_registration_name' => $result['suggestedName'], 'google_registration_email' => $result['email']]);

        return redirect()->route('google.registration');
    }

    private function googleLink(Request $request, array $result): RedirectResponse
    {
        $request->session()->put('google_link_token', $result['continuationToken']);

        return redirect()->route('login')->with('warning', 'Ese correo ya tiene una cuenta. Inicia sesión con tu contraseña para confirmar la vinculación con Google.');
    }

    private function googleVerification(Request $request, array $result): RedirectResponse
    {
        $this->storeVerificationChallenge($request, $result['verification']);

        return redirect()->route('verification.email')->with('success', $this->verificationIssuedMessage());
    }

    private function registrationRules(): array
    {
        return ['ci' => ['required', 'string', 'min:4', 'max:30'],
            'nombre_completo' => ['required', 'string', 'min:3', 'max:150', "regex:/^[\pL][\pL .'\-]{1,148}[\pL.]$/u"],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:'.$this->clinicalToday()],
            'telefono' => ['required', 'string', 'max:25', 'regex:/^\+?[0-9 ()-]{8,25}$/'],
            'email' => ['required', 'email:rfc', 'max:254'], 'ciudad_residencia' => ['required', 'string', 'min:3', 'max:100'],
            'password' => ['required', 'confirmed', 'max:72', Password::min(10)->letters()->mixedCase()->numbers()]];
    }

    private function storeVerificationChallenge(Request $request, array $data): void
    {
        $request->session()->put(['registration_verification_token' => $data['verificationToken'],
            'registration_masked_destination' => $data['maskedDestination'], 'registration_otp_expires_at' => $data['otpExpiresAt'],
            'registration_resend_available_at' => $data['resendAvailableAt'], 'registration_verification_expires_at' => $data['verificationExpiresAt'],
            'registration_delivery_mode' => $data['deliveryMode'], 'registration_delivery_confirmed' => false]);
    }

    private function forgetVerificationChallenge(Request $request): void
    {
        $request->session()->forget(['registration_verification_token', 'registration_masked_destination', 'registration_otp_expires_at',
            'registration_resend_available_at', 'registration_verification_expires_at', 'registration_delivery_mode', 'registration_delivery_confirmed']);
    }

    private function verificationIssuedMessage(): string
    {
        return 'Código de verificación aceptado por el transporte de correo configurado.';
    }

    private function clinicalToday(): string
    {
        return now((string) config('database.connections.clinical.timezone', 'America/La_Paz'))->toDateString();
    }

    private function homeRouteForRole(string $role): string
    {
        return self::ROLE_HOME_ROUTES[$role] ?? 'login';
    }

    private function authenticationError(AuthDomainException $e): string
    {
        return match ($e->errorCode) {
            'INVALID_CREDENTIALS' => 'Correo o contraseña incorrectos.', 'TOO_MANY_ATTEMPTS' => 'Se alcanzó el límite de intentos. Espera antes de volver a intentarlo.',
            'ACCOUNT_TEMPORARILY_BLOCKED' => 'La cuenta está bloqueada temporalmente. Contacta con la clínica si necesitas ayuda.',
            'ACCOUNT_DISABLED', 'PROFILE_DISABLED', 'ROLE_DISABLED', 'PROFILE_UNAVAILABLE' => 'La cuenta no está habilitada. Contacta con la administración de la clínica.',
            default => 'No fue posible iniciar sesión con los datos proporcionados.'
        };
    }

    private function registrationError(AuthDomainException $e): string
    {
        return match ($e->errorCode) {
            'EMAIL_ALREADY_REGISTERED' => 'El correo ya está registrado.', 'DOCUMENT_ALREADY_REGISTERED' => 'El documento ya está registrado.',
            'MAIL_TRANSPORT_UNAVAILABLE' => 'La entrega OTP no está configurada. Intenta nuevamente más tarde.',
            'GOOGLE_ACCOUNT_CONFLICT' => 'No fue posible completar el registro con esa identidad de Google.',
            default => $e->status >= 500 ? 'El servicio de registro presentó un error. Intenta nuevamente.' : $e->getMessage()
        };
    }

    private function otpError(AuthDomainException $e): string
    {
        return match ($e->errorCode) {
            'OTP_INVALID_OR_EXPIRED' => 'El código no es válido, expiró o alcanzó el límite de intentos.',
            'OTP_RESEND_TOO_SOON' => 'Espera al menos 60 segundos antes de solicitar otro código.',
            'OTP_HOURLY_LIMIT_REACHED', 'TOO_MANY_ATTEMPTS' => 'Se alcanzó el límite temporal de solicitudes. Intenta más tarde.',
            'INVALID_VERIFICATION_CHALLENGE' => 'La solicitud expiró. Inicia nuevamente la verificación.',
            'INVALID_VERIFICATION_CREDENTIALS' => 'No fue posible iniciar la verificación con esos datos.',
            default => $e->getMessage()
        };
    }

    private function googleError(AuthDomainException $e): string
    {
        return match ($e->errorCode) {
            'GOOGLE_OIDC_LIBRARY_MISSING', 'GOOGLE_OAUTH_NOT_CONFIGURED' => 'El acceso con Google todavía no está configurado.',
            'GOOGLE_ID_TOKEN_INVALID', 'GOOGLE_AUTHENTICATION_FAILED' => 'Google no pudo validar la autorización. Inicia el proceso nuevamente.',
            default => 'No fue posible continuar con Google.'
        };
    }
}
