<?php

namespace Tests\Unit\Auth;

use App\Exceptions\AuthDomainException;
use App\Mail\PasswordResetMail;
use App\Services\Auth\AuthMailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MockeryIsolationTestCase;

final class AuthMailBoundaryTest extends MockeryIsolationTestCase
{
    public function test_recovery_uses_one_reset_mailable_with_the_clinic_expiry_without_sending_real_mail(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 10, 0, 0, 'America/La_Paz'));
        $url = 'https://example.invalid/restablecer-contrasena/synthetic-token';
        app(AuthMailService::class)->sendPasswordReset('synthetic@example.invalid', $url);
        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo('synthetic@example.invalid')
            && $mail->resetUrl === $url && $mail->expiresAt === '10:15');
        Mail::assertSentCount(1);
        Mail::assertNothingQueued();
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('log');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_otp_configuration_rejection_never_calls_the_mail_collaborator(): void
    {
        config(['mail.default' => 'log']);
        Mail::shouldReceive('to')->never();
        try {
            app(AuthMailService::class)->sendOtp('synthetic@example.invalid', '654321');
            $this->fail('Expected unsafe transport rejection');
        } catch (AuthDomainException $error) {
            $this->assertSame('MAIL_TRANSPORT_UNAVAILABLE', $error->errorCode);
            $this->assertSame(503, $error->status);
            $this->assertStringNotContainsString('654321', $error->getMessage());
        }
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('log');
    }
}
