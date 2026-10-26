<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\HttpClientConfig;
use K9o\K9oClient;
use K9o\KError;
use K9o\Streams;
use PHPUnit\Framework\TestCase;

/** client->streams->subscribe: SSE resume from the last sequence across server-closed connections, and error mapping. */
final class StreamsTest extends TestCase
{
    private int $saved_delay;

    protected function setUp(): void
    {
        $this->saved_delay = Streams::$reconnect_delay_ms;
        Streams::$reconnect_delay_ms = 0;
    }

    protected function tearDown(): void
    {
        Streams::$reconnect_delay_ms = $this->saved_delay;
    }

    /** @param list<array{status:int,body:string}> $responses  one per connection; the last repeats */
    private function client(array $responses, array &$urls): K9oClient
    {
        $transport = function (string $m, string $u, array $h, ?string $b) use ($responses, &$urls): array {
            $urls[] = [$m, $u];
            $r = $responses[min(count($urls) - 1, count($responses) - 1)];
            return ['status' => $r['status'], 'body' => $r['body'], 'location' => null, 'error' => ''];
        };
        $cfg = new HttpClientConfig(base_url: 'https://acme.api-de01.k9o.io', api_key: 'k', project_slug: 'portal', transport: $transport);
        $r = new \ReflectionClass(K9oClient::class);
        $c = $r->newInstanceWithoutConstructor();
        $r->getConstructor()->invoke($c, $cfg);
        return $c;
    }

    private function frame(string $seq, mixed $data): string
    {
        $ev = ['sequence' => $seq, 'subject' => 'k9o.user.x._', 'data' => $data, 'publishedAt' => '2026-10-26T00:00:00Z'];
        return "id: $seq\ndata: " . json_encode($ev) . "\n\n";
    }

    private function ok(string ...$frames): array
    {
        return ['status' => 200, 'body' => "retry: 1000\n:ok\n\n" . implode('', $frames)];
    }

    /** @return array<string, string> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    public function testSubscribeResumesFromLastSequenceAcrossClosedConnections(): void
    {
        $urls = [];
        $c = $this->client([$this->ok($this->frame('1', 'a'), $this->frame('2', 'b')), $this->ok($this->frame('3', 'c')), $this->ok()], $urls);
        $events = [];
        foreach ($c->streams->subscribe('orders-feed') as $e) {
            $events[] = $e;
            if (count($events) === 3) {
                break;
            }
        }
        $this->assertSame(['1', '2', '3'], array_column($events, 'sequence'));
        $this->assertSame(['a', 'b', 'c'], array_column($events, 'data'));
        foreach ($urls as [$m, $u]) {
            $this->assertSame(['GET', '/@portal/data-streams/user/orders-feed/subscribe'], [$m, parse_url($u, PHP_URL_PATH)]);
        }
        $this->assertSame([[], ['fromSequence' => '2']], array_map(fn ($x) => $this->query($x[1]), $urls));
    }

    public function testSubscribeStartsAtFromSequenceAndKeepsCursorOverEmptyConnections(): void
    {
        $urls = [];
        $c = $this->client([$this->ok($this->frame('8', 'x')), $this->ok(), $this->ok($this->frame('9', 'y'))], $urls);
        $seen = [];
        foreach ($c->streams->subscribe('s', 7) as $e) {
            $seen[] = $e['sequence'];
            if (count($seen) === 2) {
                break;
            }
        }
        $this->assertSame(['8', '9'], $seen);
        $this->assertSame([['fromSequence' => '7'], ['fromSequence' => '8'], ['fromSequence' => '8']], array_map(fn ($x) => $this->query($x[1]), $urls));
    }

    public function testSubscribeStopsWhenCallerBreaks(): void
    {
        $urls = [];
        $c = $this->client([$this->ok($this->frame('1', 'a'), $this->frame('2', 'b'))], $urls);
        foreach ($c->streams->subscribe('s') as $e) {
            break;
        }
        $this->assertCount(1, $urls);
    }

    public function testSubscribe503RaisesTypedError(): void
    {
        $urls = [];
        $body = json_encode(['code' => 'K9O_STREAM_UNAVAILABLE', 'message' => 'no plane', 'context' => new \stdClass()]);
        $c = $this->client([['status' => 503, 'body' => $body]], $urls);
        try {
            foreach ($c->streams->subscribe('s') as $_) {
            }
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_STREAM_UNAVAILABLE', $e->code);
            $this->assertSame(503, $e->status);
        }
    }

    public function testPublishErrorsMapToKError(): void
    {
        foreach ([['K9O_STREAM_UNAVAILABLE', 503], ['K9O_STREAM_INVALID', 400]] as [$code, $status]) {
            $urls = [];
            $body = json_encode(['code' => $code, 'message' => 'nope', 'context' => new \stdClass()]);
            $c = $this->client([['status' => $status, 'body' => $body]], $urls);
            try {
                $c->streams->publish('s', ['a' => 1], 'Bad');
                $this->fail('expected KError');
            } catch (KError $e) {
                $this->assertSame($code, $e->code);
                $this->assertSame($status, $e->status);
            }
        }
    }

    public function testRequiresProjectSlug(): void
    {
        $cfg = new HttpClientConfig(base_url: 'https://acme.api-de01.k9o.io', api_key: 'k');
        $this->expectException(KError::class);
        (new Streams($cfg))->list();
    }
}
