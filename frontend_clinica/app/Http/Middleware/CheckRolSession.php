<?php

namespace App\Http\Middleware;

use App\Exceptions\AuthDomainException;
use App\Models\Usuario;
use App\Services\Auth\AccountStatusService;
use App\Services\Auth\SessionService;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpFoundation\Response;

final class CheckRolSession
{
    public function __construct(
        private AuthFactory $auth,
        private AccountStatusService $status,
        private SessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $user = $this->auth->guard('web')->user();
        if (! $user instanceof Usuario || $request->session()->get('auth_authority') !== 'laravel'
            || $request->session()->get('cod_usu') !== $user->cod_usu) {
            return $this->invalid($request, 'Debes iniciar sesión para continuar.');
        }
        $expiresAt = $request->session()->get('auth_expires_at');
        if (! is_string($expiresAt) || now()->greaterThanOrEqualTo($expiresAt)) {
            return $this->invalid($request, 'Tu sesión expiró. Inicia sesión nuevamente.');
        }
        try {
            $account = $this->status->requireActive($user->cod_usu);
        } catch (AuthDomainException) {
            $this->sessions->revokeAllForUser($user->cod_usu);

            return $this->invalid($request, 'Tu cuenta o perfil ya no está habilitado.');
        } catch (QueryException|PDOException) {
            return response(
                'No fue posible validar la sesión con la base clínica. Tu sesión se conserva; reintenta en unos momentos.',
                503,
            )->header('Retry-After', '30');
        }
        $request->session()->put([
            'cod_rol' => $account->cod_rol,
            'nombre_usuario' => $account->nombre_completo,
            'email_usuario' => $account->email,
            'nombre_rol' => $account->nombre_rol,
            'rol_slug' => SessionService::roleSlug($account->cod_rol),
            'estado_cuenta' => $account->perfil_estado,
        ]);
        if ($allowedRoles !== [] && ! in_array($account->cod_rol, $allowedRoles, true)) {
            abort(403, 'Acceso denegado: tu rol no tiene permisos para este módulo.');
        }

        $response = $next($request);
        if (! $response->isRedirection() && ! $response->headers->hasCacheControlDirective('no-store')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    private function invalid(Request $request, string $message): Response
    {
        $this->sessions->logout($request);

        return redirect()->route('login')->withErrors(['authentication' => $message]);
    }
}
