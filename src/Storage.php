<?php

declare(strict_types=1);

namespace K9o;

/**
 * client->storage->* — per-project logical buckets and the objects in them, against
 * `/@<slug>/storage/buckets...`. Flat methods; `from($bucket)` returns a StorageBucketHandle for object operations.
 *
 * Bucket names are logical (the platform resolves them to a physical store). Signed URLs need an explicit
 * `expires_in` (1..604800 seconds, no default). Not offered, by design: signed PUT upload URLs (the gateway has
 * no route), copy, and image transforms.
 *
 * Option arrays use snake_case keys (translated to the camelCase wire fields): public, file_size_limit,
 * allowed_mime_types, versioning, delete_after_days, byos_binding_id. An unknown key is refused client-side.
 */
final class Storage
{
    private const BUCKET_FIELDS = [
        'public' => 'public',
        'file_size_limit' => 'fileSizeLimit',
        'allowed_mime_types' => 'allowedMimeTypes',
        'versioning' => 'versioning',
        'delete_after_days' => 'deleteAfterDays',
        'byos_binding_id' => 'byosBindingId',
    ];

    public function __construct(private readonly HttpClientConfig $config) {}

    /** @internal */
    public static function buckets_path(HttpClientConfig $config, ?string $bucket = null, string $suffix = ''): string
    {
        $slug = Studio::require_project_slug($config, 'storage');
        $sub = 'storage/buckets';
        if ($bucket !== null) {
            if ($bucket === '') {
                throw new KError('K9O_INVALID_FIELD_VALUE', 'bucket name must not be empty', ['field' => 'bucket', 'reason' => 'empty']);
            }
            if ($bucket === '.' || $bucket === '..') {
                throw new KError('K9O_INVALID_FIELD_VALUE', 'bucket name must not be a dot segment', ['field' => 'bucket', 'reason' => 'dot_segment']);
            }
            $sub .= '/' . rawurlencode($bucket);
        }
        return UrlGrammar::project_path($slug, $sub . $suffix);
    }

    /** GET /@<slug>/storage/buckets */
    public function list_buckets(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', self::buckets_path($this->config));
    }

    /** GET .../buckets/<name> */
    public function get_bucket(string $name): Envelope
    {
        return HttpTransport::request($this->config, 'GET', self::buckets_path($this->config, $name));
    }

    /**
     * POST .../buckets. A name outside 3..63 lowercase letters, digits and hyphens is refused by the server
     * (K9O_INVALID_BUCKET_NAME).
     *
     * @param array<string, mixed> $opts
     */
    public function create_bucket(string $name, array $opts = []): Envelope
    {
        $path = self::buckets_path($this->config);
        return HttpTransport::request($this->config, 'POST', $path, ['name' => $name] + $this->wire($opts));
    }

    /**
     * PATCH .../buckets/<name>
     *
     * @param array<string, mixed> $patch
     */
    public function update_bucket(string $name, array $patch): Envelope
    {
        $path = self::buckets_path($this->config, $name);
        $body = $this->wire($patch);
        return HttpTransport::request($this->config, 'PATCH', $path, $body === [] ? new \stdClass() : $body);
    }

    /**
     * DELETE .../buckets/<name>. A non-empty bucket is refused (409 bucket_not_empty) unless `$empty` is true,
     * which purges its objects first. `?empty=true` is sent only when `$empty` is true.
     */
    public function delete_bucket(string $name, bool $empty = false): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', self::buckets_path($this->config, $name), null,
            $empty ? ['empty' => 'true'] : []);
    }

    /** A handle on one bucket for object operations: `$client->storage->from('avatars')->upload(...)`. */
    public function from(string $bucket): StorageBucketHandle
    {
        return new StorageBucketHandle($this->config, $bucket);
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    private function wire(array $opts): array
    {
        $out = [];
        foreach ($opts as $k => $v) {
            if (!is_string($k) || !isset(self::BUCKET_FIELDS[$k])) {
                throw new KError('K9O_INVALID_FIELD_VALUE', sprintf('unknown bucket option "%s"', (string) $k),
                    ['field' => (string) $k, 'reason' => 'unknown_option']);
            }
            $out[self::BUCKET_FIELDS[$k]] = $v;
        }
        return $out;
    }
}
