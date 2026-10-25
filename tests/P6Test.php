<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\Compute;
use K9o\K9oClient;
use K9o\KError;
use K9o\RealtimeTopic;
use PHPUnit\Framework\TestCase;

/**
 * P6 surface-family tests (studio / compute.llms / realtime / org
 * default-llm). Matches the package's
 * validation-only test philosophy (ext-curl round-trips are exercised by
 * integration tests once the gateway lands); here we verify pure-logic
 * surfaces — namespace composition, topic builders, project-context
 * enforcement, replay guard, and the closed model enum.
 */
class P6Test extends TestCase
{
    private function projectClient(): K9oClient
    {
        return K9oClient::create(['base_url' => 'https://acme.api.k9o.io', 'project_slug' => 'portal']);
    }

    public function testNamespacesComposeOnClient(): void
    {
        $c = $this->projectClient();
        $this->assertNotNull($c->studio);
        $this->assertNotNull($c->studio->workflows);
        $this->assertNotNull($c->studio->workflows->runs);
        $this->assertNotNull($c->studio->dashboards);
        $this->assertNotNull($c->compute);
        $this->assertNotNull($c->compute->llms);
        $this->assertNotNull($c->realtime);
        $this->assertNotNull($c->org);
        $this->assertNotNull($c->org->default_llm);
    }

    public function testRealtimeTopicBuilders(): void
    {
        $this->assertSame('collections.orders.*', RealtimeTopic::collection('orders'));
        $this->assertSame('studio.workflows.runs.*', RealtimeTopic::workflow_runs());
        $this->assertSame('studio.dashboards.refresh.dsh_x', RealtimeTopic::dashboard_refresh('dsh_x'));
        $this->assertSame('forge.functions.invocations.fn_x', RealtimeTopic::function_invocations('fn_x'));
        $this->assertSame('audit.org.role_granted', RealtimeTopic::audit_org('role_granted'));
    }

    public function testLlmModelIdsClosedEnum(): void
    {
        $this->assertContains('mistral-7b', Compute::LLM_MODEL_IDS);
        $this->assertContains('claude-3-5-sonnet', Compute::LLM_MODEL_IDS);
        $this->assertContains('text-embedding-3-large', Compute::LLM_MODEL_IDS);
        $this->assertCount(9, Compute::LLM_MODEL_IDS);
    }

    public function testRealtimeReplayRejectsNegativeSequence(): void
    {
        $c = $this->projectClient();
        try {
            $c->realtime->replay('studio.workflows.runs.*', -1);
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FILTER', $e->code);
        }
    }

    public function testWorkflowsRequireProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->studio->workflows->read('wfl_x');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testDashboardsRequireProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->studio->dashboards->read('dsh_x');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testLlmsRequireProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->compute->llms->read('mistral-7b');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testRealtimeRequiresProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'https://api.k9o.io']);
        try {
            $c->realtime->replay('studio.workflows.runs.*', 0);
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testInvokeStreamIsGenerator(): void
    {
        $c = $this->projectClient();
        // Calling invoke_stream returns a Generator without making a request
        // until iterated (lazy). Confirm the return type contract.
        $gen = $c->compute->llms->invoke_stream('gpt-4o', 'hi');
        $this->assertInstanceOf(\Generator::class, $gen);
    }
}
