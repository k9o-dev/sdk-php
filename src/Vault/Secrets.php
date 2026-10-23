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
 * $client->vault->secrets->* — typed secrets surface
 * (`services/gateway/src/routes/vault-secrets.ts`). Full family: list /
 * get / create / reveal_challenge / reveal / update / rotate / move /
 * delete. Ports `packages/kibo/src/vault.ts`'s `secrets` object 1:1,
 * including every live-verified correction to the (partly wrong)
 * documented OpenAPI contract — see the per-method notes below.
 *
 * Every operation on a NAMED secret (everything except `list`) requires
 * `region` — live-verified against staging, NOT documented in the
 * route's OpenAPI schema. Omitting it fails with `K9O_INVALID_FIELD_VALUE`
 * "this request requires an authoritative region (?region=)". `list` is
 * the one exception (verified it does NOT need `region`).
 *
 * PHP idiom: snake_case methods + throw K9o\KError +
 * Envelope return, matching Studio\Workflows / Gate / Compute\Llms.
 */
final class Secrets
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * List secrets (metadata only — never lists ciphertext).
     * `GET /@<projectSlug>/vault/secrets`. The one secrets operation that
     * does NOT need `region` (live-verified).
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
     * Read a secret's metadata (never its value — use `reveal_challenge` +
     * `reveal` for that). `GET /@<projectSlug>/vault/secrets/<name>?region=<region>`.
     *
     * 🔴 KNOWN LIVE BUG (staging, 2026-09-05): this call currently fails
     * with `K9O_REVEAL_CHALLENGE_REQUIRED` on every attempt — the
     * gateway/kibo does not honor the documented "no
     * `X-K9O-Reveal-Challenge` header ⇒ return metadata" behavior; it
     * demands a challenge unconditionally. Implemented per the documented
     * contract anyway (this is a backend defect to flag, not something to
     * design around) — mirrors `packages/kibo/src/vault.ts` `secrets.get`.
     * `list(prefix: exactName)` is the current working alternative for a
     * single secret's metadata until the gateway/kibo side is fixed.
     */
    public function get(string $name, string $region): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        return HttpTransport::request(
            $this->config, 'GET',
            UrlGrammar::project_path($slug, self::path_for($name)),
            null, self::region_query($region)
        );
    }

    /**
     * Create a secret under the caller's own auth.
     * `POST /@<projectSlug>/vault/secrets/<name>?region=<region>` → the
     * `sec_<base62>` summary.
     *
     * `region` is REQUIRED on the query string, live-verified against
     * staging — the vault-forward proxy fail-closes with
     * `K9O_INVALID_FIELD_VALUE` without it.
     *
     * `$value` is the caller-facing plaintext; this method base64-encodes
     * it for the wire (live-verified: a raw non-base64 `value` is
     * rejected with `K9O_INVALID_FIELD_VALUE` "Request body could not be
     * parsed", contradicting the OpenAPI's "the plaintext to seal"
     * description).
     *
     * @param array{kind: string, principals?: array<int, string>, roles?: array<int, string>}|null $visibility
     * @param array<string, string>|null $metadata
     */
    public function create(string $name, string $value, string $region, ?array $visibility = null, ?array $metadata = null): Envelope
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
     * call. `POST .../<name>?op=reveal-challenge&region=<region>`.
     */
    public function reveal_challenge(string $name, string $region): Envelope
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
     * `reveal_challenge()`. `GET .../<name>?region=<region>` with
     * `X-K9O-Reveal-Challenge`. Decodes the wire's base64 `value` back to
     * plaintext (live-verified: the reveal response's `value` is
     * base64, symmetric with `create`'s encode) — the caller-facing API
     * always deals in plaintext, never base64.
     */
    public function reveal(string $name, string $region, string $challenge): Envelope
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
     * `PUT .../<name>?region=<region>`.
     *
     * 🔴 Returns only `{id, name, version}` live — NARROWER than the
     * OpenAPI schema's documented full `SecretSummary` (live-verified).
     * Call `get()` after if the full summary is needed. `$value` is
     * base64-encoded for the wire, same as `create`.
     *
     * @param array<string, string>|null $metadata
     */
    public function update(string $name, string $region, string $value, ?array $metadata = null): Envelope
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
     * content unchanged) — owner-only. `POST .../<name>?op=rotate&region=<region>`.
     *
     * 🔴 Response shape (`{id, name, version, rotatedAt,
     * ledgerSequenceNumber}`) is live-verified and undocumented in the
     * OpenAPI schema.
     */
    public function rotate(string $name, string $region): Envelope
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
     * `POST .../<name>?op=move&region=<region>`.
     *
     * 🔴 Response shape (`{id, name, event}`) is live-verified and
     * undocumented in the OpenAPI schema. `event` was observed as the
     * string `"SECRET_PATH_CHANGED"` — treated as a plain string, not a
     * closed enum, since only one sample was ever observed.
     */
    public function move(string $name, string $region, string $new_path): Envelope
    {
        $slug = Vault::require_project_slug($this->config, 'vault.secrets');
        return HttpTransport::request(
            $this->config, 'POST',
            UrlGrammar::project_path($slug, self::path_for($name)),
            ['newPath' => $new_path], self::region_query($region, 'move')
        );
    }

    /**
     * Retire a secret — owner-only. `DELETE .../<name>?region=<region>` →
     * `204 No Content`.
     */
    public function delete(string $name, string $region): Envelope
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
     * Build the `region` (+ optional `op`) query for a named-secret
     * operation. `region` is REQUIRED on every operation except `list` —
     * live-verified against staging (`K9O_INVALID_FIELD_VALUE` "this
     * request requires an authoritative region (?region=)"), not
     * documented anywhere in the OpenAPI schema. `op` (when present) is
     * ordered first to match the TS reference's query shape.
     *
     * @return array<string, string>
     * @throws KError K9O_INVALID_FIELD_VALUE when `$region` is empty
     */
    public static function region_query(string $region, ?string $op = null): array
    {
        if ($region === '') {
            throw new KError(
                'K9O_INVALID_FIELD_VALUE',
                'vault.secrets operations require a non-empty region (?region=); '
                    . 'live-verified: the vault-forward proxy fail-closes without an authoritative region'
            );
        }
        $query = $op !== null ? ['op' => $op] : [];
        $query['region'] = $region;
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
     * the wire — live-verified: the vault-secrets wire `value` field is
     * base64 in BOTH directions, contradicting the OpenAPI's
     * plain-language "the plaintext to seal" description. Exposed
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
