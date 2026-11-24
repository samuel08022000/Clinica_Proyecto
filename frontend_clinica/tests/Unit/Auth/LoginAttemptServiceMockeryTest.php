<?php

namespace Tests\Unit\Auth;

use App\Exceptions\AuthDomainException;
use App\Services\Auth\LoginAttemptService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\MockeryIsolationTestCase;

final class LoginAttemptServiceMockeryTest extends MockeryIsolationTestCase
{
    public function test_aggregate_limit_is_checked_after_pair_is_allowed_and_preserves_retry_after(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('tooManyAttempts')->with(Mockery::type('string'), 5)->once()->ordered()->andReturnFalse();
        $limiter->shouldReceive('tooManyAttempts')->with(Mockery::type('string'), 15)->once()->ordered()->andReturnTrue();
        $limiter->shouldReceive('availableIn')->once()->with(Mockery::type('string'))->andReturn(91);
        $limiter->shouldNotReceive('hit');
        $limiter->shouldNotReceive('clear');
        try {
            (new LoginAttemptService($limiter))->ensureAllowed('synthetic@example.invalid', '198.51.100.77');
            $this->fail('Expected aggregate rate limit');
        } catch (AuthDomainException $error) {
            $this->assertSame(429, $error->status);
            $this->assertSame(91, $error->retryAfter);
        }
        Log::shouldHaveReceived('log')->once()->with('warning', 'auth.login.blocked',
            ['action' => 'account', 'error_code' => 'TOO_MANY_ATTEMPTS']);
    }

    public function test_failed_attempt_updates_two_distinct_private_counters_with_the_same_decay(): void
    {
        $keys = [];
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('hit')->twice()->with(Mockery::type('string'), 900)
            ->andReturnUsing(function (string $key) use (&$keys): int {
                $keys[] = $key;

                return 1;
            });
        $limiter->shouldNotReceive('clear');
        (new LoginAttemptService($limiter))->failure('synthetic@example.invalid', '198.51.100.77');
        $this->assertCount(2, array_unique($keys));
        foreach ($keys as $key) {
            $this->assertStringNotContainsString('synthetic@example.invalid', $key);
            $this->assertStringNotContainsString('198.51.100.77', $key);
        }
        Log::shouldNotHaveReceived('log');
    }
}
