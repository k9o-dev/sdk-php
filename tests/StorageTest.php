<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\HttpClientConfig;
use K9o\K9oClient;
use K9o\KError;
use PHPUnit\Framework\TestCase;

/** `$client->storage` buckets and objects, against an injected transport. */
final class StorageTest extends TestCase
{
    private const ROOT = 'https://acme.api-de01.k9o.io/@member-portal/storage/buckets';

    /** @var list<array{method:string,url:string,headers:list<string>,body:?string}> */
    private array $calls = [];

    private function client(?string $project = 'member-portal', int $status = 200, ?string $body = null): K9oClient
    {
        $body ??= json_encode(['data' => ['ok' => true], 'meta' => new \stdClass()]);
        $transport = function (string $m, string $u, array $h, ?string $b) use ($status, $body): array {
            $this->calls[] = ['method' => $m, 'url' => $u, 'headers' => $h, 'body' => $b];
            return ['status' => $status, 'body' => $body, 'location' => null, 'error' => ''];
        };
        $cfg = new HttpClientConfig(base_url: 'https://acme.api-de01.k9o.io', api_key: 'k', project_slug: $project, transport: $transport);
        $r = new \ReflectionClass(K9oClient::class);
        $c = $r->newInstanceWithoutConstructor();
        $r->getConstructor()->invoke($c, $cfg);
        return $c;
    }

    private function headers(int $i = 0): string
    {
        return strtolower(implode("\n", $this->calls[$i]['headers']));
    }

    public function testBucketCrud(): void
    {
        $s = $this->client()->storage;
        $s->list_buckets();
        $s->get_bucket('avatars');
        $s->get_bucket('a/b');
        $s->create_bucket('avatars', ['public' => true, 'file_size_limit' => 5, 'allowed_mime_types' => null, 'byos_binding_id' => 'x']);
        $s->update_bucket('avatars', ['versioning' => true, 'delete_after_days' => 7]);
        $this->assertSame(['GET', self::ROOT], [$this->calls[0]['method'], $this->calls[0]['url']]);
        $this->assertSame(self::ROOT . '/avatars', $this->calls[1]['url']);
        $this->assertSame(self::ROOT . '/a%2Fb', $this->calls[2]['url']);
        $this->assertSame(['POST', self::ROOT], [$this->calls[3]['method'], $this->calls[3]['url']]);
        $this->assertSame(
            ['name' => 'avatars', 'public' => true, 'fileSizeLimit' => 5, 'allowedMimeTypes' => null, 'byosBindingId' => 'x'],
            json_decode($this->calls[3]['body'], true)
        );
        $this->assertStringContainsString('content-type: application/json', $this->headers(3));
        $this->assertSame(['PATCH', self::ROOT . '/avatars'], [$this->calls[4]['method'], $this->calls[4]['url']]);
        $this->assertSame(['versioning' => true, 'deleteAfterDays' => 7], json_decode($this->calls[4]['body'], true));
    }

    public function testUnknownBucketOptionRefusedBeforeRequest(): void
    {
        try {
            $this->client()->storage->create_bucket('avatars', ['bogus' => 1]);
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        }
        $this->assertCount(0, $this->calls);
    }

    public function testDeleteBucketEmptyQuery(): void
    {
        $s = $this->client()->storage;
        $s->delete_bucket('avatars');
        $s->delete_bucket('avatars', empty: false);
        $s->delete_bucket('avatars', empty: true);
        $this->assertSame(['DELETE', self::ROOT . '/avatars'], [$this->calls[0]['method'], $this->calls[0]['url']]);
        $this->assertSame(self::ROOT . '/avatars', $this->calls[1]['url']);
        $this->assertSame(self::ROOT . '/avatars?empty=true', $this->calls[2]['url']);
    }

    public function testUploadIsRealMultipartWithAllParts(): void
    {
        $bytes = "line1\r\n\x00\xff binary";
        $this->client()->storage->from('avatars')->upload('dir/me.png', $bytes, [
            'content_type' => 'image/png', 'upsert' => true, 'metadata' => ['k' => 'v'],
        ]);
        $c = $this->calls[0];
        $this->assertSame(['POST', self::ROOT . '/avatars/objects'], [$c['method'], $c['url']]);
        $this->assertSame(1, preg_match('#content-type: multipart/form-data; boundary=(\S+)#', $this->headers(), $m));
        $this->assertStringNotContainsString('content-type: application/json', $this->headers());
        $b = $m[1];
        $this->assertStringStartsWith("--$b\r\n", $c['body']);
        $this->assertStringEndsWith("--$b--\r\n", $c['body']);
        $this->assertStringContainsString("name=\"file\"; filename=\"me.png\"\r\nContent-Type: image/png\r\n\r\n$bytes\r\n--$b", $c['body']);
        $this->assertStringContainsString("name=\"path\"\r\n\r\ndir/me.png\r\n", $c['body']);
        $this->assertStringContainsString("name=\"contentType\"\r\n\r\nimage/png\r\n", $c['body']);
        $this->assertStringContainsString("name=\"upsert\"\r\n\r\ntrue\r\n", $c['body']);
        $this->assertStringContainsString("name=\"metadata\"\r\n\r\n{\"k\":\"v\"}\r\n", $c['body']);
    }

