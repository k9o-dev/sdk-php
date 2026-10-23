# Changelog — k9o/sdk (PHP)

## Unreleased

- **Requires PHP 8.5.12 or later** (was 8.3): a critical PHP vulnerability fixed in 8.5.12. `composer.json` declares `"php": ">=8.5.12"`.
- `$client->storage` buckets and objects: `list_buckets()`, `get_bucket($name)`, `create_bucket($name, $opts)`, `update_bucket($name, $patch)`, `delete_bucket($name, empty: false)` (`?empty=true` only when true) against `/@<slug>/storage/buckets[/<bucket>]`, and `from($bucket)` -> `upload($path, $data, ['content_type','upsert','metadata'])` (real multipart/form-data: parts `file`, `path`, `contentType`, `upsert`, `metadata`; bytes sent as given), `create_signed_url($path, $expires_in, $download)`, `list($prefix)`, `remove($path)`. `expires_in` is required (integer 1..604800, else `K9O_INVALID_FIELD_VALUE` before any request; no default). Bucket names and paths are URL-encoded and never rewritten; server errors (409 `bucket_not_empty`, `K9O_INVALID_BUCKET_NAME`) surface unchanged as `K9o\KError`; requires a bound `project_slug`. No signed PUT, no copy, no transform. `HttpTransport` gains `RawBody` (a pre-encoded body with its own content-type) so a multipart upload is not forced to application/json.
- `$client->gate->hosted()` — `GET /@<slug>/gate/hosted` (unauthenticated, cacheable) -> `{data:{signInUrl, rpId}, meta}`. Sends no Authorization header (even with `api_key` set) and no cookie; regional-host resolution applies as for any call; requires a bound `project_slug`. An unknown org/project, deleted project and non-Gate project all surface the same 404 `K9O_PROJECT_NOT_FOUND` `K9o\KError`.
- `$client->ai->embed($input, $model = null)` — `POST /@<slug>/ai/embeddings` body `{model?, input: string | string[]}` -> `{data:{embeddings, model, dimensions}, meta}`. `model` omitted from the body when null; input sent exactly as given; requires a bound `project_slug` (`K9O_MISSING_PROJECT_CONTEXT` otherwise); no client-side content validation; server errors surface as `K9o\KError` and the input text is never placed in an SDK-constructed error.

## v0.1.0 — 2026-05-26 (P3 wave 6 — PHP idiomatic reshape)

**Reshape** of `packages/php` from prior OpenAPI-generated `kilimanjaro-dev/k9o-gate@0.3.0` raw client (210-file output; wrong Composer name; not idiomatic) to hand-authored idiomatic `k9o/sdk`.

NEW surfaces:

- `K9oClient::create([...])` factory + baseUrl validation; throws KError on missing required keys
- `KError extends \Exception` — uses `__get` magic accessor to expose `$e->code` while storing internally as `$kerror_code` (PHP 8.5 forbids shadowing parent `\Exception::$code` untyped int); ch13 spec sample `$e->code` preserved verbatim
- `Envelope` + `Meta` with 6 typed sub-accessor classes (`MetaCursor` / `MetaSubscription` / `MetaIdempotency` / `MetaWarning` / `MetaTiming` / `MetaContext`); readonly properties; explicit wire field-name translation in `Envelope::fromWire`
- `UrlGrammar::{validate_base_url, validate_slug, project_path, org_path, platform_path}` static helpers; throws KError on violation
- Data-plane DSL `$client->from('collection')` chainable filter builder (11 ops: eq / neq / gt / gte / lt / lte / like / ilike / is + `in($col, [...])` / `not_in` + generic `filter`) + `->limit(n)` / `->cursor($s)` / `->count($mode)` + `->execute()`; insert/update/delete with `->eq('id', ...)` discipline
- `$client->projects->{list, create, get, update}` per ch02 (named arguments support)
- `$client->gate->{sign_up, sign_in, sign_out, whoami, refresh_token, verify_session}` (project-scoped; named arguments)
- HTTP layer: `ext-curl` stdlib; KError rehydration on non-2xx; transport failure → K9O_UPSTREAM_UNAVAILABLE; malformed envelope → K9O_INTERNAL_ERROR

**Tests:** 33 pass / 42 assertions / 0 fail (`vendor/bin/phpunit`):
- 5 KErrorTest (construct + throwable + ToString + withRecommendation + fromWire rehydration)
- 18 UrlGrammarTest (DataProvider-driven cases for accept/reject baseUrl + slug + path builders)
- 10 ClientTest (create + normalise + reject path-bearing + missing baseUrl + DSL requirements + projects + gate + collection validation)

**Dependencies:** PHP 8.3+ runtime; `ext-json` + `ext-curl` (stdlib).
**Dev:** PHPUnit 11.

Dropped PHP from `openapitools.json` + `package.json` `sdks:generate:php` — hand-authored against `services/gateway/openapi.json` contract going forward.

Forward sub-cycles: migrations + functions + policies + audit + orgs namespaces; retry/backoff; Generator-based subscription streaming.

---

**Prior history**: `kilimanjaro-dev/k9o-gate@0.3.0` OpenAPI-generator output retired at this version.
