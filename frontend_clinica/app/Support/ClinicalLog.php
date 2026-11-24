<?php

namespace App\Support;

use App\Exceptions\AuthDomainException;
use App\Exceptions\ClinicalOperationException;
use App\Services\Clinical\ClinicalSqlException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/** Contexto técnico cerrado: nunca recibe formularios, SQL, mensajes o excepciones como contexto. */
final class ClinicalLog
{
    private static ?WeakMap $reported = null;

    public static function event(string $event, array $context = [], string $level = 'info'): void
    {
        if (! preg_match('/^(auth|admin|reception|doctor|patient|notification|clinical|app)\.[a-z_.]+$/D', $event)
            || ! in_array($level, ['info', 'warning', 'error'], true)) {
            return;
        }

        $safe = [];
        foreach ($context as $key => $value) {
            $valid = match ($key) {
                'actor_id', 'entity_id', 'previous_appointment_id', 'notification_id' => is_string($value)
                    && preg_match('/^(USU|MED|SEC|PAC|CIT|ESP|HOR|BLO|INC|DER|ANA|NOT)[0-9]{1,12}$/D', $value),
                'role' => is_string($value) && preg_match('/^ROL00[1-4]$/D', $value),
                'action' => in_array($value, ['create', 'update', 'status', 'duration', 'password', 'credentials', 'verified_identity', 'pair', 'account'], true),
                'error_code' => is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $value),
                'sqlstate' => is_string($value) && preg_match('/^[A-Z0-9]{5}$/D', $value),
                'exception_type' => is_string($value) && is_a($value, Throwable::class, true)
                    && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/D', $value),
                'http_status' => is_int($value) && $value >= 400 && $value <= 599,
                'transport' => in_array($value, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'array'], true),
                default => false,
            };
            if ($valid) {
                $safe[$key] = $value;
            }
        }

        try {
            Log::log($level, $event, $safe);
        } catch (Throwable) {
            // Un fallo del destino de observabilidad no cambia el resultado de una operación clínica.
        }
    }

    /** Solo se llama una vez que la transacción dueña ha confirmado. */
    public static function completed(string $event, array $context, mixed $result): void
    {
        $key = match (true) {
            str_starts_with($event, 'doctor.referral.') => 'codDer',
            str_starts_with($event, 'doctor.analysis.') => 'codAna',
            default => 'codCit',
        };
        $entity = is_array($result) ? ($result[$key] ?? null) : $result;
        self::event($event, $context + ['entity_id' => $entity]);
    }

    public static function afterCommit(Connection $connection, string $event, array $context = []): void
    {
        if ($connection->transactionLevel() === 0) {
            self::event($event, $context);

            return;
        }
        try {
            $connection->afterCommit(static fn () => self::event($event, $context));
        } catch (Throwable) {
            // No anunciar éxito anticipado si el entorno no permite diferirlo hasta el commit.
        }
    }

    /** La misma excepción no se vuelve a registrar al atravesar servicio/controlador/handler. */
    public static function failure(string $operation, Throwable $error, array $context = []): void
    {
        self::$reported ??= new WeakMap;
        if (isset(self::$reported[$error])) {
            return;
        }
        self::$reported[$error] = true;
        $status = $error instanceof ClinicalOperationException || $error instanceof AuthDomainException
            ? $error->status : 500;
        $safe = [
            'http_status' => $status,
            'exception_type' => $error::class,
            'error_code' => $error instanceof ClinicalOperationException || $error instanceof AuthDomainException
                ? $error->errorCode : 'TECHNICAL_ERROR',
        ];
        if ($error instanceof ClinicalSqlException) {
            $safe['sqlstate'] = $error->sqlState;
        }
        self::event($operation.($status < 500 ? '.rejected' : '.failed'), $safe + $context,
            $status < 500 ? 'warning' : 'error');
    }
}
