<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRolSession
{
    /**
     * Valida que el usuario tenga sesión activa y pertenezca a uno de los roles permitidos (ej. ROL001, ROL004).
     */
    public function handle(Request $request, Closure $next, string ...$rolesPermitidos): Response
    {
        if (!session()->has('cod_usu')) {
            return redirect()->route('login')->withErrors(['bd_error' => 'Debe iniciar sesión para continuar.']);
        }

        $rolActual = session('cod_rol');
        if (!empty($rolesPermitidos) && !in_array($rolActual, $rolesPermitidos, true)) {
            abort(403, 'Acceso denegado: Su rol no tiene permisos para este módulo.');
        }

        return $next($request);
    }
}