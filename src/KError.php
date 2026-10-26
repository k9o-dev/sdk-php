<?php

declare(strict_types=1);

namespace K9o;

/**
 * KError — the closed-taxonomy exception thrown by every k9o surface
 * on non-2xx gateway responses or SDK-side validation failures.
 *
 * PHP idiomatic shape: throw K9o\KError.
 * Code-narrowing: callers catch K9o\KError and switch on $e->code.
 */
class KError extends \Exception
{
    // Parent Exception declares $code as int (untyped, default 0). We can't shadow
    // that with our typed string property — PHP 8.5 raises a fatal. Instead store
    // the K9O code under a different name and expose it via __get('code') so the
    // the documented `$e->code` access keeps working.
    private string $kerror_code;
    public array $context;
    public ?string $recommendation;
    public ?int $status;
    public ?string $request_id;

    public function __construct(
        string $code,
        string $message,
        array $context = [],
        ?string $recommendation = null,
        ?int $status = null,
        ?string $request_id = null
    ) {
        parent::__construct($message);
        $this->kerror_code = $code;
        $this->context = $context;
        $this->recommendation = $recommendation;
        $this->status = $status;
        $this->request_id = $request_id;
    }

    /**
     * Magic property accessor — preserves the spec's `$e->code` shape.
     * Returns the K9O code string for `$e->code` (rather than parent
     * Exception's int code which defaults to 0).
     */
    public function __get(string $name): mixed
    {
        if ($name === 'code') {
            return $this->kerror_code;
        }
        return null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'code';
    }

    public function withRecommendation(string $rec): self
    {
        return new self(
            $this->kerror_code,
            $this->getMessage(),
            $this->context,
            $rec,
            $this->status,
            $this->request_id
        );
    }

    public function __toString(): string
    {
        if ($this->status !== null) {
            return sprintf('[%s] %s (status=%d)', $this->kerror_code, $this->getMessage(), $this->status);
        }
        return sprintf('[%s] %s', $this->kerror_code, $this->getMessage());
    }

    /**
     * Rehydrate a wire KError payload into a typed KError.
     *
     * @param array<string, mixed> $wire
     */
    public static function fromWire(array $wire, int $status): self
    {
        return new self(
            $wire['code'] ?? 'K9O_INTERNAL_ERROR',
            $wire['message'] ?? '',
            $wire['context'] ?? [],
            $wire['recommendation'] ?? null,
            $status,
            $wire['requestId'] ?? $wire['request_id'] ?? null
        );
    }
}
