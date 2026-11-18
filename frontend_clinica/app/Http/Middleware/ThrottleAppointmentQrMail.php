<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** Un intento cada dos minutos por paciente y cita, conservando la pantalla del pase. */
class ThrottleAppointmentQrMail
{
    public static function key(Request $request, string $appointment): string
    {
        return 'appointment-qr:'.$request->user()->getAuthIdentifier().':'.$appointment;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $code = (string) $request->route('cita');
        $key = self::key($request, $code);
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = max(1, RateLimiter::availableIn($key));
            $message = "Espera {$seconds} segundos antes de volver a solicitar el correo QR de esta cita. Tu reserva se conserva.";
            $headers = ['Retry-After' => (string) $seconds, 'X-RateLimit-Limit' => '1', 'X-RateLimit-Remaining' => '0'];
            $response = $request->expectsJson()
                ? response()->json(['message' => $message, 'retryAfter' => $seconds], 429, $headers)
                : redirect()->route('paciente.citas.show', $code, 303)->withErrors(['correoQr' => $message])->withHeaders($headers);

            return PatientResponses::privateResponse($response);
        }
        RateLimiter::hit($key, 120);

        return $next($request);
    }
}
