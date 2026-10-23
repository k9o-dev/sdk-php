<?php

declare(strict_types=1);

namespace K9o\Data;

use K9o\Envelope;
use K9o\HttpClientConfig;
use K9o\HttpTransport;
use K9o\KError;
use K9o\UrlGrammar;

/**
 * Data-plane DSL entry point.
 *
 * <code>
 * $response = $k9o->from('posts')->select()->eq('status', 'published')->limit(10)->execute();
 * foreach ($response->data as $row) { echo json_encode($row), PHP_EOL; }
 * </code>
 */
final class FromBuilder
{
    public function __construct(
        private readonly HttpClientConfig $config,
        private readonly string $collection
    ) {}

    public function select(): SelectBuilder
    {
        return new SelectBuilder($this->config, $this->collection);
    }

    public function insert(array $record): Envelope
    {
        return HttpTransport::request(
            $this->config, 'POST', self::collection_path($this->config, $this->collection), $record
        );
    }

    public function update(array $patch): UpdateBuilder
    {
        return new UpdateBuilder($this->config, $this->collection, $patch);
    }

    public function delete(): DeleteBuilder
    {
        return new DeleteBuilder($this->config, $this->collection);
    }

    public static function collection_path(HttpClientConfig $config, string $collection): string
    {
        if ($config->project_slug === null || $config->project_slug === '') {
            throw new KError(
                'K9O_MISSING_PROJECT_CONTEXT',
                'data-plane operations require project_slug to be bound on the client',
                ['collection' => $collection],
                'Pass "project_slug" in K9oClient::create opts.'
            );
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $collection)) {
            throw new KError(
                'K9O_INVALID_FIELD_VALUE',
                sprintf('collection name "%s" does not match required format', $collection),
                ['collection' => $collection]
            );
        }
        return UrlGrammar::project_path($config->project_slug, 'data/' . $collection);
    }
}

/**
 * Shared filter/pagination state.
 */
abstract class BaseBuilder
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    protected array $filters = [];
    protected ?int $limit_val = null;
    protected ?string $cursor_val = null;
    protected ?string $count_mode = null;

    public function eq(string $column, mixed $value): static { $this->filters[] = [$column, 'eq', (string) $value]; return $this; }
    public function neq(string $column, mixed $value): static { $this->filters[] = [$column, 'neq', (string) $value]; return $this; }
    public function gt(string $column, mixed $value): static { $this->filters[] = [$column, 'gt', (string) $value]; return $this; }
    public function gte(string $column, mixed $value): static { $this->filters[] = [$column, 'gte', (string) $value]; return $this; }
    public function lt(string $column, mixed $value): static { $this->filters[] = [$column, 'lt', (string) $value]; return $this; }
    public function lte(string $column, mixed $value): static { $this->filters[] = [$column, 'lte', (string) $value]; return $this; }
    public function like(string $column, string $pattern): static { $this->filters[] = [$column, 'like', $pattern]; return $this; }
    public function ilike(string $column, string $pattern): static { $this->filters[] = [$column, 'ilike', $pattern]; return $this; }
    public function is(string $column, string $value): static { $this->filters[] = [$column, 'is', $value]; return $this; }

    public function in(string $column, array $values): static
    {
        $this->filters[] = [$column, 'in', '(' . implode(',', array_map(fn($v) => (string) $v, $values)) . ')'];
        return $this;
    }
    public function not_in(string $column, array $values): static
    {
        $this->filters[] = [$column, 'notIn', '(' . implode(',', array_map(fn($v) => (string) $v, $values)) . ')'];
        return $this;
    }
    public function filter(string $column, string $op, mixed $value): static
    {
        $this->filters[] = [$column, $op, (string) $value];
        return $this;
    }

    public function limit(int $n): static { $this->limit_val = $n; return $this; }
    public function cursor(string $opaque): static { $this->cursor_val = $opaque; return $this; }
    public function count(string $mode): static { $this->count_mode = $mode; return $this; }

    /** @return array<string, string> */
    protected function to_query(): array
    {
        $q = [];
        foreach ($this->filters as [$col, $op, $val]) {
            $q[$col] = str_starts_with($val, '(') ? $op . '.' . $val : $op . '.' . $val;
        }
        if ($this->limit_val !== null) $q['limit'] = (string) $this->limit_val;
        if ($this->cursor_val !== null) $q['cursor'] = $this->cursor_val;
        if ($this->count_mode !== null) $q['count'] = $this->count_mode;
        return $q;
    }
}

final class SelectBuilder extends BaseBuilder
{
    public function __construct(
        private readonly HttpClientConfig $config,
        private readonly string $collection
    ) {}

    public function execute(): Envelope
    {
        return HttpTransport::request(
            $this->config, 'GET', FromBuilder::collection_path($this->config, $this->collection), null, $this->to_query()
        );
    }
}

final class UpdateBuilder extends BaseBuilder
{
    public function __construct(
        private readonly HttpClientConfig $config,
        private readonly string $collection,
        private readonly array $patch
    ) {}

    public function execute(): Envelope
    {
        $id_val = null;
        foreach ($this->filters as [$col, $op, $val]) {
            if ($col === 'id' && $op === 'eq') {
                $id_val = $val;
                break;
            }
        }
        if ($id_val === null) {
            throw new KError(
                'K9O_INVALID_FIELD_VALUE',
                'update() requires ->eq("id", ...) filter; row-mass update is not supported at v0.1.0',
                ['collection' => $this->collection]
            );
        }
        $path = FromBuilder::collection_path($this->config, $this->collection) . '/' . rawurlencode($id_val);
        return HttpTransport::request($this->config, 'PATCH', $path, $this->patch);
    }
}

final class DeleteBuilder extends BaseBuilder
{
    public function __construct(
        private readonly HttpClientConfig $config,
        private readonly string $collection
    ) {}

    public function execute(): Envelope
    {
        $id_val = null;
        foreach ($this->filters as [$col, $op, $val]) {
            if ($col === 'id' && $op === 'eq') {
                $id_val = $val;
                break;
            }
        }
        if ($id_val === null) {
            throw new KError(
                'K9O_INVALID_FIELD_VALUE',
                'delete() requires ->eq("id", ...) filter; row-mass delete is not supported at v0.1.0',
                ['collection' => $this->collection]
            );
        }
        $path = FromBuilder::collection_path($this->config, $this->collection) . '/' . rawurlencode($id_val);
        return HttpTransport::request($this->config, 'DELETE', $path);
    }
}