    public function testUploadDefaultsAndStream(): void
    {
        $h = fopen('php://memory', 'w+b');
        fwrite($h, 'streamed');
        rewind($h);
        $this->client()->storage->from('avatars')->upload('a.txt', $h);
        $body = $this->calls[0]['body'];
        $this->assertStringContainsString("\r\n\r\nstreamed\r\n", $body);
        $this->assertStringContainsString("name=\"upsert\"\r\n\r\nfalse\r\n", $body);
        $this->assertStringNotContainsString('name="contentType"', $body);
        $this->assertStringNotContainsString('name="metadata"', $body);
    }

    public function testExpiresInBounds(): void
    {
        $h = $this->client()->storage->from('avatars');
        foreach ([0, -1, 604801, 1.5, '60', null] as $bad) {
            try {
                $h->create_signed_url('a.png', $bad);
                $this->fail('expected KError for ' . var_export($bad, true));
            } catch (KError $e) {
                $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
            }
        }
        $this->assertCount(0, $this->calls);
        $h->create_signed_url('a.png', 1);
        $h->create_signed_url('a b.png', 604800, download: true);
        $this->assertSame(['POST', self::ROOT . '/avatars/objects/sign'], [$this->calls[0]['method'], $this->calls[0]['url']]);
        $this->assertSame(['path' => 'a.png', 'expiresIn' => 1], json_decode($this->calls[0]['body'], true));
        $this->assertSame(['path' => 'a b.png', 'expiresIn' => 604800, 'download' => true], json_decode($this->calls[1]['body'], true));
    }

    public function testListAndRemoveQueryEncoding(): void
    {
        $h = $this->client()->storage->from('my bucket');
        $h->list();
        $h->list('a b&c/');
        $h->remove('../x y&z');
        $this->assertSame(self::ROOT . '/my%20bucket/objects', $this->calls[0]['url']);
        $this->assertSame(self::ROOT . '/my%20bucket/objects?prefix=a+b%26c%2F', $this->calls[1]['url']);
        $this->assertSame(['DELETE', self::ROOT . '/my%20bucket/objects?path=..%2Fx+y%26z'], [$this->calls[2]['method'], $this->calls[2]['url']]);
        $this->assertNull($this->calls[2]['body']);
    }

    public function testDotAndDotDotBucketRefusedBeforeAnyRequest(): void
    {
        $c = $this->client();
        foreach (['.', '..'] as $name) {
            $ops = [
                fn() => $c->storage->get_bucket($name),
                fn() => $c->storage->update_bucket($name, ['public' => true]),
                fn() => $c->storage->delete_bucket($name),
                fn() => $c->storage->from($name)->upload('a.txt', 'x'),
                fn() => $c->storage->from($name)->create_signed_url('a.txt', 60),
                fn() => $c->storage->from($name)->list(),
                fn() => $c->storage->from($name)->remove('a.txt'),
            ];
            foreach ($ops as $i => $op) {
                try {
                    $op();
                    $this->fail("op $i accepted bucket '$name'");
                } catch (KError $e) {
                    $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
                    $this->assertSame('dot_segment', $e->context['reason']);
                    $this->assertSame('bucket', $e->context['field']);
                }
            }
        }
        $this->assertSame([], $this->calls);
        $c->storage->get_bucket('avatars');
        $c->storage->from('avatars')->list();
        $this->assertCount(2, $this->calls);
    }

    public function testMissingSlugRefusedWithoutRequest(): void
    {
        $s = $this->client(null)->storage;
        foreach ([
            fn () => $s->list_buckets(),
            fn () => $s->create_bucket('abc'),
            fn () => $s->from('b')->upload('p', 'x'),
            fn () => $s->from('b')->create_signed_url('p', 60),
            fn () => $s->from('b')->remove('p'),
        ] as $call) {
            try {
                $call();
                $this->fail('expected KError');
            } catch (KError $e) {
                $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
            }
        }
        $this->assertCount(0, $this->calls);
    }

    public function testErrorsPassThroughWithoutObjectBytes(): void
    {
        $wire = json_encode(['code' => 'K9O_CONFLICT', 'message' => 'bucket_not_empty', 'context' => ['reason' => 'bucket_not_empty'], 'recommendation' => null]);
        $c = $this->client(status: 409, body: $wire);
        try {
            $c->storage->delete_bucket('avatars');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('K9O_CONFLICT', $e->code);
        }
        $secret = 'super-secret-object-bytes';
        $c = $this->client(status: 400, body: json_encode(['code' => 'K9O_INVALID_BUCKET_NAME', 'message' => 'bad name', 'context' => [], 'recommendation' => null]));
        try {
            $c->storage->from('Bad_Name')->upload('a.txt', $secret);
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_BUCKET_NAME', $e->code);
            $this->assertStringNotContainsString($secret, (string) $e);
            $this->assertStringNotContainsString($secret, json_encode($e->context));
        }
    }
}
