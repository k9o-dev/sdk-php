<?php

declare(strict_types=1);

namespace K9o;

/**
 * Migration lifecycle namespace — $client->migrations.
 * PHP idiom: snake_case + Envelope return + throw KError.
 *
 * Wire mapping (member-plane, /@<projectSlug>/migrations/...):
 *   - apply($migrations)            → POST   /@<slug>/migrations/apply
 *   - list()                        → GET    /@<slug>/migrations
 *   - get($version)                 → GET    /@<slug>/migrations/<version>
 *   - queue($migrations)            → POST   /@<slug>/migrations/queue
 *   - list_pending()                → GET    /@<slug>/migrations/pending
 *   - apply_pending($pendingId)     → POST   /@<slug>/migrations/pending/<pendingId>/apply
 *   - reject_pending($pendingId)    → POST   /@<slug>/migrations/pending/<pendingId>/reject
 *   - delete_pending($pendingId)    → DELETE /@<slug>/migrations/pending/<pendingId>
 *
 * TYPE MODEL — tier-3. PHP ships a
 * documented + STATICALLY-CHECKABLE pass-through: the request shapes stay plain
 * `array` at runtime (no codegen), but the CONTROL-PLANE migration shape is
 * pinned with PHPStan/Psalm array-shape docblocks so `@param array{...}` is
 * checked at analysis time. The closed-union property of `Operation` is enforced
 * at the wire + server validation (fail-closed source of truth); the docblocks
 * are ergonomics, not validation.
 *
 * Migration shape — STRUCTURED
 * DECLARATIVE DDL, never raw SQL. Every schema change is a typed, key-tagged
 * operation, so schema drift is impossible at the SDK layer (invariant).
 * Raw SQL is the separate runtime client.query() surface, which cannot
 * create/alter schema.
 *
 * Op set (these 7): create_table | drop_table | add_column | drop_column |
 * alter_table | create_index | drop_index. alter_table is the BATCHED column
 * mutation (live in the gateway runner). No alter_column /
 * rename_table / constraint ops at v2. rename_column is a pending future op —
 * added in one bump once its parser field names are defined.
 *
 * --- ColumnReferences: foreign-key reference (compiles to REFERENCES) ---
 * @phpstan-type ColumnReferences array{
 *     collection: string,
 *     on: string,
 *     on_delete?: 'CASCADE'|'RESTRICT'|'SET NULL'|'NO ACTION',
 *     on_update?: 'CASCADE'|'RESTRICT'|'SET NULL'|'NO ACTION'
 * }
 *
 * --- Column at-rest field encryption. The `encrypted` attribute is
 * the union `true | { store, searchable? }`: the bare `true` is the inline
 * shorthand; the object form opts into store ("inline"|"vault") and the D-mode
 * searchable-encryption index. NOTE: `searchable` is NESTED inside the object,
 * NOT a sibling of the column. ---
 * @phpstan-type ColumnEncryption true|array{store: 'inline'|'vault', searchable?: bool}
 *
 * --- ColumnSpec: a column definition. `type` is the column-type
 * vocabulary (parser-owned string, NOT a fabricated enum). ---
 * @phpstan-type ColumnSpec array{
 *     name: string,
 *     type: string,
 *     primary_key?: bool,
 *     not_null?: bool,
 *     unique?: bool,
 *     default?: string,
 *     references?: ColumnReferences,
 *     encrypted?: ColumnEncryption,
 *     blind_indexed?: bool
 * }
 *
 * --- IndexSpec: an index definition. `name` defaults to <table>_<cols>_idx. ---
 * @phpstan-type IndexSpec array{name?: string, columns: list<string>, unique?: bool}
 *
 * --- Operation: a single declarative DDL operation — KEY-TAGGED (exactly one
 * of the 6 keys set). PHP array shapes cannot express a closed discriminated
 * union, so each variant is its own single-key shape and `Operation` is their
 * union; analysis flags an unknown tag, and the server rejects any non-member
 * tag at apply time. ---
 * @phpstan-type Operation array{create_table: array{name: string, columns: list<ColumnSpec>, indexes?: list<IndexSpec>}}
 *     |array{drop_table: string}
 *     |array{add_column: array{table: string, column: ColumnSpec}}
 *     |array{drop_column: array{table: string, column: string}}
 *     |array{alter_table: array{name: string, add_columns?: list<ColumnSpec>, drop_columns?: list<string>}}
 *     |array{create_index: array{table: string, index: IndexSpec}}
 *     |array{drop_index: array{name: string}}
 *
 * --- Policy section authored alongside a migration (/). ---
 * @phpstan-type ActionRule array{rule?: mixed}
 * @phpstan-type RelationshipSpec array{collection: string, on: string, as: string, type?: 'inner'|'left'}
 * @phpstan-type CollectionPolicy array{
 *     references?: list<RelationshipSpec>,
 *     actions?: array{select?: ActionRule, insert?: ActionRule, update?: ActionRule, delete?: ActionRule}
 * }
 * @phpstan-type StorageBucketPolicy array{
 *     public?: bool,
 *     actions?: array{read?: ActionRule, upload?: ActionRule, delete?: ActionRule, list?: ActionRule}
 * }
 * @phpstan-type MigrationPolicy array{
 *     collections?: array<string, CollectionPolicy>,
 *     storage?: array{buckets?: array<string, StorageBucketPolicy>}
 * }
 *
 * --- Migration: a versioned, checksummed unit of structured DDL. `up` applies
 * the change; `down` reverses it. Both are Operation arrays. ---
 * @phpstan-type Migration array{
 *     version: int,
 *     slug: string,
 *     up: list<Operation>,
 *     down: list<Operation>,
 *     policy?: MigrationPolicy
 * }
 *
 * --- Server-returned records. ---
 * @phpstan-type AppliedMigration array{version: int, slug: string, checksum: string, appliedAt: string}
 * @phpstan-type PendingMigration array{
 *     pendingId: string, version: int, slug: string, checksum: string,
 *     submittedAt: string, submittedBy: string
 * }
 *
 * @phpstan-import-type Migration from Migrations
 */
