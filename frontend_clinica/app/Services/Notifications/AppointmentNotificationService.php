<?php

namespace App\Services\Notifications;

use App\Exceptions\ClinicalOperationException;
use App\Mail\AppointmentQrMail;
use App\Services\Clinical\ClinicalRoleGuard;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Mail;
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
            "SELECT cod_not, cod_cit, tipo_alerta, mensaje, creado_en FROM notificaciones
             WHERE cod_usu=? AND canal='Web' AND estado<>'Cancelada' AND fecha_programada<=now()
             ORDER BY creado_en DESC, cod_not DESC LIMIT 50", [$user->cod_usu],
        ));
    }

    public function retryConfirmation(string $code): void
    {
        $user = $this->roles->require('ROL001');
        $changed = $this->db()->update(
            "UPDATE notificaciones n SET estado='Pendiente', fecha_programada=now(), ultimo_error=NULL
             FROM citas c JOIN pacientes p ON p.cod_pac=c.cod_pac
             WHERE n.cod_cit=c.cod_cit AND c.cod_cit=? AND p.cod_usu=?
               AND n.canal='Email' AND n.tipo_alerta='Confirmacion'
               AND n.estado='Pendiente' AND n.intentos>0 AND n.intentos<n.max_intentos
               AND c.estado='Confirmada' AND c.fecha+c.hora_fin>ahora_clinica()
               AND n.fecha_programada>now() AND n.fecha_programada<=now()+interval '60 minutes'",
            [$code, $user->cod_usu],
        );
        if ($changed === 0) {
            throw new ClinicalOperationException(409, 'El correo ya fue procesado, está pendiente o agotó sus reintentos. Consulta las notificaciones.', 'CONFLICTO');
        }
    }

    /** @return array{enviadas:int, errores:int, descartadas:int} */
    public function process(int $limit = 50): array
    {
        $this->assertMailTransport();
        $batch = $this->db()->select('SELECT * FROM public.tomar_notificaciones(?)', [$limit]);
        $result = ['enviadas' => 0, 'errores' => 0, 'descartadas' => 0];
        foreach ($batch as $notice) {
            try {
                $result[$this->deliver($notice)]++;
            } catch (Throwable) {
                $this->db()->scalar('SELECT public.finalizar_notificacion(?,false,?)', [
                    $notice->id_notificacion, 'Error recuperable de transporte o de generación del pase.',
                ]);
                $result['errores']++;
            }
        }

        return $result;
    }

    private function deliver(object $notice): string
    {
        return $this->db()->transaction(function (Connection $db) use ($notice): string {
            $current = $db->selectOne('SELECT estado FROM notificaciones WHERE cod_not=? FOR UPDATE', [$notice->id_notificacion]);
            if ($current?->estado !== 'EnProceso') {
                return 'descartadas';
            }
            $pass = $notice->cita ? $db->selectOne(
                'SELECT c.cod_cit AS "codCit", c.estado, c.token_qr AS "tokenQr", c.fecha,
                    c.hora_inicio AS "horaInicio", c.hora_fin AS "horaFin",
                    up.nombre_completo AS "nombrePaciente", up.email, p.email_verificado,
                    um.nombre_completo AS "nombreMedico", e.nombre_especialidad AS "nombreEspecialidad",
                    (c.fecha+c.hora_fin<=ahora_clinica()) AS vencida
                 FROM citas c JOIN pacientes p ON p.cod_pac=c.cod_pac
                 JOIN usuarios up ON up.cod_usu=p.cod_usu JOIN medicos m ON m.cod_med=c.cod_med
                 JOIN usuarios um ON um.cod_usu=m.cod_usu JOIN especialidades e ON e.cod_esp=c.cod_esp
                 WHERE c.cod_cit=?', [$notice->cita],
            ) : null;
            if (in_array($notice->tipo, ['Confirmacion', 'Recordatorio_24h'], true)
                && (! $pass || $pass->estado !== 'Confirmada' || $pass->vencida)) {
                $db->update("UPDATE notificaciones SET estado='Cancelada',en_proceso_desde=NULL WHERE cod_not=?", [$notice->id_notificacion]);

                return 'descartadas';
            }
            if ($notice->canal_salida === 'Web') {
                $db->scalar('SELECT public.finalizar_notificacion(?,true)', [$notice->id_notificacion]);

                return 'enviadas';
            }
            if ($notice->canal_salida !== 'Email' || ! $pass || ! $pass->email_verificado
                || ! filter_var($pass->email, FILTER_VALIDATE_EMAIL) || $pass->email !== $notice->destino) {
                throw new RuntimeException('Destinatario o canal no habilitado.');
            }
            $qr = in_array($notice->tipo, ['Confirmacion', 'Recordatorio_24h'], true)
                ? $this->qrPng($pass->tokenQr ?? throw new RuntimeException('QR no disponible.')) : '';
            $sent = Mail::to($pass->email)->send(new AppointmentQrMail(
                (array) $pass + ['mensaje' => $notice->mensaje, 'tipo' => $notice->tipo], $qr,
            ));
            if ($sent === null) {
                throw new RuntimeException('El transporte no aceptó el mensaje.');
            }
            $db->scalar('SELECT public.finalizar_notificacion(?,true)', [$notice->id_notificacion]);

            return 'enviadas';
        });
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
