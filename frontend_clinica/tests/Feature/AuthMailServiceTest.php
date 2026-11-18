<?php

namespace Tests\Feature;

use App\Exceptions\AuthDomainException;
use App\Mail\VerificationCodeMail;
use App\Services\Auth\AuthMailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

final class AuthMailServiceTest extends TestCase
{
    #[Test]
    #[DataProvider('unavailableConfigurations')]
    public function incomplete_or_non_delivery_configuration_is_rejected(array $configuration): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'sender@example.invalid',
            'mail.mailers.smtp.host' => 'smtp.gmail.com', 'mail.mailers.smtp.username' => 'sender@example.invalid',
            'mail.mailers.smtp.password' => 'test-only-password', 'mail.mailers.smtp.url' => null]);
        config($configuration);
        $this->expectException(AuthDomainException::class);
        $this->expectExceptionMessage('La entrega por correo no está configurada.');
        app(AuthMailService::class)->ensureTransport();
    }

    public static function unavailableConfigurations(): array
    {
        return [
            'log' => [['mail.default' => 'log']],
            'missing password' => [['mail.mailers.smtp.password' => '']],
            'missing username' => [['mail.mailers.smtp.username' => null]],
            'missing sender' => [['mail.from.address' => '']],
            'failover to log' => [['mail.default' => 'failover']],
            'empty failover' => [['mail.default' => 'failover', 'mail.mailers.failover.mailers' => []]],
            'recursive failover' => [['mail.default' => 'failover', 'mail.mailers.failover.mailers' => ['failover']]],
        ];
    }

    #[Test]
    public function simulated_otp_messages_are_addressed_to_each_patient_and_are_renderable(): void
    {
        config(['mail.default' => 'array']);
        Mail::fake();
        foreach (['first@example.invalid', 'second@example.invalid'] as $email) {
            app(AuthMailService::class)->sendOtp($email, '012345');
            Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo($email)
                && $mail->code === '012345' && str_contains($mail->render(), '012345'));
        }
        Mail::assertSentCount(2);
    }

    #[Test]
    public function smtp_failures_are_controlled_and_sensitive_debug_information_is_not_logged(): void
    {
        config(['mail.default' => 'array']);
        Log::spy();
        Mail::shouldReceive('to')->once()->with('patient@example.invalid')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new TransportException('Test-only sensitive SMTP debug and OTP 012345'));
        try {
            app(AuthMailService::class)->sendOtp('patient@example.invalid', '012345');
            $this->fail('SMTP failure was not reported.');
        } catch (AuthDomainException $error) {
            $this->assertSame('MAIL_DELIVERY_FAILED', $error->errorCode);
            $this->assertSame(503, $error->status);
            $this->assertStringNotContainsString('012345', $error->getMessage());
        }
        Log::shouldHaveReceived('warning')->once()->with('OTP: el transporte de correo rechazó el envío.',
            ['error' => TransportException::class]);
    }

    #[Test]
    public function gmail_credentials_in_a_mail_url_are_validated_as_effective_configuration(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'sender@example.invalid',
            'mail.mailers.smtp.url' => 'smtp://sender%40example.invalid:test-only-password@smtp.gmail.com:587']);
        app(AuthMailService::class)->ensureTransport();
        $this->assertTrue(true);
    }

    #[Test]
    public function array_transport_is_unavailable_outside_testing(): void
    {
        config(['mail.default' => 'array']);
        $this->app['env'] = 'production';
        $this->expectException(AuthDomainException::class);
        app(AuthMailService::class)->ensureTransport();
    }
}
