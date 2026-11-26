<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

final class AppointmentQrMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $passData
     */
    public function __construct(
        public readonly array $passData,
        public readonly string $qrPngBinary,
        private readonly ?int $notificationId = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->noticeTitle().' - '.$this->passData['codCit'],
        );
    }

    public function noticeTitle(): string
    {
        return match ($this->passData['tipo'] ?? 'Confirmacion') {
            'Confirmacion' => 'Confirmación de cita',
            'Recordatorio_24h' => 'Recordatorio de cita',
            'Cita_En_Espera' => 'Llegada registrada',
            'Cita_Cancelada' => 'Cita cancelada',
            'Cita_Afectada' => 'Cambio en tu cita',
            default => 'Aviso de cita',
        };
    }

    public function headers(): Headers
    {
        if ($this->notificationId === null) {
            return new Headers;
        }
        $local = hash('sha256', config('app.url').':'.$this->notificationId);
        $domain = substr(strrchr(config('mail.from.address'), '@'), 1);

        return new Headers(messageId: $local.'@'.$domain);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.appointment-qr',
            with: ['noticeTitle' => $this->noticeTitle()],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->qrPngBinary === '' ? [] : [
            Attachment::fromData(fn () => $this->qrPngBinary, 'pase-cita-'.$this->passData['codCit'].'.png')
                ->withMime('image/png'),
        ];
    }
}
