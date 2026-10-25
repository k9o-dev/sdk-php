<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\HttpClientConfig;
use K9o\HttpTransport;
use K9o\K9oClient;
use K9o\Region;
use PHPUnit\Framework\TestCase;

/**
 * Regional host resolution: one unauthenticated no-redirect HEAD to the region-free org host, strict Location
 * validation, caching, and that a credential only ever reaches a validated regional host. The stub transport
 * records every exchange; the real curl probe is exercised against a loopback `php -S` server.
 */
final class RegionTest extends TestCase
{
    /** @var list<array{method:string,url:string,headers:list<string>,body:?string}> */
    private array $calls = [];

    /** @param (callable(int):array{status:int,location:?string,error?:string})|null $head */
    private function client(string $base, ?string $headLocation, int $headStatus = 308, ?\Closure $onHead = null): K9oClient
    {
        $transport = function (string $m, string $u, array $h, ?string $b) use ($headLocation, $headStatus, $onHead): array {
            $this->calls[] = ['method' => $m, 'url' => $u, 'headers' => $h, 'body' => $b];
            if ($m === 'HEAD') {
                if ($onHead !== null) {
                    return $onHead();
                }
                return ['status' => $headStatus, 'body' => '', 'location' => $headLocation, 'error' => ''];
            }
            return ['status' => 200, 'body' => json_encode(['data' => ['ok' => true], 'meta' => new \stdClass()]),
                'location' => null, 'error' => ''];
        };
        $cfg = new HttpClientConfig(
            base_url: $base, api_key: 'secret-key', project_slug: 'portal',
            default_headers: ['x-extra' => '1', 'cookie' => 'sid=1'], transport: $transport
        );
        $r = new \ReflectionClass(K9oClient::class);
        $c = $r->newInstanceWithoutConstructor();
        $r->getConstructor()->invoke($c, $cfg);
        return $c;
    }

