<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\K9oClient;
use K9o\KError;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testCreateConstructsWithMinimalOpts(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        $this->assertSame('https://api.k9o.io', $c->base_url());
        $this->assertNotNull($c->projects);
        $this->assertNotNull($c->gate);
    }

    public function testCreateNormalisesTrailingSlash(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io/']);
        $this->assertSame('https://api.k9o.io', $c->base_url());
    }

    public function testCreateRejectsPathBearingBaseUrl(): void
    {
        $this->expectException(KError::class);
        $this->expectExceptionMessage('must not contain a path segment');
        K9oClient::create(['base_url' => 'https://api.k9o.io/v2']);
    }

    public function testCreateDefaultsToApexWhenBaseUrlOmitted(): void
    {
        $c = K9oClient::create([]);
        $this->assertSame('https://api.k9o.io', $c->base_url());
    }

    public function testCreateDerivesSubdomainFromApexPlusOrgSlug(): void
    {
        $c = K9oClient::create(['org_slug' => 'acme']);
        $this->assertSame('https://acme.api.k9o.io', $c->base_url());
    }

    public function testCreateLeavesExplicitSubdomainUnchanged(): void
    {
        $c = K9oClient::create(['base_url' => 'https://acme.api.k9o.io', 'org_slug' => 'acme']);
        $this->assertSame('https://acme.api.k9o.io', $c->base_url());
    }

    public function testCreateLeavesSelfHostedHostUnchanged(): void
    {
        $c = K9oClient::create(['base_url' => 'https://platform.acme.internal', 'org_slug' => 'acme']);
        $this->assertSame('https://platform.acme.internal', $c->base_url());
    }

    public function testCreateRejectsNonStringBaseUrl(): void
    {
        $this->expectException(KError::class);
        /** @phpstan-ignore-next-line */
        K9oClient::create(['base_url' => 123]);
    }

    public function testDataPlaneSelectRequiresProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->from('posts')->select()->execute();
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testDataPlaneUpdateRequiresIdFilter(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io', 'project_slug' => 'member-portal']);
        try {
            $c->from('posts')->update(['title' => 'x'])->execute();
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        }
    }

    public function testProjectsCreateValidatesSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io', 'org_slug' => 'acme']);
        try {
            $c->projects->create('Bad-Slug', 'x');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        }
    }

    public function testGateRequiresProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->gate->whoami();
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testCollectionNameValidation(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io', 'project_slug' => 'member-portal']);
        try {
            $c->from('Bad-Name')->select()->execute();
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        }
    }
}
