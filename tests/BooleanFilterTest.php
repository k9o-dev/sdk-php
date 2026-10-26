<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\Data\BaseBuilder;
use K9o\Data\FromBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BooleanFilterTest extends TestCase
{
    private function wire(mixed $value): string
    {
        // BaseBuilder is declared in the same file as FromBuilder; loading one loads both.
        class_exists(FromBuilder::class);
        $method = new ReflectionMethod(BaseBuilder::class, 'wire');
        return $method->invoke(null, $value);
    }

    public function testBooleansUseTheGatewayWireForm(): void
    {
        $this->assertSame('true', $this->wire(true));
        $this->assertSame('false', $this->wire(false));
    }

    public function testOtherValuesAreCastToStrings(): void
    {
        $this->assertSame('7', $this->wire(7));
        $this->assertSame('ada', $this->wire('ada'));
    }
}
