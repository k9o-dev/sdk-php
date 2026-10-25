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
 * $client->vault->secrets->* tests (— `services/gateway/src/routes/vault-secrets.ts`),
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
            'reveal' => [fn (K9oClient $c) => $c->vault->secrets->reveal('db/pw', 'us01', 'rvc_1')],
            'update' => [fn (K9oClient $c) => $c->vault->secrets->update('db/pw', 'us01', 'newpw')],
            'rotate' => [fn (K9oClient $c) => $c->vault->secrets->rotate('db/pw', 'us01')],
            'move' => [fn (K9oClient $c) => $c->vault->secrets->move('db/pw', 'us01', 'db/pw2')],
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

    // ---- region required on every named-secret op except list ------------------

    public function testGetRejectsEmptyRegion(): void
    {
        $this->assertRejectsEmptyRegion(fn (K9oClient $c) => $c->vault->secrets->get('db/pw', ''));
    }

    public function testCreateRejectsEmptyRegion(): void
    {
        $this->assertRejectsEmptyRegion(fn (K9oClient $c) => $c->vault->secrets->create('db/pw', 'hunter2', ''));
    }

    public function testRevealChallengeRejectsEmptyRegion(): void
    {
        $this->assertRejectsEmptyRegion(fn (K9oClient $c) => $c->vault->secrets->reveal_challenge('db/pw', ''));
    }

    public function testRevealRejectsEmptyRegion(): void
    {
        $this->assertRejectsEmptyRegion(fn (K9oClient $c) => $c->vault->secrets->reveal('db/pw', '', 'rvc_1'));
    }

    public function testUpdateRejectsEmptyRegion(): void
    {
        $this->assertRejectsEmptyRegion(fn (K9oClient $c) => $c->vault->secrets->update('db/pw', '', 'newpw'));
    }

    public function testRotateRejectsEmptyRegion(): void
    {
        $this->assertRejectsEmptyRegion(fn (K9oClient $c) => $c->vault->secrets->rotate('db/pw', ''));
    }

    public function testMoveRejectsEmptyRegion(): void
    {
        $this->assertRejectsEmptyRegion(fn (K9oClient $c) => $c->vault->secrets->move('db/pw', '', 'db/pw2'));
    }

    public function testDeleteRejectsEmptyRegion(): void
    {
        $this->assertRejectsEmptyRegion(fn (K9oClient $c) => $c->vault->secrets->delete('db/pw', ''));
    }

    /** @param callable(K9oClient): mixed $call */
    private function assertRejectsEmptyRegion(callable $call): void
    {
        $c = $this->projectClient();
        try {
            $call($c);
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        }
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

    public function testRegionQueryRejectsEmptyRegion(): void
    {
        try {
            Secrets::region_query('');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        }
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
