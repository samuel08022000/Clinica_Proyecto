<?php

namespace Tests\Unit\Auth;

use App\Exceptions\AuthDomainException;
use App\Services\Auth\GoogleOidcIdentityProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\MockeryIsolationTestCase;

final class GoogleOidcBoundaryTest extends MockeryIsolationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['clinic.features.google_client_id' => 'unit-client.apps.googleusercontent.com',
            'clinic.features.google_client_secret' => 'synthetic-private-client-secret']);
    }

    public function test_unknown_google_error_is_reduced_to_allowlisted_category_without_requesting_keys(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
            'error' => 'PRIVATE_SYNTHETIC_RESPONSE', 'error_description' => 'PRIVATE_SYNTHETIC_DESCRIPTION',
        ], 400)]);
        try {
            app(GoogleOidcIdentityProvider::class)->exchange('synthetic-code', 'synthetic-verifier',
                'synthetic-nonce', 'https://example.invalid/auth/google/callback');
            $this->fail('Expected authorization rejection');
        } catch (AuthDomainException $error) {
            $this->assertSame('GOOGLE_AUTHENTICATION_FAILED', $error->errorCode);
            $this->assertStringNotContainsString('PRIVATE_SYNTHETIC', $error->getMessage());
            $this->assertStringNotContainsString('synthetic-private-client-secret', $error->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->method() === 'POST');
        Log::shouldHaveReceived('warning')->once()->with('Google OIDC: el canje del código fue rechazado.',
            ['status' => 400, 'google_error' => 'unknown_error']);
    }

    public function test_non_string_id_token_stops_before_key_fetch_or_claim_validation(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['id_token' => ['PRIVATE_SYNTHETIC_TOKEN']])]);
        try {
            app(GoogleOidcIdentityProvider::class)->exchange('synthetic-code', 'synthetic-verifier',
                'synthetic-nonce', 'https://example.invalid/auth/google/callback');
            $this->fail('Expected malformed token rejection');
        } catch (AuthDomainException $error) {
            $this->assertSame('GOOGLE_ID_TOKEN_INVALID', $error->errorCode);
            $this->assertStringNotContainsString('PRIVATE_SYNTHETIC_TOKEN', $error->getMessage());
        }
        Http::assertSentCount(1);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('log');
    }
}
