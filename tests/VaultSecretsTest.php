<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\HttpClientConfig;
use K9o\K9oClient;
use K9o\KError;
use K9o\UrlGrammar;
use K9o\Vault\Secrets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * $client->vault->secrets->* tests (`services/gateway/src/routes/vault-secrets.ts`),
 * porting `packages/kibo/test/secrets.test.ts`'s wire-shape checklist to this
 * package's own "validation-only" test philosophy (ParityTest.php / P6Test.php:
 * "ext-curl round-trips exercised by integration tests once the gateway lands").
 *
 * HttpTransport talks ext-curl directly (no injectable transport / fetch-mock
 * seam exists anywhere in this package), so — matching the pattern
 * HttpTransport itself already uses for RetryTest.php (is_retryable /
 * compute_delay_ms exposed as public static purely for direct unit testing) —
 * Secrets exposes its pure request-shaping helpers (path_for / region_query /
 * reveal_header / encode_value / decode_value) as public static methods and
 * this suite exercises those directly. That covers URL construction, the
 * `region` query requirement, the base64 round-trip, and the reveal header
 * with zero network calls. The remaining tests confirm namespace composition
 * and the project-context guard that every method runs BEFORE it would ever
 * reach HttpTransport (same "validation-only" scope as every other resource's
 * tests in this package).
 */
final class VaultSecretsTest extends TestCase
{
    private function projectClient(): K9oClient
    {
        return K9oClient::create(['base_url' => 'https://acme.api.k9o.io', 'project_slug' => 'member-portal']);
    }

    private function noProjectClient(): K9oClient
    {
        return K9oClient::create(['base_url' => 'https://acme.api.k9o.io']);
    }

    // ---- namespace composition -------------------------------------------------

    public function testNamespaceComposesOnClient(): void
    {
        $c = $this->projectClient();
        $this->assertNotNull($c->vault);
        $this->assertNotNull($c->vault->secrets);
    }

    // ---- project-context guard (every method, run before any network call) ----

    /** @return array<string, array{0: callable(K9oClient): mixed}> */
    public static function operationsProvider(): array
    {
        return [
            'list' => [fn (K9oClient $c) => $c->vault->secrets->list()],
            'get' => [fn (K9oClient $c) => $c->vault->secrets->get('db/pw', 'us01')],
            'create' => [fn (K9oClient $c) => $c->vault->secrets->create('db/pw', 'hunter2', 'us01')],
            'reveal_challenge' => [fn (K9oClient $c) => $c->vault->secrets->reveal_challenge('db/pw', 'us01')],
            'reveal' => [fn (K9oClient $c) => $c->vault->secrets->reveal('db/pw', 'rvc_1', 'us01')],
            'update' => [fn (K9oClient $c) => $c->vault->secrets->update('db/pw', 'newpw', 'us01')],
            'rotate' => [fn (K9oClient $c) => $c->vault->secrets->rotate('db/pw', 'us01')],
            'move' => [fn (K9oClient $c) => $c->vault->secrets->move('db/pw', 'db/pw2', 'us01')],
            'delete' => [fn (K9oClient $c) => $c->vault->secrets->delete('db/pw', 'us01')],
        ];
    }

