<?php

namespace App\Services\Notifications;

use App\Exceptions\ClinicalOperationException;
use App\Mail\AppointmentQrMail;
use App\Services\Clinical\ClinicalRoleGuard;
use App\Support\ClinicalLog;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LogicException;
use RuntimeException;
use Throwable;

/** Consume la outbox transaccional existente; nunca crea una reserva ni otro aviso. */
class AppointmentNotificationService
{
    public function __construct(private DatabaseManager $database, private ClinicalRoleGuard $roles) {}

    public function qrPng(string $token): string
    {
        return (new Builder(writer: new PngWriter,
            data: rtrim(config('app.url'), '/').'/pase-qr/'.rawurlencode($token),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 300, margin: 20))->build()->getString();
    }

    public function patientNotices(): array
    {
        $user = $this->roles->require('ROL001');

        return array_map(static fn ($row) => (array) $row, $this->db()->select(
            "SELECT n.cod_not, n.cod_cit, n.tipo_alerta, n.mensaje, n.creado_en FROM notificaciones n
             LEFT JOIN citas c ON c.cod_cit=n.cod_cit
             WHERE n.cod_usu=? AND n.canal='Web' AND n.estado<>'Cancelada' AND n.fecha_programada<=now()
               AND (n.tipo_alerta<>'Recordatorio_24h' OR (c.estado='Confirmada' AND c.fecha+c.hora_inicio>ahora_clinica()))
             ORDER BY n.creado_en DESC, n.cod_not DESC LIMIT 50", [$user->cod_usu],
        ));
    }

    public function retryConfirmation(string $code): void
    {
        $user = $this->roles->require('ROL001');
        $existing = $this->db()->selectOne(
            "SELECT n.estado FROM notificaciones n JOIN citas c ON c.cod_cit=n.cod_cit
             JOIN pacientes p ON p.cod_pac=c.cod_pac
             WHERE c.cod_cit=? AND p.cod_usu=? AND n.canal='Email' AND n.tipo_alerta='Confirmacion'
             ORDER BY n.cod_not DESC LIMIT 1", [$code, $user->cod_usu],
        );
        if ($existing?->estado === 'Enviada') {
            return;
        }
        $changed = $this->db()->update(
            "UPDATE notificaciones n SET estado='Pendiente', fecha_programada=now(), ultimo_error=NULL
             FROM citas c JOIN pacientes p ON p.cod_pac=c.cod_pac
             WHERE n.cod_cit=c.cod_cit AND c.cod_cit=? AND p.cod_usu=?
               AND n.canal='Email' AND n.tipo_alerta='Confirmacion'
               AND n.estado='Pendiente' AND n.intentos<n.max_intentos
               AND c.estado='Confirmada' AND c.fecha+c.hora_fin>ahora_clinica()
               AND n.fecha_programada<=now()+interval '60 minutes'",
            [$code, $user->cod_usu],
        );
        if ($changed === 0) {
            ClinicalLog::event('patient.notification.retry_rejected', ['entity_id' => $code], 'warning');
            throw new ClinicalOperationException(409, 'El correo ya fue procesado, está pendiente o agotó sus reintentos. Consulta las notificaciones.', 'CONFLICTO');
        }
        ClinicalLog::afterCommit($this->db(), 'patient.notification.retry_requested', ['entity_id' => $code, 'actor_id' => $user->cod_usu]);
    }

    /** Envía únicamente la confirmación existente de una operación ya confirmada. */
    public function sendConfirmation(string $code): string
    {
        return $this->sendAppointmentNotice($code, 'Confirmacion');
    }

    public function sendCheckinNotification(string $code): string
    {
        return $this->sendAppointmentNotice($code, 'Cita_En_Espera');
    }

    private function sendAppointmentNotice(string $code, string $type): string
    {
        try {
            $db = $this->db();
            if ($db->transactionLevel() !== 0) {
                throw new LogicException('La reserva debe estar confirmada antes de enviar su QR.');
            }
            $this->assertMailTransport();
            $notice = $db->selectOne(
                "UPDATE notificaciones n SET estado='EnProceso', intentos=n.intentos+1,
                    en_proceso_desde=now(), ultimo_error=NULL
                 WHERE n.cod_not=(
                    SELECT candidate.cod_not FROM notificaciones candidate
                    WHERE candidate.cod_cit=? AND candidate.canal='Email' AND candidate.tipo_alerta=?
                      AND candidate.estado='Pendiente' AND candidate.intentos<candidate.max_intentos
                      AND candidate.fecha_programada<=now()
                    ORDER BY candidate.cod_not DESC LIMIT 1 FOR UPDATE SKIP LOCKED
                 )
                 RETURNING n.cod_not AS id_notificacion,n.cod_usu AS usuario,n.cod_cit AS cita,
                    n.canal AS canal_salida,n.tipo_alerta AS tipo,n.destinatario AS destino,
                    n.mensaje,n.intentos AS numero_intento", [$code, $type],
            );
            if ($notice !== null) {
                $this->processNotice($notice);
            }

            return $this->noticeStatus($code, $type)['correoEstado'] ?? 'NoDisponible';
        } catch (Throwable $error) {
            Log::warning('No se pudo procesar la confirmación QR.', ['cita' => $code, 'tipo' => $error::class]);

            return 'Pendiente';
        }
    }

