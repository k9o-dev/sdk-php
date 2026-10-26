<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\HttpClientConfig;
use K9o\K9oClient;
use K9o\KError;
use K9o\Region;
use K9o\UrlGrammar;
use PHPUnit\Framework\TestCase;

/**
 * Wire contract: every method that sends a body, plus every method whose path or query was corrected to the
 * gateway's routes, is checked for the exact HTTP method, path, query and JSON body it sends. The expected values
 * are the gateway's (services/gateway/openapi.json and the route handlers), not the SDK's own.
 */
final class ContractTest extends TestCase
{
    /**
     * Routes the SDK calls that are not in this tree's OpenAPI yet. Remove the entry when the route lands
     * (testPendingRoutesAreNotYetInOpenapi fails once it is declared).
     */
    private const PENDING_ROUTES = [];

    public function testPendingRoutesAreNotYetInOpenapi(): void
    {
        $spec = dirname(__DIR__, 3) . '/services/gateway/openapi.json';
        if (!is_file($spec)) {
            $this->markTestSkipped('gateway OpenAPI not found at ' . $spec);
        }
        $paths = json_decode((string) file_get_contents($spec), true)['paths'];
        $this->addToAssertionCount(1); // PENDING_ROUTES may be empty
        foreach (self::PENDING_ROUTES as $key => $why) {
            [$method, $template] = explode(' ', $key, 2);
            $this->assertArrayNotHasKey(strtolower($method), $paths[$template] ?? [],
                "$key is now in the gateway OpenAPI ($why): remove it from PENDING_ROUTES");
        }
    }

    /** @var list<array{method:string,url:string,headers:list<string>,body:?string}> */
    private array $calls = [];

    private function client(): K9oClient
    {
        $this->calls = [];
        $transport = function (string $m, string $u, array $h, ?string $b): array {
            $this->calls[] = ['method' => $m, 'url' => $u, 'headers' => $h, 'body' => $b];
            return ['status' => 200, 'body' => '{"data":{},"meta":{}}', 'location' => null, 'error' => ''];
        };
        $cfg = new HttpClientConfig(base_url: 'https://acme.api-de01.k9o.io', api_key: 'k', project_slug: 'portal', transport: $transport);
        $r = new \ReflectionClass(K9oClient::class);
        $c = $r->newInstanceWithoutConstructor();
        $r->getConstructor()->invoke($c, $cfg);
        return $c;
    }

    /** Asserts the last request's method, path, query and decoded JSON body (null: no body). */
    private function assertSent(string $method, string $path, array $query = [], ?array $body = null): void
    {
        $call = end($this->calls);
        $this->assertNotFalse($call, 'no request was sent');
        $u = parse_url($call['url']);
        parse_str($u['query'] ?? '', $q);
        $this->assertSame([$method, $path], [$call['method'], $u['path']]);
        $this->assertEquals($query, $q);
        if ($body === null) {
            $this->assertNull($call['body']);
        } else {
            $this->assertSame(json_encode($body), json_encode(json_decode((string) $call['body'], true)));
        }
    }

