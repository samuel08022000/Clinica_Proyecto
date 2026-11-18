<?php

namespace App\Console\Commands;

use App\Services\Notifications\AppointmentNotificationService;
use Illuminate\Console\Command;
use Throwable;

class ClinicalNotifications extends Command
{
    protected $signature = 'clinical:notifications {--limit=50} {--check : Revisa configuración sin consumir avisos ni enviar correos}';

    protected $description = 'Procesa confirmaciones, alertas y recordatorios de la outbox clínica';

    public function handle(AppointmentNotificationService $notifications): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('El límite debe estar entre 1 y 500.');

            return self::INVALID;
        }
        try {
            if ($this->option('check')) {
                $notifications->assertMailTransport();
                $this->line(json_encode([
                    'transporte' => config('mail.default'),
                    'remitente_valido' => true,
                    'gd_disponible' => extension_loaded('gd'),
                    'smtp_host_configurado' => filled(config('mail.mailers.smtp.host')),
                    'smtp_puerto_configurado' => filled(config('mail.mailers.smtp.port')),
                    'smtp_usuario_configurado' => filled(config('mail.mailers.smtp.username')),
                    'smtp_clave_configurada' => filled(config('mail.mailers.smtp.password')),
                    'conexion_y_entrega_verificadas' => false,
                ]));

                return self::SUCCESS;
            }
            $this->line(json_encode($notifications->process($limit)));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No se procesó la cola. Revise permisos clínicos, remitente y transporte SMTP.');

            return self::FAILURE;
        }
    }
}
