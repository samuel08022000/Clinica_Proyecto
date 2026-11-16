<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code, public readonly string $expiresAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verificación de correo');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.verification-code');
    }
}
