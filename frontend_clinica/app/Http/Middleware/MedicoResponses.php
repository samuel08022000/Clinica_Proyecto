<?php

namespace App\Http\Middleware;

use App\Exceptions\ClinicalOperationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MedicoResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::privateResponse($next($request));
    }

    public static function renderFailure(Request $request, ClinicalOperationException $exception): Response
    {
        if ($exception->status === 401) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $response = redirect()->route('login')->withErrors(['authentication' => $exception->getMessage()]);
        } elseif (! $request->isMethod('GET') && ! in_array($exception->status, [403, 404], true)) {
            $response = back()->withInput(self::safeInput($request))
                ->withErrors(['api' => $exception->getMessage()])->with('api_status', $exception->status);
        } else {
            $response = response()->view('medico.error', [
                'status' => $exception->status,
                'message' => $exception->getMessage(),
            ], $exception->status);
        }

        return self::privateResponse($response);
    }

    public static function safeInput(Request $request): array
    {
        // No se reinyectan resultados ni motivos clínicos en la sesión flash.
        return $request->only([
            'fecha', 'desde', 'hasta', 'estado', 'page', 'size',
            'codEspDestino', 'codMedDestino', 'tipoAnalisis',
        ]);
    }

    public static function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
