<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\K9oClient;
use K9o\KError;
use PHPUnit\Framework\TestCase;

/**
 * `$client->ai->embed()` — URL, verb, body shape, bound-project guard, and
 * that errors never echo the input. HttpTransport talks ext-curl directly
 * (no injectable transport), so this suite runs a loopback `php -S` server
 * that records each request to a file and replies with a canned response.
 */
final class AiEmbedTest extends TestCase
{
    private static $proc = null;
    private static string $dir = '';
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/k9o-ai-embed-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        file_put_contents(self::$dir . '/router.php', <<<'PHP'
<?php
$dir = __DIR__;
file_put_contents($dir . '/captured.json', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'body' => file_get_contents('php://input'),
]));
$status = (int) trim((string) @file_get_contents($dir . '/status'));
http_response_code($status ?: 200);
header('content-type: application/json');
echo (string) file_get_contents($dir . '/response.json');
PHP);
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        self::$proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$dir . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', self::$port);
            if ($c) { fclose($c); return; }
            usleep(50000);
        }
        self::fail('loopback server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc !== null) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        foreach (glob(self::$dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir(self::$dir);
    }

    private function respond(array $json, int $status = 200): void
    {
        file_put_contents(self::$dir . '/response.json', json_encode($json));
        file_put_contents(self::$dir . '/status', (string) $status);
        @unlink(self::$dir . '/captured.json');
    }

    /** @return array{method: string, uri: string, body: string} */
    private function captured(): array
    {
        return json_decode((string) file_get_contents(self::$dir . '/captured.json'), true);
    }

    private function ok(): void
    {
        $this->respond(['data' => ['embeddings' => [[0.1, 0.2]], 'model' => 'nomic-embed-text-v1.5', 'dimensions' => 2], 'meta' => new \stdClass()]);
    }

    private function client(): K9oClient
    {
        return K9oClient::create(['base_url' => 'http://127.0.0.1:' . self::$port, 'project_slug' => 'member-portal']);
    }

    public function testPostsEmbeddingsPathAndReturnsVectors(): void
    {
        $this->ok();
        $r = $this->client()->ai->embed('hello');
        $cap = $this->captured();
        $this->assertSame('POST', $cap['method']);
        $this->assertSame('/@member-portal/ai/embeddings', $cap['uri']);
        $this->assertSame(['input' => 'hello'], json_decode($cap['body'], true));
        $this->assertSame([[0.1, 0.2]], $r->data['embeddings']);
        $this->assertSame(2, $r->data['dimensions']);
    }

    public function testListInputAndModelSentExactlyAsGiven(): void
    {
        $this->ok();
        $this->client()->ai->embed(['search_document: a', 'search_document: b'], 'nomic-embed-text-v1.5');
        $this->assertSame(
            ['model' => 'nomic-embed-text-v1.5', 'input' => ['search_document: a', 'search_document: b']],
            json_decode($this->captured()['body'], true)
        );
    }

    public function testModelOmittedWhenNotGiven(): void
    {
        $this->ok();
        $this->client()->ai->embed(['x']);
        $body = json_decode($this->captured()['body'], true);
        $this->assertArrayNotHasKey('model', $body);
        $this->assertSame(['x'], $body['input']);
    }

    public function testRequiresProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'http://127.0.0.1:' . self::$port]);
        try {
            $c->ai->embed('x');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testServerRefusalSurfacesAsKErrorWithoutInputText(): void
    {
        $this->respond([
            'code' => 'K9O_MODEL_NOT_DECLARED',
            'message' => 'model is not a declared embedding model',
            'context' => ['declared' => ['nomic-embed-text-v1.5']],
            'recommendation' => null,
        ], 400);
        $secret = 'the-secret-document-body';
        try {
            $this->client()->ai->embed($secret, 'other');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MODEL_NOT_DECLARED', $e->code);
            $this->assertSame(400, $e->status);
            $this->assertStringNotContainsString($secret, (string) $e);
            $this->assertStringNotContainsString($secret, $e->getMessage());
            $this->assertStringNotContainsString($secret, json_encode($e->context));
        }
    }
}