    private function heads(): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['method'] === 'HEAD'));
    }

    public function testProbesUnauthenticatedThenSendsCredentialedCallToRegionalHost(): void
    {
        $c = $this->client('https://acme.api.k9o.io', 'https://acme.api-de01.k9o.io/');
        $c->gate->whoami();
        $this->assertSame('HEAD', $this->calls[0]['method']);
        $this->assertSame('https://acme.api.k9o.io/', $this->calls[0]['url']);
        $joined = strtolower(implode("\n", $this->calls[0]['headers']));
        $this->assertStringNotContainsString('authorization', $joined);
        $this->assertStringNotContainsString('x-extra', $joined);
        $this->assertStringNotContainsString('cookie', $joined);
        $this->assertStringNotContainsString('secret-key', $joined);
        $this->assertSame('GET', $this->calls[1]['method']);
        $this->assertSame('https://acme.api-de01.k9o.io/@portal/gate/whoami', $this->calls[1]['url']);
        $this->assertStringContainsString('secret-key', implode("\n", $this->calls[1]['headers']));
    }

    public function testGateSignInPostGoesToRegionalHost(): void
    {
        $c = $this->client('https://acme.api.k9o.io', 'https://acme.api-de01.k9o.io/');
        $c->gate->sign_in('a@b.c', 'pw');
        $this->assertSame('POST', $this->calls[1]['method']);
        $this->assertStringStartsWith('https://acme.api-de01.k9o.io/', $this->calls[1]['url']);
    }

    public function testStreamPathUsesRegionalHost(): void
    {
        $c = $this->client('https://acme.api-staging.k9o.io', 'https://acme.api-staging-de01.k9o.io/');
        $n = 0;
        foreach (HttpTransport::stream_sse($c->config, 'GET', '/@portal/stream') as $_) {
            $n++;
        }
        $this->assertSame('HEAD', $this->calls[0]['method']);
        $this->assertSame('https://acme.api-staging-de01.k9o.io/@portal/stream', $this->calls[1]['url']);
        $this->assertStringContainsString('secret-key', implode("\n", $this->calls[1]['headers']));
        $this->assertSame(1, $this->heads());
    }

    public function testCachedOneProbeForTwoCalls(): void
    {
        $c = $this->client('https://acme.api-staging.k9o.io', 'https://acme.api-staging-de01.k9o.io/');
        $c->gate->whoami();
        $c->gate->whoami();
        $this->assertSame(1, $this->heads());
        $this->assertSame('https://acme.api-staging-de01.k9o.io/@portal/gate/whoami', $this->calls[2]['url']);
    }

    public function testNoRedirectKeepsConfiguredHostAndIsCached(): void
    {
        $c = $this->client('https://acme.api.k9o.io', null, 200);
        $c->gate->whoami();
        $c->gate->whoami();
        $this->assertSame('https://acme.api.k9o.io/@portal/gate/whoami', $this->calls[1]['url']);
        $this->assertSame(1, $this->heads());
    }

    public function testRegionalCustomAndSelfHostedBaseNeverProbed(): void
    {
        foreach (['https://acme.api-de01.k9o.io', 'https://api.example.com', 'https://k9o.internal.corp',
                  'https://api.k9o.io', 'http://127.0.0.1:9', 'https://acme.api.k9o.io.evil.example'] as $base) {
            $this->calls = [];
            $c = $this->client($base, 'https://evil.example/');
            $c->gate->whoami();
            $this->assertSame(0, $this->heads(), $base);
            $this->assertStringStartsWith($base, $this->calls[0]['url'], $base);
        }
    }

    public function testHostileLocationRefusedCredentialStaysOnConfiguredHost(): void
    {
        $hostile = [
            'https://evil.example/',
            'https://acme.api-de01.evil.example/',
            'https://acme.api-de01.k9o.io.evil.example/',
            'https://other.api-de01.k9o.io/',
            'https://acme.api-staging-de01.k9o.io/',
            'http://acme.api-de01.k9o.io/',
            'https://user:pw@acme.api-de01.k9o.io/',
            'https://acme.api-de01.k9o.io:8443/',
            'https://acme.api-germany.k9o.io/',
            'https://evil.example\\@acme.api-de01.k9o.io/',
            '/relative',
        ];
        foreach ($hostile as $loc) {
            $this->calls = [];
            $c = $this->client('https://acme.api.k9o.io', $loc);
            $c->gate->whoami();
            $this->assertSame('https://acme.api.k9o.io/@portal/gate/whoami', $this->calls[1]['url'], $loc);
        }
    }

    public function testNon308StatusIgnoresLocation(): void
    {
        $c = $this->client('https://acme.api.k9o.io', 'https://acme.api-de01.k9o.io/', 302);
        $c->gate->whoami();
        $this->assertSame('https://acme.api.k9o.io/@portal/gate/whoami', $this->calls[1]['url']);
    }

    public function testProbeNetworkFailureIsNotCached(): void
    {
        $n = 0;
        $c = $this->client('https://acme.api.k9o.io', null, 308, function () use (&$n): array {
            $n++;
            if ($n === 1) {
                return ['status' => 0, 'body' => '', 'location' => null, 'error' => 'offline'];
            }
            return ['status' => 308, 'body' => '', 'location' => 'https://acme.api-de01.k9o.io/', 'error' => ''];
        });
        $c->gate->whoami();
        $this->assertSame('https://acme.api.k9o.io/@portal/gate/whoami', $this->calls[1]['url']);
        $c->gate->whoami();
        $this->assertSame(2, $n);
        $this->assertSame('https://acme.api-de01.k9o.io/@portal/gate/whoami', end($this->calls)['url']);
    }

    public function testOriginFromLocationAcceptsOnlySameOrgSameApexFamily(): void
    {
        $base = 'https://acme.api.k9o.io';
        $this->assertSame('https://acme.api-de01.k9o.io', Region::origin_from_location('acme.api.k9o.io', 'https://acme.api-de01.k9o.io/x', $base));
        $this->assertNull(Region::origin_from_location('acme.api.k9o.io', '/relative', $base));
        $this->assertSame('https://acme.api-staging-de01.k9o.io', Region::origin_from_location('acme.api-staging.k9o.io', 'https://acme.api-staging-de01.k9o.io/', $base));
        $this->assertNull(Region::origin_from_location('api.example.com', 'https://acme.api-de01.k9o.io/', $base));
    }

    // --- real curl probe against a loopback php -S server -------------------------------------------------

    public function testCurlProbeDoesNotFollowRedirectAndSendsOnlyGivenHeaders(): void
    {
        $dir = sys_get_temp_dir() . '/k9o-region-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/router.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/log.jsonl', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'],
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'cookie' => $_SERVER['HTTP_COOKIE'] ?? null,
]) . "\n", FILE_APPEND);
if ($_SERVER['REQUEST_URI'] === '/') {
    http_response_code(308);
    header('Location: /followed');
    return;
}
echo 'followed';
PHP);
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0, $up = false; $i < 100 && !$up; $i++) {
                $c = @fsockopen('127.0.0.1', $port);
                if ($c) { fclose($c); $up = true; } else { usleep(50000); }
            }
            $this->assertTrue($up, 'loopback server did not start');
            $cfg = new HttpClientConfig(base_url: 'http://127.0.0.1:' . $port, api_key: 'secret-key',
                default_headers: ['x-extra' => '1']);
            $res = HttpTransport::send($cfg, 'HEAD', 'http://127.0.0.1:' . $port . '/', ['accept: */*'], null);
            $this->assertSame(308, $res['status']);
            $this->assertSame('/followed', $res['location']);
            $this->assertSame('', $res['error']);
            $lines = array_map(fn ($l) => json_decode($l, true), file($dir . '/log.jsonl', FILE_IGNORE_NEW_LINES));
            $this->assertCount(1, $lines, 'redirect must not be followed');
            $this->assertSame('HEAD', $lines[0]['method']);
            $this->assertNull($lines[0]['auth']);
            $this->assertNull($lines[0]['cookie']);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }
}
