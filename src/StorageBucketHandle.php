<?php

declare(strict_types=1);

namespace K9o;

/** Object operations on one bucket: `$client->storage->from('avatars')`. */
final class StorageBucketHandle
{
    public const MAX_EXPIRES_IN = 604800;

    public function __construct(private readonly HttpClientConfig $config, private readonly string $bucket) {}

    /**
     * Upload an object: `POST .../buckets/<bucket>/objects` (multipart/form-data: parts `file`, `path`, optional
     * `contentType`, `upsert`, `metadata`). Bytes are sent as given (a PHP string is bytes; a stream resource is
     * read to its end). The path is not rewritten (the server decides what `..` or a leading `/` means).
     *
     * @param string|resource $data
     * @param array{content_type?: string, upsert?: bool, metadata?: array<string, string>} $opts
     */
    public function upload(string $path, mixed $data, array $opts = []): Envelope
    {
        $this->require_path($path);
        $url_path = Storage::buckets_path($this->config, $this->bucket, '/objects');
        if (is_resource($data)) {
            $data = (string) stream_get_contents($data);
        } elseif (!is_string($data)) {
            throw new KError('K9O_INVALID_FIELD_VALUE', 'upload data must be a string or a stream resource', ['field' => 'data', 'reason' => 'type']);
        }
        $content_type = $opts['content_type'] ?? null;
        $boundary = '----k9o' . bin2hex(random_bytes(16));
        $filename = str_replace(['"', "\r", "\n", '\\'], '_', ($base = basename($path)) === '' ? 'file' : $base);

        $body = '';
        $part = static function (string $name, string $value, string $extra = '') use (&$body, $boundary): void {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"$extra\r\n\r\n$value\r\n";
        };
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n"
            . 'Content-Type: ' . ($content_type ?? 'application/octet-stream') . "\r\n\r\n" . $data . "\r\n";
        $part('path', $path);
        if ($content_type !== null) $part('contentType', $content_type);
        $part('upsert', ($opts['upsert'] ?? false) === true ? 'true' : 'false');
        if (isset($opts['metadata'])) $part('metadata', json_encode((object) $opts['metadata']));
        $body .= "--$boundary--\r\n";

        return HttpTransport::request($this->config, 'POST', $url_path, new RawBody($body, 'multipart/form-data; boundary=' . $boundary));
    }

    /**
     * A pre-authenticated GET URL for one object: `POST .../objects/sign`. `expires_in` is REQUIRED (integer
     * seconds, 1..604800); there is no default. Refused client-side (K9O_INVALID_FIELD_VALUE) before any request.
     *
     * @param mixed $expires_in
     */
    public function create_signed_url(string $path, mixed $expires_in, ?bool $download = null): Envelope
    {
        $this->require_path($path);
        if (!is_int($expires_in) || $expires_in < 1 || $expires_in > self::MAX_EXPIRES_IN) {
            throw new KError(
                'K9O_INVALID_FIELD_VALUE',
                sprintf('expires_in is required and must be an integer from 1 to %d seconds', self::MAX_EXPIRES_IN),
                ['field' => 'expiresIn', 'reason' => 'out_of_range'],
                'Pass an explicit expires_in; signed URLs have no default lifetime.'
            );
        }
        $body = ['path' => $path, 'expiresIn' => $expires_in];
        if ($download !== null) $body['download'] = $download;
        return HttpTransport::request($this->config, 'POST', Storage::buckets_path($this->config, $this->bucket, '/objects/sign'), $body);
    }

    /** List objects, optionally under a prefix: `GET .../objects?prefix=`. */
    public function list(?string $prefix = null): Envelope
    {
        return HttpTransport::request($this->config, 'GET', Storage::buckets_path($this->config, $this->bucket, '/objects'), null,
            $prefix !== null ? ['prefix' => $prefix] : []);
    }

    /** Delete one object: `DELETE .../objects?path=`. */
    public function remove(string $path): Envelope
    {
        $this->require_path($path);
        return HttpTransport::request($this->config, 'DELETE', Storage::buckets_path($this->config, $this->bucket, '/objects'), null, ['path' => $path]);
    }

    private function require_path(string $path): void
    {
        if ($path === '') {
            throw new KError('K9O_INVALID_FIELD_VALUE', 'object path must not be empty', ['field' => 'path', 'reason' => 'empty']);
        }
    }
}
