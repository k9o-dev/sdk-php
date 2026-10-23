<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\HttpClientConfig;
use K9o\K9oClient;
use K9o\KError;
use PHPUnit\Framework\TestCase;

final class GateHostedTest extends TestCase
{
    /** @var list<array{method:string,url:string,headers:list<string>,body:?string}> */
    private array $calls = [];

    private function client(?string $project = 'portal', int $status = 200, ?string $body = null): K9oClient
    {
        $body ??= json_encode(['data' => ['signInUrl' => 'https://login.acme.com/', 'rpId' => 'login.acme.com'], 'meta' => new \stdClass()]);
        $transport = function (string $m, string $u, array $h, ?string $b) use ($status, $body): array {
            $this->calls[] = ['method' => $m, 'url' => $u, 'headers' => $h, 'body' => $b];
            return ['status' => $status, 'body' => $body, 'location' => null, 'error' => ''];
        };
        $cfg = new HttpClientConfig(
            base_url: 'https://acme.api-de01.k9o.io', api_key: 'secret-key', project_slug: $project,
            default_headers: ['Authorization' => 'Bearer other', 'Cookie' => 'sid=1', 'x-extra' => '1'],
            transport: $transport
        );
        $r = new \ReflectionClass(K9oClient::class);
        $c = $r->newInstanceWithoutConstructor();
        $r->getConstructor()->invoke($c, $cfg);
        return $c;
    }

    public function testUrlAndVerb(): void
    {
        $this->client()->gate->hosted();
        $this->assertCount(1, $this->calls);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('https://acme.api-de01.k9o.io/@portal/gate/hosted', $this->calls[0]['url']);
    }

    public function testSendsNoCredentialEvenWithTokenConfigured(): void
    {
        $this->client()->gate->hosted();
        $joined = strtolower(implode("\n", $this->calls[0]['headers']));
        $this->assertStringNotContainsString('authorization', $joined);
        $this->assertStringNotContainsString('cookie', $joined);
        $this->assertStringNotContainsString('secret-key', $joined);
        $this->assertStringContainsString('x-extra', $joined);
    }

    public function testReturnsSignInUrlAndRpId(): void
    {
        $env = $this->client()->gate->hosted();
        $this->assertSame('https://login.acme.com/', $env->data['signInUrl']);
        $this->assertSame('login.acme.com', $env->data['rpId']);
    }

    public function testUniform404SurfacesAsKError(): void
    {
        $c = $this->client('portal', 404, json_encode(['code' => 'K9O_PROJECT_NOT_FOUND', 'message' => 'Project not found']));
        try {
            $c->gate->hosted();
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('K9O_PROJECT_NOT_FOUND', $e->code);
        }
    }

    public function testMissingProjectSlugRefusedWithNoRequest(): void
    {
        try {
            $this->client(null)->gate->hosted();
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
        $this->assertCount(0, $this->calls);
    }
}
