<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\HttpClientConfig;
use K9o\K9oClient;
use K9o\KError;
use K9o\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `query`, `search`, `text_search` / `vector_search`: wire shape, the shared translation vectors, validation. */
final class QuerySearchTest extends TestCase
{
    /** @var list<array{method:string,url:string,body:?string}> */
    private array $calls = [];

    private function client(): K9oClient
    {
        $this->calls = [];
        $transport = function (string $m, string $u, array $h, ?string $b): array {
            $this->calls[] = ['method' => $m, 'url' => $u, 'body' => $b];
            return [
                'status' => 200,
                'body' => '{"data":[{"id":"u1"}],"meta":{"rawQuery":{"intent":"read","rowCount":1}}}',
                'location' => null,
                'error' => '',
            ];
        };
        $cfg = new HttpClientConfig(base_url: 'https://acme.api-de01.k9o.io', api_key: 'k', project_slug: 'demo', transport: $transport);
        $r = new \ReflectionClass(K9oClient::class);
        $c = $r->newInstanceWithoutConstructor();
        $r->getConstructor()->invoke($c, $cfg);
        return $c;
    }

    /** @return array{0:string,1:string,2:array<string,string>,3:mixed} */
    private function last(): array
    {
        $call = end($this->calls);
        $u = parse_url($call['url']);
        parse_str($u['query'] ?? '', $q);
        ksort($q);
        return [$call['method'], $u['path'], $q, $call['body'] === null ? null : json_decode($call['body'], true)];
    }

    public static function vectors(): array
    {
        return [
            'vector 1' => ["SELECT * FROM users WHERE data->>'role' = :role", ['role' => 'admin'],
                "SELECT * FROM users WHERE data->>'role' = \$1", ['admin']],
            'vector 2' => ['SELECT :a, :b, :a', ['a' => 1, 'b' => 2], 'SELECT $1, $2, $1', [1, 2]],
            'vector 3' => ["SELECT ':not_a_param', \":also_not\", x::int FROM t WHERE y = :y", ['y' => true],
                "SELECT ':not_a_param', \":also_not\", x::int FROM t WHERE y = \$1", [true]],
            'vector 4' => ["SELECT 'it''s :no' , :v -- :comment\nFROM t", ['v' => null],
                "SELECT 'it''s :no' , \$1 -- :comment\nFROM t", [null]],
            'vector 5' => ['SELECT /* :c /* :d */ :e */ :f', ['f' => 1], 'SELECT /* :c /* :d */ :e */ $1', [1]],
            'vector 6' => ['SELECT $$ :x $$, $q$ :y $q$, :z', ['z' => 's'], 'SELECT $$ :x $$, $q$ :y $q$, $1', ['s']],
            'vector 7' => ['SELECT arr[1:2], a:b, :n', ['n' => 5], 'SELECT arr[1:2], a:b, $1', [5]],
            'escape string and extra keys' => ["SELECT E'a\\' :x', :y", ['y' => 1, 'z' => 2], "SELECT E'a\\' :x', \$1", [1]],
        ];
    }

    #[DataProvider('vectors')]
    public function testSharedVectors(string $sql, array $named, string $out, array $params): void
    {
        $this->assertSame([$out, $params], Query::translate_named_params($sql, $named));
    }

    public function testVector8MissingValue(): void
    {
        try {
            Query::translate_named_params('SELECT :missing', []);
            $this->fail('no throw');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
            $this->assertStringContainsString(':missing', $e->getMessage());
        }
    }

    public function testVector9NonBindableValue(): void
    {
        $this->expectException(KError::class);
        Query::translate_named_params('SELECT :a', ['a' => new \stdClass()]);
    }

    public function testNamedParamsWire(): void
    {
        $r = $this->client()->query("SELECT * FROM users WHERE data->>'role' = :role", ['role' => 'admin']);
        $this->assertSame(
            ['POST', '/@demo/query', [], ['sql' => "SELECT * FROM users WHERE data->>'role' = \$1", 'params' => ['admin']]],
            $this->last()
        );
        $this->assertSame([['id' => 'u1']], $r->data);
    }