    private function header(string $name): ?string
    {
        $call = end($this->calls);
        foreach ($call['headers'] as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return null;
    }

    private function assertRefused(callable $fn): void
    {
        try {
            $fn();
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        }
    }

    // projects ---------------------------------------------------------------------------------------------------

    public function testProjectsCreateSendsDisplayNameAndWireOptions(): void
    {
        $c = $this->client();
        $c->projects->create('Demo', ['slug' => 'demo', 'custody_mode' => 'PLATFORM_MANAGED', 'region' => 'de01', 'idempotency_key' => 'k1', 'processing_contexts' => ['a', 'b']]);
        $this->assertSent('POST', '/projects', [], [
            'displayName' => 'Demo', 'slug' => 'demo', 'custodyMode' => 'PLATFORM_MANAGED', 'region' => 'de01',
            'idempotencyKey' => 'k1', 'processingContexts' => ['a', 'b'],
        ]);
        $this->assertRefused(fn () => $c->projects->create('Demo', ['name' => 'x']));
    }

    public function testProjectsGetAndUpdateUseTheProjectsRoutes(): void
    {
        $c = $this->client();
        $c->projects->get('demo');
        $this->assertSent('GET', '/projects/demo');
        $c->projects->update('demo', ['display_name' => 'Renamed', 'description' => null]);
        $this->assertSent('PATCH', '/projects/demo', [], ['displayName' => 'Renamed', 'description' => null]);
    }

    // functions --------------------------------------------------------------------------------------------------

    public function testFunctionsUploadArtifactSendsRawBytesWithTheArtifactContentType(): void
    {
        $c = $this->client();
        $c->functions->upload_artifact("\0asm", 'wasm');
        $call = end($this->calls);
        $this->assertSame(['POST', 'https://acme.api-de01.k9o.io/@portal/functions/artifacts'], [$call['method'], $call['url']]);
        $this->assertSame("\0asm", $call['body']);
        $this->assertSame('application/wasm', $this->header('content-type'));
        $c->functions->upload_artifact('tar-bytes', 'tar');
        $this->assertSame('application/x-tar', $this->header('content-type'));
        $this->assertRefused(fn () => $c->functions->upload_artifact('x', 'zip'));
    }

    public function testFunctionsDeployReferencesAnUploadedArtifact(): void
    {
        $this->client()->functions->deploy('hello', 'bun', 'sandbox', 'functions/p/abc.tar', '1', 'd');
        $this->assertSent('POST', '/@portal/functions', [], [
            'name' => 'hello', 'runtime' => 'bun', 'tier' => 'sandbox', 'artifactKey' => 'functions/p/abc.tar',
            'version' => '1', 'description' => 'd',
        ]);
    }

    public function testFunctionsListQuery(): void
    {
        $this->client()->functions->list('active', 10, 'c');
        $this->assertSent('GET', '/@portal/functions', ['status' => 'active', 'limit' => '10', 'cursor' => 'c']);
    }

    public function testFunctionsInvokeWrapsThePayload(): void
    {
        $c = $this->client();
        $c->functions->invoke('hello', ['a' => 1]);
        $this->assertSent('POST', '/@portal/functions/hello/invoke', [], ['payload' => ['a' => 1]]);
        $c->functions->invoke('hello', ['a' => 1], ['background' => true, 'on_behalf_of' => 'u_1', 'confined_to' => 'f', 'delegation' => ['token' => 't']]);
        $this->assertSent('POST', '/@portal/functions/hello/invoke', [], [
            'payload' => ['a' => 1], 'background' => true, 'onBehalfOf' => 'u_1', 'confinedTo' => 'f', 'delegation' => ['token' => 't'],
        ]);
        $this->assertRefused(fn () => $c->functions->invoke('hello', 1, ['bogus' => 1]));
    }

    // policies ---------------------------------------------------------------------------------------------------

    public function testPoliciesBodiesCarryAPolicyDocument(): void
    {
        $c = $this->client();
        $c->policies->create(['collections' => ['posts' => ['read' => 'any']]], null, 'json');
        $this->assertSent('POST', '/@portal/policies', [], ['policy' => ['collections' => ['posts' => ['read' => 'any']]], 'editorMode' => 'json']);
        $c->policies->validate(['collections' => ['posts' => ['read' => 'any']]]);
        $this->assertSent('POST', '/@portal/policies/validate', [], ['policy' => ['collections' => ['posts' => ['read' => 'any']]]]);
        $c->policies->update('pol_1', ['collections' => ['posts' => ['read' => 'any']]], [1]);
        $this->assertSent('PATCH', '/@portal/policies/pol_1', [], ['policy' => ['collections' => ['posts' => ['read' => 'any']]], 'rules' => [1]]);
    }

    public function testAnEmptyPolicyDocumentIsAnObjectOnTheWire(): void
    {
        $c = $this->client();
        $c->policies->validate([]);
        $this->assertSame('{"policy":{}}', end($this->calls)['body']);
    }

    // audit ------------------------------------------------------------------------------------------------------

    public function testAuditQueryUsesTheGatewayFilterNames(): void
    {
        $c = $this->client();
        $c->audit->query(['event_type' => 'auth.sign_in', 'actor_id' => 'u_1', 'after' => '2026-01-01', 'before' => '2026-02-01', 'limit' => 5, 'cursor' => 'c']);
        $this->assertSent('GET', '/@portal/audit-log', [
            'eventType' => 'auth.sign_in', 'actorId' => 'u_1', 'after' => '2026-01-01', 'before' => '2026-02-01', 'limit' => '5', 'cursor' => 'c',
        ]);
        $this->assertRefused(fn () => $c->audit->query(['since' => 'x']));
    }

    public function testOrgAuditLogSendsOnlyWhatTheRouteReads(): void
    {
        $this->client()->org->audit_log->query('org.updated', 10);
        $this->assertSent('GET', '/audit-log', ['type' => 'org.updated', 'limit' => '10']);
    }

    // orgs and org -----------------------------------------------------------------------------------------------

    public function testOrgsGetValidatesTheSlug(): void
    {
        $c = $this->client();
        $c->orgs->get('acme');
        $this->assertSent('GET', '/orgs/acme');
        $this->expectException(KError::class);
        $c->orgs->get('Bad Slug');
    }

    public function testOrgMembersAndGroups(): void
    {
        $c = $this->client();
        $c->org->members->list('active', 'u', 'ORG_ADMIN', 'accepted', 's');
        $this->assertSent('GET', '/members', ['userId' => 'u', 'role' => 'ORG_ADMIN', 'acceptanceStatus' => 'accepted', 'status' => 'active', 'state' => 's']);
        $c->org->members->invite('a@b.co', 'ORG_MEMBER');
        $this->assertSent('POST', '/members', [], ['email' => 'a@b.co', 'role' => 'ORG_MEMBER']);
        $c->org->groups->list('u_1');
        $this->assertSent('GET', '/groups', ['userId' => 'u_1']);
    }

    public function testOrgRoleGrantsUseTheRoleGrantsRoute(): void
    {
        $c = $this->client();
        $c->org->gate->roles->list();
        $this->assertSent('GET', '/role-grants');
        $c->org->gate->roles->grant('u_1', 'ORG_ADMIN', 'r');
        $this->assertSent('POST', '/role-grants', [], ['userId' => 'u_1', 'role' => 'ORG_ADMIN', 'decisionReason' => 'r']);
        $c->org->gate->roles->get('g_1');
        $this->assertSent('GET', '/role-grants/g_1');
        $c->org->gate->roles->revoke('g_1');
        $this->assertSent('DELETE', '/role-grants/g_1');
        $c->org->gate->roles->revoke('g_1', 'r');
        $this->assertSent('DELETE', '/role-grants/g_1', [], ['decisionReason' => 'r']);
    }

    public function testOrgIdentityProvidersUseTheIdentityProvidersRoute(): void
    {
        $c = $this->client();
        $c->org->gate->idp_providers->list();
        $this->assertSent('GET', '/identity-providers');
        $c->org->gate->idp_providers->create('saml', 'Okta', 'cid', 'ref_1', 'free_tier_ok', ['enabled' => true]);
        $this->assertSent('POST', '/identity-providers', [], [
            'idpProvider' => 'saml', 'displayName' => 'Okta', 'clientId' => 'cid', 'clientSecretRef' => 'ref_1',
            'tierGating' => 'free_tier_ok', 'enabled' => true,
        ]);
        $c->org->gate->idp_providers->get('idp_1');
        $this->assertSent('GET', '/identity-providers/idp_1');
        $c->org->gate->idp_providers->configure('idp_1', ['enabled' => false]);
        $this->assertSent('PATCH', '/identity-providers/idp_1', [], ['enabled' => false]);
        $c->org->gate->idp_providers->delete('idp_1');
        $this->assertSent('DELETE', '/identity-providers/idp_1');
    }

    public function testOrgSmtpSendsPasswordRefAndFromAddress(): void
    {
        $c = $this->client();
        $c->org->smtp->set('smtp.x.io', 587, 'u', 'ref_1', 'a@b.co', 'A');
        $this->assertSent('PATCH', '/org', [], ['smtpConfig' => [
            'host' => 'smtp.x.io', 'port' => 587, 'username' => 'u', 'passwordRef' => 'ref_1', 'fromAddress' => 'a@b.co', 'fromName' => 'A',
        ]]);
        $c->org->smtp->test('a@b.co', 's');
        $this->assertSent('POST', '/org/smtp/test-email', [], ['to' => 'a@b.co', 'subject' => 's']);
        $c->org->smtp->unset();
        $this->assertSent('PATCH', '/org', [], ['smtpConfig' => null]);
    }

    public function testOrgDefaultLlmUsesTheDefaultLlmRoute(): void
    {
        $c = $this->client();
        $c->org->default_llm->get();
        $this->assertSent('GET', '/default-llm');
        $c->org->default_llm->set('llama-3-8b');
        $this->assertSent('PATCH', '/default-llm', [], ['modelId' => 'llama-3-8b']);
    }

    // me ---------------------------------------------------------------------------------------------------------

    public function testMeProfileUpdateSendsOnlyGivenFields(): void
    {
        $c = $this->client();
        $c->me->profile->update(['display_name' => 'A']);
        $this->assertSent('PATCH', '/me', [], ['displayName' => 'A']);
        $c->me->profile->update(['display_name' => null, 'timezone' => 'Europe/Berlin']);
        $this->assertSent('PATCH', '/me', [], ['displayName' => null, 'timezone' => 'Europe/Berlin']);
        $this->assertRefused(fn () => $c->me->profile->update(['nickname' => 'x']));
    }

    public function testMeSessionsUseThePostRevokeRoutes(): void
    {
        $c = $this->client();
        $c->me->sessions->revoke('s_1');
        $this->assertSent('POST', '/auth/sessions/s_1/revoke');
        $c->me->sessions->revoke_all();
        $this->assertSent('POST', '/me/sessions/revoke-all');
    }

    public function testMeApiTokensCreateSendsLabelAndScopeManifest(): void
    {
        $scope = ['operations' => ['read'], 'projects' => ['portal'], 'resourceClasses' => null, 'collections' => null, 'cidrs' => null];
        $this->client()->me->api_tokens->create('ci', $scope, 3600);
        $this->assertSent('POST', '/me/api-tokens', [], ['label' => 'ci', 'scopeManifest' => $scope, 'ttlSeconds' => 3600]);
    }

    public function testRemovedMethodsAreGone(): void
    {
        $c = $this->client();
        $this->assertFalse(property_exists($c->me, 'notifications'));
        $this->assertFalse(property_exists($c->me, 'audit_log'));
        $this->assertFalse(property_exists($c->me, 'data_export'));
        foreach ([[$c->gate, 'hosted'], [$c->gate, 'verify_session'], [$c->realtime, 'replay'], [$c->realtime, 'subscribe_poll'],
                  [$c->org->groups, 'create'], [$c->org->groups, 'delete'], [$c->org->members, 'get'], [$c->org->smtp, 'get']] as [$obj, $m]) {
            $this->assertFalse(method_exists($obj, $m), get_class($obj) . '::' . $m);
        }
    }

    // gate -------------------------------------------------------------------------------------------------------

    public function testGateBodies(): void
    {
        $c = $this->client();
        $c->gate->sign_up('a@b.co', 'pw', 'A');
        $this->assertSent('POST', '/@portal/gate/signup', [], ['email' => 'a@b.co', 'password' => 'pw', 'displayName' => 'A']);
        $c->gate->sign_in('a@b.co', 'pw');
        $this->assertSent('POST', '/@portal/gate/signin', [], ['email' => 'a@b.co', 'password' => 'pw']);
        $c->gate->sign_out('refresh-token-1');
        $this->assertSent('POST', '/@portal/gate/signout', [], ['refreshToken' => 'refresh-token-1']);
        $c->gate->refresh_token('refresh-token-1');
        $this->assertSent('POST', '/@portal/gate/refresh', [], ['refreshToken' => 'refresh-token-1']);
        $c->gate->whoami();
        $this->assertSent('GET', '/@portal/gate/whoami');
        $this->expectException(\ArgumentCountError::class);
        $c->gate->sign_out();
    }

    // compute, studio, realtime ----------------------------------------------------------------------------------

    public function testLlmsInvokePutsParamsBesideThePrompt(): void
    {
        $c = $this->client();
        $c->compute->llms->list();
        $this->assertSent('GET', '/@portal/compute/llms');
        $c->compute->llms->invoke('llama-3-8b', 'hi', ['max_tokens' => 10, 'temperature' => 0.2, 'usage_type' => 'u']);
        $this->assertSent('POST', '/@portal/compute/llms/llama-3-8b/invoke', [], ['prompt' => 'hi', 'maxTokens' => 10, 'temperature' => 0.2, 'usageType' => 'u']);
        $c->compute->llms->invoke('llama-3-8b', 'hi', ['maxTokens' => 3]);
        $this->assertSent('POST', '/@portal/compute/llms/llama-3-8b/invoke', [], ['prompt' => 'hi', 'maxTokens' => 3]);
        $this->assertRefused(fn () => $c->compute->llms->invoke('llama-3-8b', 'hi', ['top_k' => 1]));
    }

    public function testLlmsInvokeStreamSendsTheSameBody(): void
    {
        $c = $this->client();
        iterator_to_array($c->compute->llms->invoke_stream('llama-3-8b', 'hi', ['max_tokens' => 4]));
        $this->assertSent('POST', '/@portal/compute/llms/llama-3-8b/invoke-stream', [], ['prompt' => 'hi', 'maxTokens' => 4]);
    }

    public function testWorkflowsTriggerWrapsTriggerContext(): void
    {
        $c = $this->client();
        $c->studio->workflows->trigger('wfl_1', ['k' => 'v']);
        $this->assertSent('POST', '/@portal/workflows/wfl_1/runs', [], ['triggerContext' => ['k' => 'v']]);
        $c->studio->workflows->trigger('wfl_1');
        $this->assertSame('{"triggerContext":{}}', end($this->calls)['body']);
    }

    public function testWorkflowListFiltersAreTheOnesTheRouteReads(): void
    {
        $c = $this->client();
        $c->studio->workflows->list(['status' => 'active', 'limit' => 3, 'cursor' => 'c']);
        $this->assertSent('GET', '/@portal/workflows', ['status' => 'active', 'limit' => '3', 'cursor' => 'c']);
        $this->assertRefused(fn () => $c->studio->workflows->list(['triggerType' => 'manual']));
        $c->studio->workflows->runs->list('wfl_1', 2, 'c');
        $this->assertSent('GET', '/@portal/workflows/wfl_1/runs', ['limit' => '2', 'cursor' => 'c']);
    }

    public function testDashboardsListFilters(): void
    {
        $c = $this->client();
        $c->studio->dashboards->list(['status' => 'active', 'pinned_only' => true, 'limit' => 2, 'cursor' => 'c']);
        $this->assertSent('GET', '/@portal/dashboards', ['status' => 'active', 'pinnedOnly' => 'true', 'limit' => '2', 'cursor' => 'c']);
    }

    public function testWorkflowAndDashboardBodiesPassThroughUnchanged(): void
    {
        $c = $this->client();
        $wf = ['name' => 'w', 'triggerType' => 'manual', 'triggerConfig' => ['k' => 1], 'actions' => [['index' => 0, 'action_type' => 'branch', 'config' => ['k' => 1]]]];
        $c->studio->workflows->create($wf);
        $this->assertSent('POST', '/@portal/workflows', [], $wf);
        $dash = ['name' => 'd', 'widgets' => [['id' => 'w1', 'widget_type' => 'kpi_card', 'data_source' => ['mode' => 'view', 'config' => ['k' => 1]]]]];
        $c->studio->dashboards->create($dash);
        $this->assertSent('POST', '/@portal/dashboards', [], $dash);
    }

    // migrations, storage, ai, vault -----------------------------------------------------------------------------

    public function testMigrationsBodies(): void
    {
        $c = $this->client();
        $migs = [['version' => 1, 'slug' => 'init', 'up' => [], 'down' => []]];
        $c->migrations->apply($migs);
        $this->assertSent('POST', '/@portal/migrations/apply', [], ['migrations' => $migs]);
        $c->migrations->queue($migs);
        $this->assertSent('POST', '/@portal/migrations/queue', [], ['migrations' => $migs]);
        $c->migrations->reject_pending('pm_1', 'no');
        $this->assertSent('POST', '/@portal/migrations/pending/pm_1/reject', [], ['reason' => 'no']);
    }

    public function testStorageBodies(): void
    {
        $c = $this->client();
        $c->storage->create_bucket('docs', ['public' => false, 'file_size_limit' => 5]);
        $this->assertSent('POST', '/@portal/storage/buckets', [], ['name' => 'docs', 'public' => false, 'fileSizeLimit' => 5]);
        $c->storage->from('docs')->create_signed_url('a.txt', 600, true);
        $this->assertSent('POST', '/@portal/storage/buckets/docs/objects/sign', [], ['path' => 'a.txt', 'expiresIn' => 600, 'download' => true]);
    }

    public function testQueryAndSearchWire(): void
    {
        $c = $this->client();
        $c->query("SELECT * FROM users WHERE data->>'role' = :role", ['role' => 'admin']);
        $this->assertSent('POST', '/@portal/query', [], ['sql' => "SELECT * FROM users WHERE data->>'role' = \$1", 'params' => ['admin']]);
        $c->search('articles', column: 'body', q: 'climate', limit: 5);
        $this->assertSent('GET', '/@portal/data/articles', ['textSearch' => 'body:climate', 'limit' => '5']);
    }

    public function testAiEmbedBody(): void
    {
        $this->client()->ai->embed(['a', 'b'], 'nomic-embed-text-v1.5');
        $this->assertSent('POST', '/@portal/ai/embeddings', [], ['model' => 'nomic-embed-text-v1.5', 'input' => ['a', 'b']]);
    }

    public function testAiGenerateBody(): void
    {
        $c = $this->client();
        $c->ai->generate('Say hi');
        $this->assertSent('POST', '/@portal/ai/generate', [], ['prompt' => 'Say hi']);
        $c->ai->generate('p', 'llama-3-8b', 20, 0.5, 'ai.generate');
        $this->assertSent('POST', '/@portal/ai/generate', [], [
            'prompt' => 'p', 'model' => 'llama-3-8b', 'maxTokens' => 20, 'temperature' => 0.5, 'usageType' => 'ai.generate',
        ]);
    }

    public function testVaultSecretBodies(): void
    {
        $c = $this->client();
        $c->vault->secrets->create('db/pw', 'x', 'de01');
        $this->assertSent('POST', '/@portal/vault/secrets/db%2Fpw', ['region' => 'de01'], ['value' => 'eA==']);
        $c->vault->secrets->move('db/pw', 'z', 'de01');
        $this->assertSent('POST', '/@portal/vault/secrets/db%2Fpw', ['op' => 'move', 'region' => 'de01'], ['newPath' => 'z']);
    }

    // realtime ---------------------------------------------------------------------------------------------------

    public function testRealtimeSubscribeSendsTopicAndFilterAndSkipsResyncFrames(): void
    {
        $sse = "event: resync\ndata: {\"reason\":\"cursor-evicted\",\"action\":\"reread-source\"}\n\n"
            . "id: 1\nevent: row_inserted\ndata: {\"topic\":\"collections.x.*\",\"eventType\":\"row_inserted\",\"payload\":{},\"occurredAt\":\"t\"}\n\n";
        $calls = [];
        $transport = function (string $m, string $u, array $h, ?string $b) use ($sse, &$calls): array {
            $calls[] = [$m, $u];
            return ['status' => 200, 'body' => $sse, 'location' => null, 'error' => ''];
        };
        $cfg = new HttpClientConfig(base_url: 'https://acme.api-de01.k9o.io', api_key: 'k', project_slug: 'portal', transport: $transport);
        $r = new \ReflectionClass(K9oClient::class);
        $c = $r->newInstanceWithoutConstructor();
        $r->getConstructor()->invoke($c, $cfg);
        $events = iterator_to_array($c->realtime->subscribe('collections.x.*', ['a' => 1]), false);
        $u = parse_url($calls[0][1]);
        parse_str($u['query'], $q);
        $this->assertSame(['GET', '/@portal/realtime/subscribe'], [$calls[0][0], $u['path']]);
        $this->assertSame(['topic' => 'collections.x.*', 'filter' => '{"a":1}'], $q);
        $this->assertSame(['row_inserted'], array_map(fn ($e) => $e['eventType'], $events));
    }

    // streams ----------------------------------------------------------------------------------------------------

    public function testStreamsWireShapes(): void
    {
        $c = $this->client();
        $c->streams->create('Orders feed', 1073741824, 3600);
        $this->assertSent('POST', '/@portal/data-streams/user', [], ['displayName' => 'Orders feed', 'maxBytes' => 1073741824, 'retentionSeconds' => 3600]);
        $c->streams->create('Orders feed', 1073741824);
        $this->assertSent('POST', '/@portal/data-streams/user', [], ['displayName' => 'Orders feed', 'maxBytes' => 1073741824]);
        $c->streams->list();
        $this->assertSent('GET', '/@portal/data-streams/user');
        $c->streams->get('orders-feed');
        $this->assertSent('GET', '/@portal/data-streams/user/orders-feed');
        $c->streams->delete('orders-feed');
        $this->assertSent('DELETE', '/@portal/data-streams/user/orders-feed');
        $c->streams->publish('orders-feed', ['id' => 1], 'eu.new');
        $this->assertSent('POST', '/@portal/data-streams/user/orders-feed/publish', [], ['data' => ['id' => 1], 'subject' => 'eu.new']);
        $c->streams->publish('orders-feed', [1, 2]);
        $this->assertSent('POST', '/@portal/data-streams/user/orders-feed/publish', [], ['data' => [1, 2]]);
        $c->streams->replay('orders-feed', 5, 9, 3);
        $this->assertSent('POST', '/@portal/data-streams/user/orders-feed/replay', [], ['fromSequence' => 5, 'untilSequence' => 9, 'limit' => 3]);
        $c->streams->replay('orders-feed');
        $call = end($this->calls);
        $this->assertSame('{}', $call['body']);
        $this->assertSame('/@portal/data-streams/user/orders-feed/replay', parse_url($call['url'], PHP_URL_PATH));
    }

    // approvals, approval policies, schema (the OpenAPI entries declare no bodies; these are the ones the handlers read)

    public function testApprovalsWireShapes(): void
    {
        $c = $this->client();
        $criteria = ['eq' => ['change.table', 'orders']];
        $c->approvals->list('pending_approval', 20);
        $this->assertSent('GET', '/@portal/pending-approvals', ['status' => 'pending_approval', 'limit' => '20']);
        $c->approvals->get('apr_1');
        $this->assertSent('GET', '/@portal/pending-approvals/apr_1');
        $c->approvals->approve('apr_1', 'ok');
        $this->assertSent('POST', '/@portal/pending-approvals/apr_1/approve', [], ['reason' => 'ok']);
        $c->approvals->reject('apr_1', 'duplicate');
        $this->assertSent('POST', '/@portal/pending-approvals/apr_1/reject', [], ['reason' => 'duplicate']);
        $c->approvals->bulk('reject', $criteria, 'stale');
        $this->assertSent('POST', '/@portal/pending-approvals/bulk', [], ['action' => 'reject', 'criteria' => $criteria, 'reason' => 'stale']);
        $c->approval_policies->list();
        $this->assertSent('GET', '/@portal/approval-policies');
        $c->approval_policies->create('orders', 'update', 'queue', ['gt' => ['change.row.amount', 1000]]);
        $this->assertSent('POST', '/@portal/approval-policies', [], [
            'collection' => 'orders', 'operation' => 'update', 'action' => 'queue', 'predicate' => ['gt' => ['change.row.amount', 1000]],
        ]);
        $c->approval_policies->delete('apo_1');
        $this->assertSent('DELETE', '/@portal/approval-policies/apo_1');
        $c->schema->collections->list(10, 'abc');
        $this->assertSent('GET', '/@portal/schema/collections', ['limit' => '10', 'cursor' => 'abc']);
    }

    /** A new public Streams method must get a wire test above (subscribe is covered in StreamsTest). */
    public function testEveryPublicStreamsMethodIsCovered(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            array_filter((new \ReflectionClass(\K9o\Streams::class))->getMethods(\ReflectionMethod::IS_PUBLIC), fn ($m) => !$m->isConstructor())
        );
        sort($methods);
        $this->assertSame(['create', 'delete', 'get', 'list', 'publish', 'replay', 'subscribe'], $methods);
    }

