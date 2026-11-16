<?php

namespace App\Http\Middleware;

use App\Exceptions\QuarkusApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReceptionResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() === 503 && ! $response->isRedirection()) {
            $response = self::error(503, 'No fue posible validar la sesión o consultar el servicio. Tu sesión se conserva. Reintenta en unos momentos.');
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
            $response = back()->withInput(self::safeInput($request))
                ->withErrors(['api' => $exception->getMessage()])->with('api_status', $exception->status);
        } else {
            $response = self::error($exception->status, $exception->getMessage());
        }

        return self::privateResponse($response);
    }

    public static function safeInput(Request $request): array
    {
        // An allow-list also excludes QR credentials smuggled into other forms.
        return $request->only(['q', 'codPac', 'codEsp', 'codMed', 'fecha', 'desde', 'hasta', 'estado', 'page', 'size', 'motivo']);
    }

    public static function error(int $status, string $message): Response
    {
        return response()->view('secretaria.error', compact('status', 'message'), $status);
    }

    public static function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        if ($response->getStatusCode() === 503) {
            $response->headers->set('Retry-After', '30');
        }

        return $response;
    }
}
