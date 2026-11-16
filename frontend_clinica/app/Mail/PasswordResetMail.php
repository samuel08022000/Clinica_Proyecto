<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $resetUrl, public readonly string $expiresAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Recuperación de contraseña');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset');
    }
}