    // hosts ------------------------------------------------------------------------------------------------------

    public function testParseApiHostReadsOrgRegionAndApexFromAnyApex(): void
    {
        $this->assertSame(['org_slug' => 'acme', 'family' => 'api', 'region' => 'de01', 'apex' => 'corp.internal'], UrlGrammar::parse_api_host('acme.api-de01.corp.internal'));
        $this->assertSame(['org_slug' => null, 'family' => 'api-staging', 'region' => null, 'apex' => 'k9o.example'], UrlGrammar::parse_api_host('api-staging.k9o.example'));
        foreach (['localhost', '127.0.0.1', 'example.com', 'acme.example.com', 'api', 'a..b'] as $host) {
            $this->assertNull(UrlGrammar::parse_api_host($host), $host);
        }
    }

    public function testOrgSlugIsComposedOntoAPlatformHostOfAnyApex(): void
    {
        $this->assertSame('https://acme.api.corp.internal', UrlGrammar::resolve_base_url('https://api.corp.internal', 'acme'));
        $this->assertSame('http://acme.api.localhost:8080', UrlGrammar::resolve_base_url('http://api.localhost:8080', 'acme'));
        $this->assertSame('http://localhost:8080', UrlGrammar::resolve_base_url('http://localhost:8080', 'acme'));
        $this->assertSame('https://acme.api.corp.internal', UrlGrammar::resolve_base_url('https://acme.api.corp.internal', 'other'));
        $this->assertSame('https://api.k9o.io', UrlGrammar::resolve_base_url(null));
        $this->assertSame('https://acme.api.k9o.io', UrlGrammar::resolve_base_url(null, 'acme'));
    }

    public function testRegionalOriginIsLearnedForAnyApex(): void
    {
        $base = 'https://acme.api.corp.internal';
        $this->assertSame('https://acme.api-de01.corp.internal',
            Region::origin_from_location('acme.api.corp.internal', 'https://acme.api-de01.corp.internal/', $base));
        foreach (['https://evil.api-de01.corp.internal/', 'https://acme.api-de01.k9o.io/', 'https://acme.api-staging-de01.corp.internal/',
                  'https://acme.api-de01.corp.internal:8443/', 'http://acme.api-de01.corp.internal/', 'https://acme.api-xx.corp.internal/'] as $loc) {
            $this->assertNull(Region::origin_from_location('acme.api.corp.internal', $loc, $base), $loc);
        }
        $this->assertNull(Region::origin_from_location('acme.api-de01.corp.internal', 'https://acme.api-fr01.corp.internal/', 'x'));
        $this->assertNull(Region::origin_from_location('api.corp.internal', 'https://acme.api-de01.corp.internal/', 'x'));
    }
}