    /** Consulta operativa sin exponer destinatarios, tokens ni credenciales. */
    public function confirmationStatus(string $code): array
    {
        return $this->noticeStatus($code, 'Confirmacion');
    }

    private function noticeStatus(string $code, string $type): array
    {
        return (array) ($this->db()->selectOne(
            "SELECT c.cod_cit AS \"codCit\", c.estado, c.fecha, p.email_verificado AS \"correoVerificado\",
                n.estado AS \"correoEstado\", n.intentos, n.enviado_en AS \"enviadoEn\",
                CASE WHEN n.ultimo_error LIKE 'SMTP_AUTH:%' THEN 'SMTP_AUTH'
                     WHEN n.ultimo_error LIKE 'SMTP_CONNECTION:%' THEN 'SMTP_CONNECTION'
                     WHEN n.ultimo_error LIKE 'QR_RENDERER:%' THEN 'QR_RENDERER'
                     WHEN n.ultimo_error IS NOT NULL THEN 'MAIL_ERROR' ELSE NULL END AS \"errorCodigo\"
             FROM citas c JOIN pacientes p ON p.cod_pac=c.cod_pac
             LEFT JOIN notificaciones n ON n.cod_cit=c.cod_cit AND n.canal='Email' AND n.tipo_alerta=?
             WHERE c.cod_cit=? ORDER BY n.cod_not DESC LIMIT 1", [$type, $code],
        ) ?? []);
    }

    /** @return array{enviadas:int, errores:int, descartadas:int} */
    public function process(int $limit = 50): array
    {
        $this->assertMailTransport();
        $batch = $this->db()->select('SELECT * FROM public.tomar_notificaciones(?)', [$limit]);
        $result = ['enviadas' => 0, 'errores' => 0, 'descartadas' => 0];
        foreach ($batch as $notice) {
            $result[$this->processNotice($notice)]++;
        }

        return $result;
    }

    private function processNotice(object $notice): string
    {
        $accepted = false;
        try {
            $result = $this->deliver($notice, $accepted);
        } catch (Throwable $error) {
            if ($accepted) {
                // El transporte ya aceptó el correo. Reintentar el registro sin volver a enviarlo.
                try {
                    $this->finalizeClaim($notice, true);
                    $result = 'enviadas';
                } catch (Throwable $persistenceError) {
                    ClinicalLog::event('notification.mail.persistence_failed', [
                        'entity_id' => $notice->cita, 'notification_id' => 'NOT'.$notice->id_notificacion,
                        'exception_type' => $persistenceError::class,
                    ], 'warning');

                    return 'errores';
                }
            } else {
                return $this->recordDeliveryFailure($notice, $error);
            }
        }
        if ($accepted && ! Mail::isFake()) {
            ClinicalLog::afterCommit($this->db(), 'notification.mail.transport_accepted', [
                'entity_id' => $notice->cita, 'notification_id' => 'NOT'.$notice->id_notificacion,
                'transport' => config('mail.mailers.'.config('mail.default').'.transport'),
            ]);
        }

        return $result;
    }

    private function recordDeliveryFailure(object $notice, Throwable $error): string
    {
        $description = strtolower($error->getMessage());
        $safeError = match (true) {
            str_contains($description, 'gd extension'), str_contains($description, 'imagecreatetruecolor') => 'QR_RENDERER: No se pudo generar el PNG. Revisa GD y reinicia el proceso PHP.',
            str_contains($description, 'authenticate'), str_contains($description, '535'), str_contains($description, '534') => 'SMTP_AUTH: El servidor rechazó la autenticación configurada.',
            str_contains($description, 'connect'), str_contains($description, 'timed out'), str_contains($description, 'getaddrinfo') => 'SMTP_CONNECTION: No se pudo conectar con el transporte de correo.',
            default => 'Error recuperable de transporte o de generación del pase.',
        };
        $this->finalizeClaim($notice, false, $safeError);
        ClinicalLog::event('notification.mail.failed', [
            'entity_id' => $notice->cita, 'notification_id' => 'NOT'.$notice->id_notificacion,
            'exception_type' => $error::class,
        ], 'warning');

        return 'errores';
    }

    private function finalizeClaim(object $notice, bool $success, ?string $error = null): void
    {
        $this->db()->transaction(function (Connection $db) use ($notice, $success, $error): void {
            if ($this->lockClaim($db, $notice) !== null) {
                $db->scalar('SELECT public.finalizar_notificacion(?,?::boolean,?)', [
                    $notice->id_notificacion, $success ? 'true' : 'false', $error,
                ]);
            }
        });
    }

