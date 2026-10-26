<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\K9oClient;
use K9o\KError;
use PHPUnit\Framework\TestCase;

/**
 * `$client->ai->generate()` — URL, verb, body shape (prompt first, optional fields omitted),
 * result parsing and the 400 / 501 error mapping. HttpTransport talks ext-curl directly
 * (no injectable transport), so this suite runs a loopback `php -S` server
 * that records each request to a file and replies with a canned response.
 */
final class AiGenerateTest extends TestCase
{
    private static $proc = null;
    private static string $dir = '';
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/k9o-ai-generate-' . bin2hex(random_bytes(4));
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
        $this->respond(['data' => [
            'modelId' => 'llama-3-8b', 'content' => 'Hi there, friend!', 'finishReason' => 'stop',
            'usage' => ['promptTokens' => 5, 'completionTokens' => 4, 'totalTokens' => 9], 'usageType' => 'ai.generate',
        ], 'meta' => new \stdClass()]);
    }

    private function client(): K9oClient
    {
        return K9oClient::create(['base_url' => 'http://127.0.0.1:' . self::$port, 'project_slug' => 'member-portal']);
    }

    public function testPostsPromptOnlyAndParsesResult(): void
    {
        $this->ok();
        $r = $this->client()->ai->generate('Say hi');
        $cap = $this->captured();
        $this->assertSame('POST', $cap['method']);
        $this->assertSame('/@member-portal/ai/generate', $cap['uri']);
        $this->assertSame(['prompt' => 'Say hi'], json_decode($cap['body'], true));
        $this->assertSame('Hi there, friend!', $r->data['content']);
        $this->assertSame('llama-3-8b', $r->data['modelId']);
        $this->assertSame(9, $r->data['usage']['totalTokens']);
    }

    public function testAllFieldsUseWireNames(): void
    {
        $this->ok();
        $this->client()->ai->generate('p', 'llama-3-8b', 20, 0.5, 'ai.generate');
        $this->assertSame(
            ['prompt' => 'p', 'model' => 'llama-3-8b', 'maxTokens' => 20, 'temperature' => 0.5, 'usageType' => 'ai.generate'],
            json_decode($this->captured()['body'], true)
        );
    }

    public function testZeroTemperatureIsSent(): void
    {
        $this->ok();
        $this->client()->ai->generate('p', null, null, 0);
        $body = json_decode($this->captured()['body'], true);
        $this->assertSame(['prompt', 'temperature'], array_keys($body));
    }

    public function testRequiresProjectSlug(): void
    {
        $c = K9oClient::create(['base_url' => 'http://127.0.0.1:' . self::$port]);
        try {
            $c->ai->generate('x');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_MISSING_PROJECT_CONTEXT', $e->code);
        }
    }

    public function testNoDefaultModelIs400KError(): void
    {
        $this->respond(['code' => 'K9O_INVALID_FIELD_VALUE', 'message' => 'no default LLM', 'context' => new \stdClass(), 'recommendation' => null], 400);
        try {
            $this->client()->ai->generate('x');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
            $this->assertSame(400, $e->status);
        }
    }

    public function testOutsideProviderModelIs501KError(): void
    {
        $this->respond([
            'code' => 'K9O_NOT_IMPLEMENTED', 'message' => 'External LLM provider dispatch is not configured',
            'context' => ['modelId' => 'gpt-4o', 'provider' => 'openai'], 'recommendation' => null,
        ], 501);
        try {
            $this->client()->ai->generate('x', 'gpt-4o');
            $this->fail('expected KError');
        } catch (KError $e) {
            $this->assertSame('K9O_NOT_IMPLEMENTED', $e->code);
            $this->assertSame(501, $e->status);
        }
    }
}
