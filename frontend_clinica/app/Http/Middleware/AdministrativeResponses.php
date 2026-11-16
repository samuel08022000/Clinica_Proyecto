<?php

namespace App\Http\Middleware;

use App\Exceptions\QuarkusApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdministrativeResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The existing role middleware fails closed during an authorization outage.
        if ($response->getStatusCode() === 503 && ! $response->isRedirection()) {
            $response = response()->view('administracion.error', [
                'status' => 503,
                'message' => 'No fue posible validar la sesión o consultar el servicio. Tu sesión se conserva; reintenta en unos momentos.',
            ], 503)->header('Retry-After', '30');
        }

        return self::privateResponse($response);
    }

    public static function renderFailure(Request $request, QuarkusApiException $exception): Response
    {
        if ($exception->status === 401) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $response = redirect()->route('login')->withErrors(['authentication' => $exception->getMessage()]);
        } elseif (! $request->isMethod('GET') && ! in_array($exception->status, [403, 404, 423], true)) {
            $response = back()->withInput($request->except(['password', 'password_confirmation', '_token', '_method']))
                ->withErrors(['api' => $exception->getMessage()])->with('api_status', $exception->status);
        } else {
            $response = response()->view('administracion.error', [
                'status' => $exception->status, 'message' => $exception->getMessage(),
            ], $exception->status);
        }

        return self::privateResponse($response);
    }

    private static function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
