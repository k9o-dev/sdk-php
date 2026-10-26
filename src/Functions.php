<?php

declare(strict_types=1);

namespace K9o;

/**
 * Function lifecycle namespace — $client->functions.
 *
 * Upload the function's bytes first (`upload_artifact` returns the `artifactKey`), then `deploy` references that
 * artifact. Deploy requires PROJECT_ADMIN and returns with status `deploying`; the function is ready when status
 * flips to `active`.
 */
final class Functions
{
    private const ARTIFACT_CONTENT_TYPES = ['wasm' => 'application/wasm', 'tar' => 'application/x-tar'];

    public function __construct(private readonly HttpClientConfig $config) {}

    private function require_project_slug(): string
    {
        if ($this->config->project_slug === null || $this->config->project_slug === '') {
            throw new KError('K9O_MISSING_PROJECT_CONTEXT', 'functions operations require project_slug bound on the client');
        }
        return $this->config->project_slug;
    }

    /**
     * POST /@<slug>/functions/artifacts with the raw bytes: a WebAssembly module for the gateway tier (up to 16 MiB,
     * `$kind` 'wasm') or an OCI image archive for the sandbox tier (up to 256 MiB, `$kind` 'tar'). Uploading the same
     * bytes again returns the same key. The envelope's data is `['artifactKey' => string]`.
     *
     * @param string|resource $bytes
     * @param 'wasm'|'tar' $kind
     */
    public function upload_artifact(mixed $bytes, string $kind): Envelope
    {
        $content_type = self::ARTIFACT_CONTENT_TYPES[$kind] ?? null;
        if ($content_type === null) {
            throw new KError('K9O_INVALID_FIELD_VALUE', 'kind must be "wasm" or "tar"', ['field' => 'kind', 'reason' => 'unknown_option']);
        }
        if (is_resource($bytes)) {
            $bytes = (string) stream_get_contents($bytes);
        } elseif (!is_string($bytes)) {
            throw new KError('K9O_INVALID_FIELD_VALUE', 'artifact bytes must be a string or a stream resource', ['field' => 'bytes', 'reason' => 'type']);
        }
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'functions/artifacts'), new RawBody($bytes, $content_type));
    }

    /**
     * POST /@<slug>/functions — deploy or redeploy from an uploaded artifact.
     *
     * runtime: bun python go java kotlin dotnet swift php ruby rust tinygo zig assemblyscript.
     * tier: 'gateway' (WebAssembly) or 'sandbox' (OCI image archive).
     * artifact_key: the key `upload_artifact` returned. version: label for this deploy (1 to 64 characters).
     */
    public function deploy(string $name, string $runtime, string $tier, string $artifact_key, string $version, ?string $description = null): Envelope
    {
        $slug = $this->require_project_slug();
        $body = ['name' => $name, 'runtime' => $runtime, 'tier' => $tier, 'artifactKey' => $artifact_key, 'version' => $version];
        if ($description !== null) $body['description'] = $description;
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'functions'), $body);
    }

    /** GET /@<slug>/functions (cursor pagination). */
    public function list(?string $status = null, ?int $limit = null, ?string $cursor = null): Envelope
    {
        $slug = $this->require_project_slug();
        $q = [];
        if ($status !== null) $q['status'] = $status;
        if ($limit !== null) $q['limit'] = (string) $limit;
        if ($cursor !== null) $q['cursor'] = $cursor;
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'functions'), null, $q);
    }

    public function get(string $name): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'functions/' . rawurlencode($name)));
    }

    /** Retire a function; its name enters a 24h cooldown. */
    public function delete(string $name): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::project_path($slug, 'functions/' . rawurlencode($name)));
    }

    /**
     * POST /@<slug>/functions/<name>/invoke. `$payload` is sent as the `payload` field of the request body and
     * passed to the function runtime verbatim. Optional controls: `background` (run asynchronously), `delegation`
     * (`['token' => ..., 'invokeSessionId' => ...]`), `on_behalf_of`, `confined_to`.
     *
     * @param array{background?: bool, delegation?: array{token: string, invokeSessionId?: string}, on_behalf_of?: string, confined_to?: string} $options
     */
    public function invoke(string $name, mixed $payload = null, array $options = []): Envelope
    {
        $slug = $this->require_project_slug();
        $map = ['background' => 'background', 'delegation' => 'delegation', 'on_behalf_of' => 'onBehalfOf', 'confined_to' => 'confinedTo'];
        $body = [];
        if ($payload !== null) $body['payload'] = $payload;
        foreach ($options as $k => $v) {
            if (!is_string($k) || !isset($map[$k])) {
                throw new KError('K9O_INVALID_FIELD_VALUE', sprintf('unknown invoke option "%s"', (string) $k), ['field' => (string) $k, 'reason' => 'unknown_option']);
            }
            if ($v !== null) $body[$map[$k]] = $v;
        }
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'functions/' . rawurlencode($name) . '/invoke'), $body === [] ? new \stdClass() : $body);
    }
}
