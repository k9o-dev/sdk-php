<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\HttpClientConfig;
use K9o\K9oClient;
use K9o\KError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** client->approvals, client->approval_policies, client->schema->collections: wire shape and client-side validation. */
final class ApprovalsTest extends TestCase
{
    private const CRITERIA = ['eq' => ['change.table', 'orders']];
    private const PREDICATE = ['gt' => ['change.row.amount', 1000]];

    /** @var list<array{method:string,url:string,body:?string}> */
    private array $calls = [];

    private function client(string $body = '{"data":{},"meta":{}}', ?string $project_slug = 'portal'): K9oClient
    {
        $this->calls = [];
        $transport = function (string $m, string $u, array $h, ?string $b) use ($body): array {
            $this->calls[] = ['method' => $m, 'url' => $u, 'body' => $b];
            return ['status' => 200, 'body' => $body, 'location' => null, 'error' => ''];
        };
        $cfg = new HttpClientConfig(base_url: 'https://acme.api-de01.k9o.io', api_key: 'k', project_slug: $project_slug, transport: $transport);
        $r = new \ReflectionClass(K9oClient::class);
        $c = $r->newInstanceWithoutConstructor();
        $r->getConstructor()->invoke($c, $cfg);
        return $c;
    }

    /** Asserts the last request: method, path, query and the raw JSON body (null: no body). */
    private function assertSent(string $method, string $path, array $query = [], ?string $body = null): void
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
            $this->assertSame($body, $call['body']);
        }
    }

    public function testListWire(): void
    {
        $c = $this->client();
        $c->approvals->list();
        $this->assertSent('GET', '/@portal/pending-approvals');
        $c->approvals->list('pending_approval', 20);
        $this->assertSent('GET', '/@portal/pending-approvals', ['status' => 'pending_approval', 'limit' => '20']);
    }

    public function testGetWireAndPathEncoding(): void
    {
        $c = $this->client();
        $c->approvals->get('apr_1');
        $this->assertSent('GET', '/@portal/pending-approvals/apr_1');
        $c->approvals->get('a/b c');
        $this->assertSame('/@portal/pending-approvals/a%2Fb%20c', parse_url(end($this->calls)['url'], PHP_URL_PATH));
    }

    public function testApproveWire(): void
    {
        $c = $this->client();
        $c->approvals->approve('apr_1');
        $this->assertSent('POST', '/@portal/pending-approvals/apr_1/approve', [], '{}');
        $c->approvals->approve('apr_1', 'ok');
        $this->assertSent('POST', '/@portal/pending-approvals/apr_1/approve', [], '{"reason":"ok"}');
    }

    public function testRejectWire(): void
    {
        $c = $this->client();
        $c->approvals->reject('apr_1', 'duplicate');
        $this->assertSent('POST', '/@portal/pending-approvals/apr_1/reject', [], '{"reason":"duplicate"}');
    }

    public function testBulkWire(): void
    {
        $c = $this->client();
        $crit = '{"eq":["change.table","orders"]}';
        $c->approvals->bulk('approve', self::CRITERIA);
        $this->assertSent('POST', '/@portal/pending-approvals/bulk', [], '{"action":"approve","criteria":' . $crit . '}');
        $c->approvals->bulk('approve', self::CRITERIA, 'fine');
        $this->assertSent('POST', '/@portal/pending-approvals/bulk', [], '{"action":"approve","criteria":' . $crit . ',"reason":"fine"}');
        $c->approvals->bulk('reject', self::CRITERIA, 'stale');
        $this->assertSent('POST', '/@portal/pending-approvals/bulk', [], '{"action":"reject","criteria":' . $crit . ',"reason":"stale"}');
        $c->approvals->bulk('approve', new \stdClass());
        $this->assertSent('POST', '/@portal/pending-approvals/bulk', [], '{"action":"approve","criteria":{}}');
    }

    public function testPoliciesWire(): void
    {
        $c = $this->client();
        $c->approval_policies->list();
        $this->assertSent('GET', '/@portal/approval-policies');
        $c->approval_policies->create('orders', 'update', 'queue', self::PREDICATE);
        $this->assertSent('POST', '/@portal/approval-policies', [],
            '{"collection":"orders","operation":"update","action":"queue","predicate":{"gt":["change.row.amount",1000]}}');
        $c->approval_policies->delete('apo_1');
        $this->assertSent('DELETE', '/@portal/approval-policies/apo_1');
    }

    public function testSchemaCollectionsWire(): void
    {
        $c = $this->client();
        $c->schema->collections->list();
        $this->assertSent('GET', '/@portal/schema/collections');
        $c->schema->collections->list(2, 'dGFza3M');
        $this->assertSent('GET', '/@portal/schema/collections', ['limit' => '2', 'cursor' => 'dGFza3M']);
    }

    public function testSchemaPageExposesCursor(): void
    {
        $c = $this->client('{"data":[{"name":"orders","rowCount":1}],"meta":{"cursor":{"next":"b3JkZXJz","totalCount":null}}}');
        $res = $c->schema->collections->list(1);
        $this->assertSame('orders', $res->data[0]['name']);
        $this->assertSame('b3JkZXJz', $res->meta->cursor->next);
    }

    public function testApproveKeepsPendingStatus(): void
    {
        $c = $this->client('{"data":{"id":"apr_1","status":"pending_approval"},"meta":{}}');
        $this->assertSame('pending_approval', $c->approvals->approve('apr_1')->data['status']);
    }

    private function assertInvalid(callable $call): void
    {
        $c = $this->client();
        $this->calls = [];
        try {
            $call($c);
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        }
        $this->assertSame([], $this->calls);
    }

    /** @return list<array{mixed}> */
    public static function badLimits(): array
    {
        return [[0], [201], [-1], [1.5], ['10'], [true]];
    }

    #[DataProvider('badLimits')]
    public function testBadLimits(mixed $limit): void
    {
        $this->assertInvalid(fn (K9oClient $c) => $c->approvals->list(null, $limit));
        $this->assertInvalid(fn (K9oClient $c) => $c->schema->collections->list($limit));
    }

    public function testLimitBoundsAccepted(): void
    {
        $c = $this->client();
        foreach ([1, 200] as $limit) {
            $c->approvals->list(null, $limit);
            $this->assertSent('GET', '/@portal/pending-approvals', ['limit' => (string) $limit]);
        }
    }

    public function testBadCursor(): void
    {
        $this->assertInvalid(fn (K9oClient $c) => $c->schema->collections->list(null, 5));
    }

    public function testRejectNeedsReason(): void
    {
        foreach ([null, '', '   ', 5] as $reason) {
            $this->assertInvalid(fn (K9oClient $c) => $c->approvals->reject('apr_1', $reason));
        }
    }

    public function testIdsMustBeNonEmptyStrings(): void
    {
        foreach (['', null, 5] as $bad) {
            $this->assertInvalid(fn (K9oClient $c) => $c->approvals->get($bad));
            $this->assertInvalid(fn (K9oClient $c) => $c->approvals->approve($bad));
            $this->assertInvalid(fn (K9oClient $c) => $c->approvals->reject($bad, 'x'));
            $this->assertInvalid(fn (K9oClient $c) => $c->approval_policies->delete($bad));
        }
    }

    public function testBulkValidation(): void
    {
        foreach (['', 'delete', null, 'APPROVE'] as $action) {
            $this->assertInvalid(fn (K9oClient $c) => $c->approvals->bulk($action, self::CRITERIA, 'x'));
        }
        foreach ([null, [], [self::CRITERIA], 'eq', 5] as $criteria) {
            $this->assertInvalid(fn (K9oClient $c) => $c->approvals->bulk('approve', $criteria));
        }
        foreach ([null, '', '  '] as $reason) {
            $this->assertInvalid(fn (K9oClient $c) => $c->approvals->bulk('reject', self::CRITERIA, $reason));
        }
    }

    public function testPolicyValidation(): void
    {
        foreach (['', '1orders', 'or-ders', 'a b', str_repeat('x', 64), "orders\n", null, 5] as $collection) {
            $this->assertInvalid(fn (K9oClient $c) => $c->approval_policies->create($collection, 'update', 'queue', self::PREDICATE));
        }
        foreach (['', 'upsert', null, 'UPDATE'] as $operation) {
            $this->assertInvalid(fn (K9oClient $c) => $c->approval_policies->create('orders', $operation, 'queue', self::PREDICATE));
        }
        foreach (['', 'approve', null] as $action) {
            $this->assertInvalid(fn (K9oClient $c) => $c->approval_policies->create('orders', 'update', $action, self::PREDICATE));
        }
        foreach ([null, [], [self::PREDICATE], 'gt', 5, true] as $predicate) {
            $this->assertInvalid(fn (K9oClient $c) => $c->approval_policies->create('orders', 'update', 'queue', $predicate));
        }
    }

    public function testPolicyCollectionBoundsAccepted(): void
    {
        $c = $this->client();
        foreach (['_orders', 'Orders1', str_repeat('x', 63)] as $collection) {
            $c->approval_policies->create($collection, 'insert', 'queue', self::PREDICATE);
            $this->assertSame($collection, json_decode((string) end($this->calls)['body'], true)['collection']);
        }
    }

    public function testRequiresProjectSlug(): void
    {
        $c = $this->client(project_slug: null);
        foreach ([fn () => $c->approvals->list(), fn () => $c->approval_policies->list(), fn () => $c->schema->collections->list()] as $call) {
            try {
                $call();
                $this->fail('expected KError');
            } catch (KError $e) {
                $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
            }
        }
        $this->assertSame([], $this->calls);
    }

    public function testEveryPublicMethodIsCovered(): void
    {
        $names = function (string $class): array {
            $m = array_map(fn (\ReflectionMethod $m) => $m->getName(),
                array_filter((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC), fn ($m) => !$m->isConstructor()));
            sort($m);
            return $m;
        };
        $this->assertSame(['approve', 'bulk', 'get', 'list', 'reject'], $names(\K9o\Approvals::class));
        $this->assertSame(['create', 'delete', 'list'], $names(\K9o\ApprovalPolicies::class));
        $this->assertSame(['list'], $names(\K9o\SchemaCollections::class));
    }
}
