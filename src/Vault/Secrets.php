<?php

declare(strict_types=1);

namespace K9o\Vault;

use K9o\Envelope;
use K9o\HttpClientConfig;
use K9o\HttpTransport;
use K9o\KError;
use K9o\UrlGrammar;
use K9o\Vault;

/**
 * $client->vault->secrets->* - the typed secrets surface
 * (`services/gateway/src/routes/vault-secrets.ts`). Full family: list /
 * get / create / reveal_challenge / reveal / update / rotate / move /
 * delete. Ports `packages/kibo/src/vault.ts`'s `secrets` object.
 *
 * `region` is OPTIONAL on every named-secret operation: the gateway ignores
 * any caller-supplied region and uses its own. It is sent on the query
 * string only when the caller passes one (never by `get`).
 *
 * PHP idiom: snake_case methods + throw K9o\KError + Envelope return,
 * matching Studio\Workflows / Gate / Compute\Llms.
 */
final class Secrets
{
    private const MAX_GET_PAGES = 20;

    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * List secrets (metadata only — never lists ciphertext).
     * `GET /@<projectSlug>/vault/secrets`.
     */
    public function list(?string $prefix = null, ?string $cursor = null, ?int $limit = null): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        $query = [];
        if ($prefix !== null) $query['prefix'] = $prefix;
        if ($cursor !== null) $query['cursor'] = $cursor;
        if ($limit !== null) $query['limit'] = (string) $limit;
        return HttpTransport::request(
            $this->config, 'GET', UrlGrammar::project_path($slug, 'vault/secrets'), null, $query
        );
    }

    /**
     * Read a secret's metadata (never its value - use `reveal_challenge` +
     * `reveal` for that).
     *
     * `GET /@<projectSlug>/vault/secrets/<name>` is a reveal and always
     * demands an `X-K9O-Reveal-Challenge` header, so metadata is read from
     * the list route: list with the exact name as `prefix` (limit 100,
     * following `nextCursor` for up to 20 pages) and return the secret whose
     * `name` equals `$name`. Throws `KError` `K9O_NOT_FOUND` (status 404)
     * when none matches. `$region` is accepted for symmetry and not sent.
     */
    public function get(string $name, ?string $region = null): Envelope
    {
        $cursor = null;
        for ($page = 0; $page < self::MAX_GET_PAGES; $page++) {
            $env = $this->list($name, $cursor, 100);
            $data = is_array($env->data) ? $env->data : [];
            foreach ($data['secrets'] ?? [] as $secret) {
                if (is_array($secret) && ($secret['name'] ?? null) === $name) {
                    return new Envelope($secret, $env->meta);
                }
            }
            $next = $data['nextCursor'] ?? null;
            if (!is_string($next) || $next === '') break;
            $cursor = $next;
        }
        throw new KError(
            'K9O_NOT_FOUND',
            "No secret named '" . $name . "' in this project.",
            ['name' => $name],
            null,
            404
        );
    }

    /**
     * Create a secret under the caller's own auth.
     * `POST /@<projectSlug>/vault/secrets/<name>` (+ `region` when given) -> the
     * `sec_<base62>` summary.
     *
     * `$value` is the caller-facing plaintext; this method base64-encodes
     * it for the wire.
     *
     * @param array{kind: string, principals?: array<int, string>, roles?: array<int, string>}|null $visibility
     * @param array<string, string>|null $metadata
     */
    public function create(string $name, string $value, ?string $region = null, ?array $visibility = null, ?array $metadata = null): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        $body = ['value' => self::encode_value($value)];
        if ($visibility !== null) $body['visibility'] = $visibility;
        if ($metadata !== null) $body['metadata'] = $metadata;
        return HttpTransport::request(
            $this->config, 'POST',
            UrlGrammar::project_path($slug, self::path_for($name)),
            $body, self::region_query($region)
        );
    }

    /**
     * Mint a single-use reveal challenge — present it as
     * `X-K9O-Reveal-Challenge` on the immediately-following `reveal()`
     * call. `POST .../<name>?op=reveal-challenge`.
     */
    public function reveal_challenge(string $name, ?string $region = null): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        return HttpTransport::request(
            $this->config, 'POST',
            UrlGrammar::project_path($slug, self::path_for($name)),
            null, self::region_query($region, 'reveal-challenge')
        );
    }

    /**
     * Reveal a secret's plaintext value using a single-use challenge from
     * `reveal_challenge()`. `GET .../<name>` with
     * `X-K9O-Reveal-Challenge`. Decodes the wire's base64 `value` back to
     * plaintext (the reveal response's `value` is base64,
     * symmetric with `create`'s encode) — the caller-facing API
     * always deals in plaintext, never base64.
     */
    public function reveal(string $name, string $challenge, ?string $region = null): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        $envelope = HttpTransport::request(
            $this->config, 'GET',
            UrlGrammar::project_path($slug, self::path_for($name)),
            null, self::region_query($region), self::reveal_header($challenge)
        );
        $data = $envelope->data;
        if (is_array($data) && isset($data['value']) && is_string($data['value'])) {
            $data['value'] = self::decode_value($data['value']);
        }
        return new Envelope($data, $envelope->meta);
    }

    /**
     * Update a secret's value (bumps version) — owner-only.
     * `PUT .../<name>`.
     *
     * Returns only `{id, name, version}`, narrower than a full
     * `SecretSummary`.
     * Call `get()` after if the full summary is needed. `$value` is
     * base64-encoded for the wire, same as `create`.
     *
     * @param array<string, string>|null $metadata
     */
    public function update(string $name, string $value, ?string $region = null, ?array $metadata = null): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        $body = ['value' => self::encode_value($value)];
        if ($metadata !== null) $body['metadata'] = $metadata;
        return HttpTransport::request(
            $this->config, 'PUT',
            UrlGrammar::project_path($slug, self::path_for($name)),
            $body, self::region_query($region)
        );
    }

    /**
     * Rotate a secret's wrapping key (re-wraps under a fresh `kid`;
     * content unchanged) — owner-only. `POST .../<name>?op=rotate`.
     *
     * Response shape: `{id, name, version, rotatedAt,
     * ledgerSequenceNumber}`.
     */
    public function rotate(string $name, ?string $region = null): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        return HttpTransport::request(
            $this->config, 'POST',
            UrlGrammar::project_path($slug, self::path_for($name)),
            null, self::region_query($region, 'rotate')
        );
    }

    /**
     * Move (rename) a secret's hierarchical path — owner-only.
     * `POST .../<name>?op=move`.
     *
     * Response shape: `{id, name, event}`. `event` was observed as the
     * string `"SECRET_PATH_CHANGED"` — treated as a plain string, not a
     * closed enum, since only one sample was ever observed.
     */
    public function move(string $name, string $new_path, ?string $region = null): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        return HttpTransport::request(
            $this->config, 'POST',
            UrlGrammar::project_path($slug, self::path_for($name)),
            ['newPath' => $new_path], self::region_query($region, 'move')
        );
    }

    /**
     * Retire a secret - owner-only. `DELETE .../<name>`; any 2xx is success
     * (204 No Content maps to a null `data`).
     */
    public function delete(string $name, ?string $region = null): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        return HttpTransport::request(
            $this->config, 'DELETE',
            UrlGrammar::project_path($slug, self::path_for($name)),
            null, self::region_query($region)
        );
    }

    /**
     * Build the `vault/secrets/<name>` sub-resource path, matching the TS
     * reference's `encodeURIComponent` — `rawurlencode` also percent-encodes
     * `/`, so a hierarchical name like `db/pw` becomes `db%2Fpw` (the
     * gateway addresses the whole hierarchical name as a single path
     * segment). Exposed `public static` (rather than `private`) purely so
     * the exact wire encoding is directly unit-testable without a live
     * HTTP round-trip, matching HttpTransport's own
     * is_retryable/compute_delay_ms testability pattern.
     */
    public static function path_for(string $name): string
    {
        return 'vault/secrets/' . rawurlencode($name);
    }

    /**
     * Build the (optional) `op` + `region` query for a named-secret
     * operation. `region` is sent only when the caller passes a non-empty
     * one. `op` (when present) is ordered first to match the TS reference's
     * query shape.
     *
     * @return array<string, string>
     */
    public static function region_query(?string $region = null, ?string $op = null): array
    {
        $query = $op !== null ? ['op' => $op] : [];
        if ($region !== null && $region !== '') {
            $query['region'] = $region;
        }
        return $query;
    }

    /**
     * Build the single-use `X-K9O-Reveal-Challenge` header presented on
     * `reveal()`. Exposed `public static` for direct unit testing.
     *
     * @return array<string, string>
     */
    public static function reveal_header(string $challenge): array
    {
        return ['X-K9O-Reveal-Challenge' => $challenge];
    }

    /**
     * UTF-8-safe base64 encode of a caller-facing plaintext `value` for
     * the wire - the vault-secrets wire `value` field is base64 in BOTH
     * directions. Exposed
     * `public static` for direct unit testing (round-trip with
     * `decode_value`).
     */
    public static function encode_value(string $plaintext): string
    {
        return base64_encode($plaintext);
    }

    /**
     * Inverse of {@see encode_value} — decodes a revealed wire `value`
     * back to plaintext.
     */
    public static function decode_value(string $wire_value): string
    {
        return base64_decode($wire_value);
    }
}