    public function testVector10ListParamsUnchanged(): void
    {
        $this->client()->query('SELECT $1', ['x']);
        $this->assertSame(['sql' => 'SELECT $1', 'params' => ['x']], $this->last()[3]);
    }

    public function testVector11NoParamsKey(): void
    {
        $c = $this->client();
        $c->query('SELECT 1');
        $this->assertSame(['sql' => 'SELECT 1'], $this->last()[3]);
        $c->query('SELECT 1', []);
        $this->assertSame(['sql' => 'SELECT 1'], $this->last()[3]);
    }

    public function testBadValuesThrowBeforeAnyRequest(): void
    {
        $c = $this->client();
        foreach ([fn () => $c->query('SELECT $1', [[1]]), fn () => $c->query('SELECT :nope', ['a' => 1])] as $call) {
            try {
                $call();
                $this->fail('no throw');
            } catch (KError $e) {
                $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
            }
        }
        $this->assertSame([], $this->calls);
    }

    public function testTextSearchDefaultsOmitted(): void
    {
        $this->client()->from('articles')->select()->text_search('body', 'climate resilience')->limit(5)->execute();
        $this->assertSame(['GET', '/@demo/data/articles', ['limit' => '5', 'textSearch' => 'body:climate resilience'], null], $this->last());
    }

    public function testTextSearchLanguageAndType(): void
    {
        $this->client()->from('articles')->select()->text_search('body', 'a b', 'french', 'websearch')->execute();
        $q = $this->last()[2];
        $this->assertSame('french', $q['textSearchLanguage']);
        $this->assertSame('websearch', $q['textSearchType']);
    }

    public function testVectorSearchWire(): void
    {
        $this->client()->from('articles')->select()->vector_search('embedding', [0.1, 0.2], 'dot', 10)->execute();
        $this->assertSame(['vectorLimit' => '10', 'vectorMetric' => 'dot', 'vectorSearch' => 'embedding:[0.1,0.2]'], $this->last()[2]);
    }

    public function testVectorSearchDefaultsOmitted(): void
    {
        $this->client()->from('articles')->select()->vector_search('embedding', [1, 2])->execute();
        $this->assertSame(['vectorSearch' => 'embedding:[1,2]'], $this->last()[2]);
    }

    public static function badSearchCalls(): array
    {
        return [
            'column' => [fn ($s) => $s->text_search('bad col', 'x')],
            'empty query' => [fn ($s) => $s->text_search('body', '')],
            'language' => [fn ($s) => $s->text_search('body', 'x', 'fr-ench')],
            'type' => [fn ($s) => $s->text_search('body', 'x', 'english', 'fuzzy')],
            'empty embedding' => [fn ($s) => $s->vector_search('embedding', [])],
            'non-number' => [fn ($s) => $s->vector_search('embedding', ['a'])],
            'limit high' => [fn ($s) => $s->vector_search('embedding', [1], 'cosine', 1001)],
            'limit zero' => [fn ($s) => $s->vector_search('embedding', [1], 'cosine', 0)],
            'metric' => [fn ($s) => $s->vector_search('embedding', [1], 'manhattan')],
        ];
    }

    #[DataProvider('badSearchCalls')]
    public function testValidation(callable $call): void
    {
        $c = $this->client();
        try {
            $call($c->from('articles')->select());
            $this->fail('no throw');
        } catch (KError $e) {
            $this->assertSame('K9O_INVALID_FIELD_VALUE', $e->code);
        }
        $this->assertSame([], $this->calls);
    }

    public function testSearchFullTextPaged(): void
    {
        $r = $this->client()->search('articles', column: 'body', q: 'climate resilience', language: 'french', limit: 5, cursor: 'c1');
        $this->assertSame(
            ['GET', '/@demo/data/articles',
                ['cursor' => 'c1', 'limit' => '5', 'textSearch' => 'body:climate resilience', 'textSearchLanguage' => 'french'], null],
            $this->last()
        );
        $this->assertSame([['id' => 'u1']], $r->data);
    }

    public function testSearchBadColumnThrowsBeforeRequest(): void
    {
        $c = $this->client();
        $this->expectException(KError::class);
        try {
            $c->search('articles', column: 'bad col', q: 'x');
        } finally {
            $this->assertSame([], $this->calls);
        }
    }
}
