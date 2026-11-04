<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        $isDemoEnvironment = app()->environment(['local', 'demo']);

        return view('auth.login', [
            'backendAvailable' => false,
            'demoEnabled' => $isDemoEnvironment && config('clinic.demo.enabled'),
            'demoUsers' => $isDemoEnvironment ? config('clinic.demo.users', []) : [],
            'demoPassword' => $isDemoEnvironment ? config('clinic.demo.password') : null,
            'googleEnabled' => false,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string'],
        ]);

        return back()
            ->withInput($request->only('email'))
            ->withErrors([
                'authentication' => 'El endpoint de autenticación de Quarkus todavía no está implementado. No se enviaron ni almacenaron las credenciales.',
            ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'La sesión local se cerró correctamente.');
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
}
