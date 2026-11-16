<?php

namespace App\Exceptions;

use RuntimeException;

class PacienteApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly int $status = 0,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function isUnauthenticated(): bool
    {
        return $this->status === 401 || $this->errorCode === 'UNAUTHENTICATED';
    }

    /** Mensaje seguro para mostrar al paciente (no filtra detalles internos). */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'SLOT_NO_DISPONIBLE' => 'Ese horario ya no está disponible. Elige otro.',
            'CITA_DUPLICADA' => 'Ya tienes una cita en ese horario.',
            'CUENTA_SANCIONADA' => 'Tu cuenta tiene una sanción activa y no puede reservar citas por ahora.',
            'CITA_NO_ENCONTRADA' => 'No encontramos esa cita.',
            'CITA_NO_CANCELABLE' => 'Esta cita ya no se puede cancelar.',
            'CANCELACION_FUERA_DE_PLAZO' => 'Solo puedes cancelar hasta 24 horas antes de la cita. Contacta con la clínica.',
            'FECHA_INVALIDA' => 'La fecha elegida no es válida.',
            'VALIDACION', 'VALIDATION_ERROR' => 'Revisa los datos ingresados e intenta nuevamente.',
            'SERVICIO_NO_DISPONIBLE' => 'El servicio de citas no está disponible. Intenta nuevamente en unos minutos.',
            default => $this->status >= 500 || $this->status === 0
                ? 'El servicio de citas presentó un error. Intenta nuevamente.'
                : 'No fue posible completar la operación.',
        };
    }
}
