<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\K9oClient;
use K9o\KError;
use PHPUnit\Framework\TestCase;

/**
 * Full-coverage parity tests for the namespaces bringing PHP to TS surface
 * parity: migrations / functions / policies / audit / orgs / me / full org.
 * Validation-only philosophy (ext-curl round-trips exercised by integration
 * tests once the gateway lands): verifies namespace composition + project-
 * context enforcement.
 */
class ParityTest extends TestCase
{
    private function projectClient(): K9oClient
    {
        return K9oClient::create(['base_url' => 'https://acme.api.k9o.io', 'project_slug' => 'portal']);
    }

    public function testNamespacesComposeOnClient(): void
    {
        $c = $this->projectClient();
        $this->assertNotNull($c->migrations);
        $this->assertNotNull($c->functions);
        $this->assertNotNull($c->policies);
        $this->assertNotNull($c->audit);
        $this->assertNotNull($c->orgs);
        $this->assertNotNull($c->me);
        $this->assertNotNull($c->me->profile);
        $this->assertNotNull($c->me->sessions);
        $this->assertNotNull($c->me->api_tokens);
        $this->assertNotNull($c->org->members);
        $this->assertNotNull($c->org->groups);
        $this->assertNotNull($c->org->smtp);
        $this->assertNotNull($c->org->audit_log);
        $this->assertNotNull($c->org->gate);
        $this->assertNotNull($c->org->gate->roles);
        $this->assertNotNull($c->org->gate->idp_providers);
        $this->assertNotNull($c->org->default_llm);
    }

    public function testMigrationsRequireProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->migrations->list();
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testFunctionsRequireProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->functions->get('greet');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testPoliciesRequireProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->policies->current();
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testAuditRequireProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->audit->query(['type' => 'data.write.access.insert']);
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }
}
