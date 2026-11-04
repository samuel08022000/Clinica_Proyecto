<?php

namespace App\Http\Middleware;

use App\Services\QuarkusApiClient;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRolSession
{
    public function __construct(private readonly QuarkusApiClient $apiClient) {}

    public function handle(Request $request, Closure $next, string ...$rolesPermitidos): Response
    {
        if (! $request->session()->has(['auth_token', 'cod_usu', 'cod_rol'])) {
            return redirect()->route('login')->withErrors(['authentication' => 'Debes iniciar sesión para continuar.']);
        }

        try {
            $response = empty($rolesPermitidos)
                ? $this->apiClient->authenticatedUser()
                : $this->apiClient->authorizeRoles($rolesPermitidos);
        } catch (ConnectionException) {
            return $this->endInvalidSession(
                $request,
                'No fue posible validar tu sesión porque el servicio de acceso no está disponible.'
            );
        }

        if ($response->status() === 403
            && data_get($response->json(), 'error.code') === 'FORBIDDEN') {
            abort(403, 'Acceso denegado: tu rol no tiene permisos para este módulo.');
        }

        if (! $response->successful()) {
            return $this->endInvalidSession(
                $request,
                $response->status() === 401
                    ? 'Tu sesión expiró o fue revocada. Inicia sesión nuevamente.'
                    : 'Tu cuenta ya no está habilitada para acceder.'
            );
        }

        $payload = $response->json('data');
        $codUsu = data_get($payload, 'user.id');
        $rolActual = data_get($payload, 'user.role.code');

        if (! is_string($codUsu) || ! is_string($rolActual)
            || $codUsu !== $request->session()->get('cod_usu')) {
            return $this->endInvalidSession($request, 'La sesión no pudo validarse de forma segura.');
        }

        $request->session()->put([
            'cod_rol' => $rolActual,
            'nombre_usuario' => data_get($payload, 'user.name'),
            'email_usuario' => data_get($payload, 'user.email'),
            'nombre_rol' => data_get($payload, 'user.role.name'),
            'rol_slug' => data_get($payload, 'user.role.slug'),
            'estado_cuenta' => data_get($payload, 'user.accountStatus'),
            'auth_expires_at' => data_get($payload, 'expiresAt'),
        ]);

        if (! empty($rolesPermitidos) && ! in_array($rolActual, $rolesPermitidos, true)) {
            abort(403, 'Acceso denegado: tu rol no tiene permisos para este módulo.');
        }

        return $next($request);
    }

    private function endInvalidSession(Request $request, string $message): Response
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['authentication' => $message]);
    }
}