final class Migrations
{
    public function __construct(private readonly HttpClientConfig $config) {}

    private function require_project_slug(): string
    {
        if ($this->config->project_slug === null || $this->config->project_slug === '') {
            throw new KError('K9O_MISSING_PROJECT_CONTEXT', 'migrations operations require project_slug bound on the client');
        }
        return $this->config->project_slug;
    }

    /**
     * Apply migrations immediately (PROJECT_ADMIN minimum).
     *
     * @param list<Migration> $migrations structured declarative-DDL migrations
     */
    public function apply(array $migrations): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'migrations/apply'), ['migrations' => $migrations]);
    }

    /** List applied migrations on the project. */
    public function list(): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'migrations'));
    }

    /** Get a single applied migration by version. */
    public function get(string $version): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'migrations/' . rawurlencode($version)));
    }

    /**
     * Queue migrations for maker-checker approval.
     *
     * @param list<Migration> $migrations structured declarative-DDL migrations
     */
    public function queue(array $migrations): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'migrations/queue'), ['migrations' => $migrations]);
    }

    /** List pending (queued, not yet approved) migrations. */
    public function list_pending(): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'migrations/pending'));
    }

    /** Approve + apply a pending migration. */
    public function apply_pending(string $pending_id): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'migrations/pending/' . rawurlencode($pending_id) . '/apply'));
    }

    /** Reject a pending migration (transitions to rejected; does not apply). */
    public function reject_pending(string $pending_id, ?string $reason = null): Envelope
    {
        $slug = $this->require_project_slug();
        $body = $reason !== null ? ['reason' => $reason] : null;
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'migrations/pending/' . rawurlencode($pending_id) . '/reject'), $body);
    }

    /** Delete a pending migration from the queue. */
    public function delete_pending(string $pending_id): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::project_path($slug, 'migrations/pending/' . rawurlencode($pending_id)));
    }
}
