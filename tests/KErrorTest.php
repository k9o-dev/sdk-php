<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\KError;
use PHPUnit\Framework\TestCase;

class KErrorTest extends TestCase
{
    public function testConstructsWithRequiredFields(): void
    {
        $e = new KError('K9O_INVALID_FIELD_VALUE', 'bad');
        $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        $this->assertSame('bad', $e->getMessage());
        $this->assertSame([], $e->context);
        $this->assertNull($e->recommendation);
        $this->assertNull($e->status);
    }

    public function testIsThrowable(): void
    {
        $this->expectException(KError::class);
        throw new KError('X', 'y');
    }

    public function testToStringIncludesStatus(): void
    {
        $e = new KError('K9O_TIER_CAP_HIT', 'cap', [], null, 429);
        $s = (string) $e;
        $this->assertStringContainsString('K9O_TIER_CAP_HIT', $s);
        $this->assertStringContainsString('429', $s);
    }

    public function testWithRecommendation(): void
    {
        $e = (new KError('X', 'y'))->withRecommendation('do this');
        $this->assertSame('do this', $e->recommendation);
    }

    public function testFromWireRehydration(): void
    {
        $e = KError::fromWire([
            'code' => 'K9O_TIER_CAP_HIT',
            'message' => 'cap',
            'context' => ['dim' => 'mau'],
            'recommendation' => 'upgrade',
            'requestId' => 'req_x',
        ], 429);
        $this->assertSame('K9O_TIER_CAP_HIT', $e->code);
        $this->assertSame(429, $e->status);
        $this->assertSame('req_x', $e->request_id);
        $this->assertSame(['dim' => 'mau'], $e->context);
    }
}
