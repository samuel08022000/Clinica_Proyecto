<?php

namespace App\Services\Auth;

use App\Exceptions\AuthDomainException;
use App\Mail\PasswordResetMail;
use App\Mail\VerificationCodeMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

final class AuthMailService
{
    public function ensureTransport(): void
    {
        $mailer = (string) config('mail.default');
        if ($this->containsNonDeliveryTransport($mailer)) {
            throw new AuthDomainException('MAIL_TRANSPORT_UNAVAILABLE', 503,
                'La entrega por correo no está configurada.');
        }
    }

    public function sendOtp(string $email, string $code): void
    {
        $this->ensureTransport();
        Mail::to($email)->send(new VerificationCodeMail($code, $this->clinicalNow()->addMinutes(5)->format('H:i')));
    }

    public function sendPasswordReset(string $email, string $url): void
    {
        $this->ensureTransport();
        Mail::to($email)->send(new PasswordResetMail($url, $this->clinicalNow()->addMinutes(15)->format('H:i')));
    }

    private function containsNonDeliveryTransport(string $mailer, array $seen = []): bool
    {
        if ($mailer === '' || in_array($mailer, $seen, true)) {
            return true;
        }
        $transport = (string) config("mail.mailers.{$mailer}.transport", '');
        if ($transport === 'log' || ($transport === 'array' && ! app()->environment('testing'))) {
            return true;
        }
        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return $transport === '';
        }
        $seen[] = $mailer;
        foreach ((array) config("mail.mailers.{$mailer}.mailers", []) as $nested) {
            if ($this->containsNonDeliveryTransport((string) $nested, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function clinicalNow(): Carbon
    {
        return now((string) config('database.connections.clinical.timezone', 'America/La_Paz'));
    }
}
