<?php

namespace App\Http\Middleware;

use App\Exceptions\ClinicalOperationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PatientResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() === 503 && ! $response->isRedirection()) {
            $response = self::errorResponse(
                $request,
                503,
                'SERVICIO_NO_DISPONIBLE',
                'No fue posible validar la sesión o consultar la información clínica. Tu sesión se conserva; reintenta en unos momentos.',
            );
        }

        return self::privateResponse($response);
    }

    public static function renderFailure(Request $request, ClinicalOperationException $exception): Response
    {
        if ($exception->status === 401) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $response = $request->expectsJson()
                ? response()->json(['error' => $exception->errorCode, 'message' => $exception->getMessage()], 401)
                : redirect()->route('login')->withErrors(['authentication' => $exception->getMessage()]);
        } elseif ($request->expectsJson()) {
            $response = response()->json([
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->status);
        } elseif (! $request->isMethod('GET') && ! in_array($exception->status, [403, 404, 423], true)) {
            $response = back()->withInput(self::safeInput($request))
                ->withErrors(['api' => $exception->getMessage()])->with('api_status', $exception->status);
        } else {
            $response = self::errorResponse($request, $exception->status, $exception->errorCode, $exception->getMessage());
        }

        return self::privateResponse($response);
    }

    public static function safeInput(Request $request): array
    {
        // Lista blanca: excluye motivos clínicos y credenciales QR de la sesión flash.
        return $request->only([
            'codEsp', 'codMed', 'codSlot', 'fecha', 'desde', 'hasta', 'estado', 'alcance', 'page', 'size',
        ]);
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

    private static function errorResponse(Request $request, int $status, string $errorCode, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => $errorCode, 'message' => $message], $status);
        }
        if (view()->exists('paciente.error')) {
            return response()->view('paciente.error', [
                'status' => $status,
                'errorCode' => $errorCode,
                'message' => $message,
            ], $status);
        }

        return response($message, $status);
    }
}
