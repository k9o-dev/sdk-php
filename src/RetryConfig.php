<?php

declare(strict_types=1);

namespace K9o;

/**
 * Retry + backoff policy. Applied to idempotent methods
 * (GET/HEAD/OPTIONS/DELETE) on transient failures only (transport error /
 * 502 / 503 / 504 / 429); semantic 4xx and non-idempotent methods are
 * never retried.
 */
final class RetryConfig
{
    public function __construct(
        public readonly int $max_retries = 3,
        /** "exponential" | "linear" | "none". */
        public readonly string $backoff = 'exponential',
        public readonly int $initial_delay_ms = 100,
        public readonly int $max_delay_ms = 5000,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }
}
