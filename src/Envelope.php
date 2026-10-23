<?php

declare(strict_types=1);

namespace K9o;

/**
 * Standard envelope returned by every k9o surface on success.
 *
 * Per PHP idiomatic shape: snake_case at the SDK boundary;
 * wire camelCase translated via JSON_OBJECT_AS_ARRAY decode + explicit
 * field-name mapping at the boundary.
 */
class Envelope
{
    public function __construct(
        public readonly mixed $data,
        public readonly Meta $meta
    ) {}

    /**
     * @param array<string, mixed> $wire
     */
    public static function fromWire(array $wire): self
    {
        return new self(
            $wire['data'] ?? null,
            Meta::fromWire($wire['meta'] ?? [])
        );
    }
}

/**
 * Meta accessor sugar. Sub-namespaces are nullable; null
 * when the gateway didn't send the corresponding sub-namespace.
 */
class Meta
{
    public function __construct(
        public readonly ?MetaCursor $cursor = null,
        public readonly ?MetaSubscription $subscription = null,
        public readonly ?MetaIdempotency $idempotency = null,
        public readonly array $warnings = [],
        public readonly array $cost = [],
        public readonly ?MetaTiming $timing = null,
        public readonly ?MetaContext $context = null
    ) {}

    /**
     * @param array<string, mixed> $wire
     */
    public static function fromWire(array $wire): self
    {
        $cursor = isset($wire['cursor']) && is_array($wire['cursor'])
            ? new MetaCursor($wire['cursor']['next'] ?? null, $wire['cursor']['totalCount'] ?? null)
            : null;
        $sub = isset($wire['subscription']) && is_array($wire['subscription'])
            ? new MetaSubscription((bool) ($wire['subscription']['isInitial'] ?? false))
            : null;
        $idem = isset($wire['idempotency']) && is_array($wire['idempotency'])
            ? new MetaIdempotency((bool) ($wire['idempotency']['replayed'] ?? false))
            : null;
        $warnings = [];
        foreach ($wire['warnings'] ?? [] as $w) {
            if (is_array($w)) {
                $warnings[] = new MetaWarning($w['code'] ?? '', $w['message'] ?? '');
            }
        }
        $timing = isset($wire['timing']) && is_array($wire['timing'])
            ? new MetaTiming((int) ($wire['timing']['totalMs'] ?? 0))
            : null;
        $context = isset($wire['context']) && is_array($wire['context']) && isset($wire['context']['requestId'])
            ? new MetaContext((string) $wire['context']['requestId'])
            : null;
        return new self($cursor, $sub, $idem, $warnings, $wire['cost'] ?? [], $timing, $context);
    }
}

class MetaCursor
{
    public function __construct(public readonly ?string $next, public readonly ?int $total_count) {}
}

class MetaSubscription
{
    public function __construct(public readonly bool $is_initial) {}
}

class MetaIdempotency
{
    public function __construct(public readonly bool $replayed) {}
}

class MetaWarning
{
    public function __construct(public readonly string $code, public readonly string $message) {}
}

class MetaTiming
{
    public function __construct(public readonly int $total_ms) {}
}

class MetaContext
{
    public function __construct(public readonly string $request_id) {}
}