    #[DataProvider('operationsProvider')]
    public function testEachOperationRequiresProjectSlug(callable $call): void
    {
        $c = $this->noProjectClient();
        try {
            $call($c);
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    // ---- wire behaviour through an injected transport --------------------------

    /** @var list<array{method:string,url:string,headers:list<string>,body:?string}> */
    private array $calls = [];

    /** @param list<string> $bodies responses in order (last repeats) */
    private function wireClient(array $bodies, int $status = 200): K9oClient
    {
        $this->calls = [];
        $transport = function (string $m, string $u, array $h, ?string $b) use (&$bodies, $status): array {
            if ($m === 'HEAD') {
                return ['status' => 200, 'body' => '', 'location' => null, 'error' => '']; // region-discovery probe
            }
            $this->calls[] = ['method' => $m, 'url' => $u, 'headers' => $h, 'body' => $b];
            $body = count($bodies) > 1 ? array_shift($bodies) : $bodies[0];
            return ['status' => $status, 'body' => $body, 'location' => null, 'error' => ''];
        };
        $cfg = new HttpClientConfig(
            base_url: 'https://acme.api.k9o.io', api_key: 'k', project_slug: 'member-portal', transport: $transport
        );
        $r = new \ReflectionClass(K9oClient::class);
        $c = $r->newInstanceWithoutConstructor();
        $r->getConstructor()->invoke($c, $cfg);
        return $c;
    }

    /** @return array<string, mixed> */
    private static function summary(string $name): array
    {
        return ['id' => 'sec_abc', 'name' => $name, 'version' => 1];
    }

    private static function page(array $secrets, ?string $cursor = null): string
    {
        return json_encode(['data' => ['secrets' => $secrets, 'nextCursor' => $cursor], 'meta' => new \stdClass()]);
    }

    private function lastUrl(): string
    {
        return end($this->calls)['url'];
    }

    public function testGetReadsMetadataFromTheListRouteWithExactName(): void
    {
        $c = $this->wireClient([self::page([self::summary('db/pw-old'), self::summary('db/pw')])]);
        $env = $c->vault->secrets->get('db/pw');
        $this->assertCount(1, $this->calls);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('https://acme.api.k9o.io/@member-portal/vault/secrets?prefix=db%2Fpw&limit=100', $this->calls[0]['url']);
        $this->assertSame('db/pw', $env->data['name']);
        foreach ($this->calls as $call) {
            $this->assertStringNotContainsString('/vault/secrets/', $call['url']);
        }
    }

    public function testGetDoesNotSendRegion(): void
    {
        $c = $this->wireClient([self::page([self::summary('db/pw')])]);
        $c->vault->secrets->get('db/pw', 'us01');
        $this->assertStringNotContainsString('region', $this->lastUrl());
    }

    public function testGetFollowsNextCursor(): void
    {
        $c = $this->wireClient([self::page([self::summary('db/pw-old')], 'cur1'), self::page([self::summary('db/pw')])]);
        $env = $c->vault->secrets->get('db/pw');
        $this->assertCount(2, $this->calls);
        $this->assertSame('https://acme.api.k9o.io/@member-portal/vault/secrets?prefix=db%2Fpw&cursor=cur1&limit=100', $this->calls[1]['url']);
        $this->assertSame('db/pw', $env->data['name']);
    }

    public function testGetNotFoundIs404(): void
    {
        $c = $this->wireClient([self::page([self::summary('db/pw-old')])]);
        try {
            $c->vault->secrets->get('db/pw');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_NOT_FOUND', $e->code);
            $this->assertSame(404, $e->status);
            $this->assertSame("No secret named 'db/pw' in this project.", $e->getMessage());
        }
        $this->assertCount(1, $this->calls);
    }

    public function testGetStopsAfterTwentyPages(): void
    {
        $c = $this->wireClient([self::page([], 'again')]);
        try {
            $c->vault->secrets->get('db/pw');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_NOT_FOUND', $e->code);
        }
        $this->assertCount(20, $this->calls);
    }

    public function testNamedOperationsOmitRegionWhenNotGiven(): void
    {
        $c = $this->wireClient(['{"data":{},"meta":{}}']);
        $s = $c->vault->secrets;
        $base = 'https://acme.api.k9o.io/@member-portal/vault/secrets/db%2Fpw';
        $s->create('db/pw', 'x');
        $this->assertSame($base, $this->lastUrl());
        $s->update('db/pw', 'x');
        $this->assertSame($base, $this->lastUrl());
        $s->reveal_challenge('db/pw');
        $this->assertSame($base . '?op=reveal-challenge', $this->lastUrl());
        $s->reveal('db/pw', 'rvc_1');
        $this->assertSame($base, $this->lastUrl());
        $s->rotate('db/pw');
        $this->assertSame($base . '?op=rotate', $this->lastUrl());
        $s->move('db/pw', 'z');
        $this->assertSame($base . '?op=move', $this->lastUrl());
        $s->delete('db/pw');
        $this->assertSame($base, $this->lastUrl());
    }

    public function testNamedOperationsSendRegionWhenGiven(): void
    {
        $c = $this->wireClient(['{"data":{},"meta":{}}']);
        $c->vault->secrets->rotate('db/pw', region: 'us01');
        $this->assertSame('https://acme.api.k9o.io/@member-portal/vault/secrets/db%2Fpw?op=rotate&region=us01', $this->lastUrl());
        $c->vault->secrets->delete('db/pw', region: 'us01');
        $this->assertStringEndsWith('/db%2Fpw?region=us01', $this->lastUrl());
    }

    public function testDeleteAcceptsAny2xx(): void
    {
        $c = $this->wireClient(['{"data":{"id":"sec_abc"},"meta":{}}'], 200);
        $env = $c->vault->secrets->delete('db/pw');
        $this->assertSame('DELETE', $this->calls[0]['method']);
        $this->assertSame('sec_abc', $env->data['id']);
        $c = $this->wireClient([''], 204);
        $this->assertNull($c->vault->secrets->delete('db/pw')->data);
    }

    // ---- pure request-shaping helpers (URL / query / header / base64) ----------

    public function testPathForEncodesHierarchicalNameAsSingleSegment(): void
    {
        $this->assertSame('vault/secrets/db%2Fpw', Secrets::path_for('db/pw'));
        $this->assertSame('vault/secrets/simple', Secrets::path_for('simple'));
    }

    public function testPathForComposesIntoProjectUrl(): void
    {
        $path = UrlGrammar::project_path('member-portal', Secrets::path_for('db/pw'));
        $this->assertSame('/@member-portal/vault/secrets/db%2Fpw', $path);
    }

    public function testRegionQueryWithoutOp(): void
    {
        $this->assertSame(['region' => 'us01'], Secrets::region_query('us01'));
    }

    public function testRegionQueryWithOpOrdersOpFirst(): void
    {
        $q = Secrets::region_query('us01', 'rotate');
        $this->assertSame(['op' => 'rotate', 'region' => 'us01'], $q);
        $this->assertSame(['op', 'region'], array_keys($q));
    }

    public function testRegionQueryOmitsRegionWhenNotGiven(): void
    {
        $this->assertSame([], Secrets::region_query());
        $this->assertSame([], Secrets::region_query(null));
        $this->assertSame(['op' => 'move'], Secrets::region_query(null, 'move'));
    }

    public function testRevealHeader(): void
    {
        $this->assertSame(['X-K9O-Reveal-Challenge' => 'rvc_1'], Secrets::reveal_header('rvc_1'));
    }

    public function testEncodeValueBase64Encodes(): void
    {
        $this->assertSame(base64_encode('hunter2'), Secrets::encode_value('hunter2'));
        $this->assertNotSame('hunter2', Secrets::encode_value('hunter2'));
    }

    public function testDecodeValueIsInverseOfEncodeValue(): void
    {
        $this->assertSame('hunter2', Secrets::decode_value(Secrets::encode_value('hunter2')));
    }

    public function testEncodeDecodeRoundTripsArbitraryPlaintext(): void
    {
        $plaintext = "s3kr!t \xE2\x9C\x93 with unicode + spaces";
        $this->assertSame($plaintext, Secrets::decode_value(Secrets::encode_value($plaintext)));
    }
}
