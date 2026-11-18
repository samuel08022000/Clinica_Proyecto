<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->passData['tipo'] ?? 'Confirmación de cita').' - '.$this->passData['codCit'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.appointment-qr',
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
