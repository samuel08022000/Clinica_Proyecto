<?php

namespace App\Services\Auth;

use App\Exceptions\AuthDomainException;
use App\Mail\PasswordResetMail;
use App\Mail\VerificationCodeMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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
        try {
            Mail::to($email)->send(new VerificationCodeMail($code, $this->clinicalNow()->addMinutes(5)->format('H:i')));
        } catch (TransportExceptionInterface $error) {
            Log::warning('OTP: el transporte de correo rechazó el envío.', ['error' => $error::class]);
            throw new AuthDomainException('MAIL_DELIVERY_FAILED', 503,
                'No fue posible enviar el código de verificación. Intenta nuevamente más tarde.');
        }
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
        $configuration = (array) config("mail.mailers.{$mailer}", []);
        try {
            $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        } catch (\InvalidArgumentException) {
            return true;
        }
        $transport = (string) ($configuration['driver'] ?? $configuration['transport'] ?? '');
        if ($transport === 'log' || ($transport === 'array' && ! app()->environment('testing'))) {
            return true;
        }
        if ($transport === 'smtp') {
            if (trim((string) ($configuration['host'] ?? '')) === ''
                || ! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
                return true;
            }
            if (strtolower((string) $configuration['host']) === 'smtp.gmail.com') {
                return ! filter_var($configuration['username'] ?? '', FILTER_VALIDATE_EMAIL)
                    || trim((string) ($configuration['password'] ?? '')) === '';
            }
        }
        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return $transport === '';
        }
        $seen[] = $mailer;
        $nestedMailers = (array) ($configuration['mailers'] ?? []);
        if ($nestedMailers === []) {
            return true;
        }
        foreach ($nestedMailers as $nested) {
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
