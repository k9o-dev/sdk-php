<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\Envelope;
use K9o\HttpTransport;
use K9o\KError;
use K9o\Meta;
use K9o\RetryConfig;
use PHPUnit\Framework\TestCase;

/**
 * Retry + backoff behaviour. Idempotent methods are retried on
 * transient failures (transport / 502 / 503 / 504 / 429); semantic 4xx and
 * non-idempotent methods are not. The retry policy is exercised directly
 * via HttpTransport::retry with a fake attempt callable (no live HTTP).
 */
final class RetryTest extends TestCase
{
    private function noSleep(): RetryConfig
    {
        return new RetryConfig(2, 'none', 0, 0);
    }

    /** @return callable():Envelope that fails `$failCount` times (status 503) then succeeds. */
    private function failingThenOk(int $failCount, int &$calls): callable
    {
        return function () use ($failCount, &$calls): Envelope {
            $calls++;
            if ($calls <= $failCount) {
                throw new KError('K9O_UPSTREAM_UNAVAILABLE', 'down', [], null, 503);
            }
            return new Envelope(null, new Meta());
        };
    }

    public function testGetRetriesTransientThenSucceeds(): void
    {
        $calls = 0;
        HttpTransport::retry($this->failingThenOk(2, $calls), 'GET', $this->noSleep());
        $this->assertSame(3, $calls); // 2 failures + 1 success
    }

    public function testGetExhaustsRetries(): void
    {
        $calls = 0;
        $threw = false;
        try {
            HttpTransport::retry($this->failingThenOk(999, $calls), 'GET', $this->noSleep());
        } catch (KError) {
            $threw = true;
        }
        $this->assertTrue($threw);
        $this->assertSame(3, $calls); // max_retries (2) + 1 initial
    }

    public function testPostNotRetried(): void
    {
        $calls = 0;
        $threw = false;
        try {
            HttpTransport::retry($this->failingThenOk(999, $calls), 'POST', $this->noSleep());
        } catch (KError) {
            $threw = true;
        }
        $this->assertTrue($threw);
        $this->assertSame(1, $calls); // non-idempotent → single attempt
    }

    public function testSemantic4xxNotRetried(): void
    {
        $calls = 0;
        $attempt = function () use (&$calls): Envelope {
            $calls++;
            throw new KError('K9O_NOT_FOUND', 'nope', [], null, 404);
        };
        $threw = false;
        try {
            HttpTransport::retry($attempt, 'GET', $this->noSleep());
        } catch (KError) {
            $threw = true;
        }
        $this->assertTrue($threw);
        $this->assertSame(1, $calls); // 404 is semantic, not transient
    }

    public function testIsRetryableMatrix(): void
    {
        $down = new KError('K9O_UPSTREAM_UNAVAILABLE', 'down');
        $five03 = new KError('X', 'x', [], null, 503);
        $four04 = new KError('K9O_NOT_FOUND', 'x', [], null, 404);

        $this->assertTrue(HttpTransport::is_retryable($down, 'GET'));
        $this->assertTrue(HttpTransport::is_retryable($five03, 'DELETE'));
        $this->assertFalse(HttpTransport::is_retryable($five03, 'POST'));
        $this->assertFalse(HttpTransport::is_retryable($four04, 'GET'));
    }

    public function testComputeDelay(): void
    {
        $none = new RetryConfig(3, 'none', 100, 5000);
        $this->assertSame(0, HttpTransport::compute_delay_ms($none, 0));

        $exp = new RetryConfig(3, 'exponential', 100, 5000);
        // attempt 0 base=100, ±25% jitter ⇒ within [75, 125]
        $d = HttpTransport::compute_delay_ms($exp, 0);
        $this->assertGreaterThanOrEqual(75, $d);
        $this->assertLessThanOrEqual(125, $d);
    }
}