    private function deliver(object $notice, bool &$accepted): string
    {
        return $this->db()->transaction(function (Connection $db) use ($notice, &$accepted): string {
            if ($notice->cita !== null) {
                $db->scalar('SELECT public.bloquear_cita_para_notificacion(?)', [$notice->cita]);
            }
            $current = $this->lockClaim($db, $notice);
            if ($current === null) {
                return 'descartadas';
            }
            $pass = $notice->cita ? $db->selectOne(
                'SELECT c.cod_cit AS "codCit", c.estado, c.token_qr AS "tokenQr", c.fecha,
                    c.hora_inicio AS "horaInicio", c.hora_fin AS "horaFin",
                    up.nombre_completo AS "nombrePaciente", up.cod_usu, up.email, p.email_verificado,
                    um.nombre_completo AS "nombreMedico", e.nombre_especialidad AS "nombreEspecialidad",
                    (c.fecha+c.hora_fin<=clock_timestamp() AT TIME ZONE \'America/La_Paz\') AS vencida,
                    (c.fecha+c.hora_inicio<=clock_timestamp() AT TIME ZONE \'America/La_Paz\') AS horario_iniciado,
                    (?::timestamptz > ((c.fecha+c.hora_inicio) AT TIME ZONE \'America/La_Paz\') - interval \'24 hours\') AS confirmada_tarde,
                    (c.hora_inicio_atencion IS NOT NULL) AS atencion_iniciada
                 FROM citas c JOIN pacientes p ON p.cod_pac=c.cod_pac
                 JOIN usuarios up ON up.cod_usu=p.cod_usu JOIN medicos m ON m.cod_med=c.cod_med
                 JOIN usuarios um ON um.cod_usu=m.cod_usu JOIN especialidades e ON e.cod_esp=c.cod_esp
                 WHERE c.cod_cit=?', [$current->creado_en, $notice->cita],
            ) : null;
            $vigente = match ($notice->tipo) {
                'Confirmacion' => $pass && $pass->estado === 'Confirmada' && ! $pass->vencida,
                'Recordatorio_24h' => $pass && $pass->estado === 'Confirmada' && ! $pass->horario_iniciado
                    && ($notice->canal_salida !== 'Email' || ! $pass->confirmada_tarde),
                'Cita_En_Espera' => $notice->canal_salida === 'Web'
                    || ($pass && $pass->estado === 'En Espera' && ! $pass->atencion_iniciada),
                default => true,
            };
            if (! $vigente || ($notice->canal_salida === 'Email'
                && (! $pass || $pass->cod_usu !== $notice->usuario || ! $pass->email_verificado
                    || ! filter_var($pass->email, FILTER_VALIDATE_EMAIL) || $pass->email !== $notice->destino))) {
                $db->update("UPDATE notificaciones SET estado='Cancelada',en_proceso_desde=NULL WHERE cod_not=?", [$notice->id_notificacion]);

                return 'descartadas';
            }
            if ($notice->canal_salida === 'Web') {
                $db->scalar('SELECT public.finalizar_notificacion(?,true)', [$notice->id_notificacion]);

                return 'enviadas';
            }
            if ($notice->canal_salida !== 'Email') {
                throw new RuntimeException('Canal no habilitado.');
            }
            $qr = in_array($notice->tipo, ['Confirmacion', 'Recordatorio_24h'], true)
                ? $this->qrPng($pass->tokenQr ?? throw new RuntimeException('QR no disponible.')) : '';
            $sent = Mail::to($pass->email)->send(new AppointmentQrMail(
                (array) $pass + ['mensaje' => $notice->mensaje, 'tipo' => $notice->tipo], $qr, (int) $notice->id_notificacion,
            ));
            if ($sent === null && ! (app()->environment('testing') && Mail::isFake())) {
                throw new RuntimeException('El transporte no aceptó el mensaje.');
            }
            $accepted = true;
            $db->scalar('SELECT public.finalizar_notificacion(?,true)', [$notice->id_notificacion]);

            return 'enviadas';
        });
    }

    private function lockClaim(Connection $db, object $notice): ?object
    {
        return $db->selectOne(
            "SELECT creado_en FROM notificaciones WHERE cod_not=? AND estado='EnProceso' AND intentos=? FOR UPDATE",
            [$notice->id_notificacion, $notice->numero_intento],
        );
    }

    public function assertMailTransport(): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)
            && ! (app()->environment('testing') && $transport === 'array')) {
            throw new RuntimeException('Configure un transporte de correo real; log, array y fallbacks no acreditan envío.');
        }
        if (! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El remitente no tiene un formato válido.');
        }
    }

    private function db(): Connection
    {
        return $this->database->connection('clinical');
    }
}
