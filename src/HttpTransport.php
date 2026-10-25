<?php

declare(strict_types=1);

namespace K9o;

/**
 * HTTP transport using ext-curl (no Guzzle/external HTTP client dep).
 */
final class HttpTransport
{
    private const RETRYABLE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'DELETE'];

    private function __construct() {}

    /**
     * Issue a request with retry on transient failures. Idempotent methods
     * are retried with backoff per `$config->retry_config`;
     * semantic / non-idempotent failures throw immediately.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers extra per-request headers (e.g. the
     *     `X-K9O-Reveal-Challenge` header on `vault.secrets.reveal`), merged
     *     after the standard project/org/auth headers.
     */
    public static function request(
        HttpClientConfig $config,
        string $method,
        string $path,
        mixed $body = null,
        array $query = [],
        array $headers = [],
        bool $anonymous = false
    ): Envelope {
        return self::retry(
            fn (): Envelope => self::request_once($config, $method, $path, $body, $query, $headers, $anonymous),
            $method,
            $config->retry_config ?? RetryConfig::default()
        );
    }

    /**
     * Run an attempt callable with retry-with-backoff. Extracted so the
     * retry policy is unit-testable without a live HTTP round-trip.
     *
     * @param callable():Envelope $attempt
     */
    public static function retry(callable $attempt, string $method, RetryConfig $config): Envelope
    {
        $last = null;
        for ($i = 0; $i <= $config->max_retries; $i++) {
            try {
                return $attempt();
            } catch (KError $e) {
                $last = $e;
                if ($i === $config->max_retries || !self::is_retryable($e, $method)) {
                    throw $e;
                }
                $delay = self::compute_delay_ms($config, $i);
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }
        throw $last; // unreachable: loop returns or throws
    }

    public static function is_retryable(KError $e, string $method): bool
    {
        if (!in_array(strtoupper($method), self::RETRYABLE_METHODS, true)) {
            return false;
        }
        if ($e->code === 'K9O_UPSTREAM_UNAVAILABLE') {
            return true;
        }
        return in_array($e->status, [502, 503, 504, 429], true);
    }

    public static function compute_delay_ms(RetryConfig $c, int $attempt): int
    {
        if ($c->backoff === 'none') {
            return 0;
        }
        $base = $c->backoff === 'linear'
            ? $c->initial_delay_ms * ($attempt + 1)
            : $c->initial_delay_ms * (2 ** $attempt);
        $capped = min($base, $c->max_delay_ms);
        $jitter = $capped * 0.25 * (mt_rand() / mt_getrandmax() * 2 - 1);
        return (int) max(0, (int) ($capped + $jitter));
    }

    /**
     * Single-attempt request. `request` wraps this in retry-with-backoff.
     *
     * @param array<string, string> $query
     * @param array<string, string> $extra_headers
     */
    private static function request_once(
        HttpClientConfig $config,
        string $method,
        string $path,
        mixed $body = null,
        array $query = [],
        array $extra_headers = [],
        bool $anonymous = false
    ): Envelope {
        $url = rtrim(Region::resolve($config), '/') . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['accept: application/json'];
        foreach ($config->default_headers as $k => $v) {
            if ($anonymous && in_array(strtolower($k), ['authorization', 'cookie'], true)) {
                continue;
            }
            $headers[] = $k . ': ' . $v;
        }
        if ($config->project_slug !== null) {
            $headers[] = 'x-k9o-project-slug: ' . $config->project_slug;
        }
        if ($config->org_slug !== null) {
            $headers[] = 'x-k9o-org-slug: ' . $config->org_slug;
        }
        if ($config->api_key !== null && !$anonymous) {
            $headers[] = 'authorization: Bearer ' . $config->api_key;
        }
        foreach ($extra_headers as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }

        // A RawBody (multipart upload) carries its own content-type, boundary included; everything else is JSON.
        if ($body instanceof RawBody) {
            $headers[] = 'content-type: ' . $body->content_type;
            $payload = $body->content;
        } else {
            if ($body !== null) {
                $headers[] = 'content-type: application/json';
            }
            $payload = $body !== null ? json_encode($body) : null;
        }
        $res = self::send($config, strtoupper($method), $url, $headers, $payload);
        $response_body = $res['error'] === '' ? $res['body'] : false;
        $curl_error = $res['error'];
        $status = $res['status'];

        if ($response_body === false || $curl_error !== '') {
            throw new KError(
                'K9O_UPSTREAM_UNAVAILABLE',
                sprintf('Network error reaching %s: %s', $url, $curl_error),
                ['url' => $url],
                'Check connectivity to the gateway; retry with backoff if transient.'
            );
        }

        if ($status === 204) {
            return new Envelope(null, new Meta());
        }

        $parsed = json_decode((string) $response_body, true);
        if (!is_array($parsed)) {
            throw new KError(
                'K9O_INTERNAL_ERROR',
                sprintf('Failed to parse JSON response from %s', $url),
                ['url' => $url, 'status' => $status],
                null, $status
            );
        }

        if ($status >= 200 && $status < 300) {
            if (!array_key_exists('data', $parsed) || !array_key_exists('meta', $parsed)) {
                throw new KError(
                    'K9O_INTERNAL_ERROR',
                    sprintf('Malformed envelope from %s: expected {data, meta}', $url),
                    ['url' => $url, 'status' => $status],
                    null, $status
                );
            }
            return Envelope::fromWire($parsed);
        }

        if (isset($parsed['code']) && isset($parsed['message']) && is_string($parsed['code']) && is_string($parsed['message'])) {
            throw KError::fromWire($parsed, $status);
        }
        throw new KError(
            'K9O_INTERNAL_ERROR',
            sprintf('Upstream returned %d with non-KError body', $status),
            ['url' => $url, 'status' => $status],
            null, $status
        );
    }

    /**
     * One HTTP exchange, never following redirects (a credential must not be replayed to a redirect target).
     * Uses `$config->transport` when set, else ext-curl.
     *
     * @param list<string> $header_lines
     * @return array{status:int, body:string, location:?string, error:string}
     */
    public static function send(HttpClientConfig $config, string $method, string $url, array $header_lines, ?string $body): array
    {
        if ($config->transport !== null) {
            return ($config->transport)($method, $url, $header_lines, $body);
        }
        $location = null;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => is_string($out) ? $out : '',
            'location' => $location,
            'error' => ($out === false && $err === '') ? 'request failed' : $err,
        ];
    }

    /**
     * Open an SSE stream and yield decoded `data:` payloads as parsed
     * JSON. Used by Compute LLMs invoke_stream + Realtime
     * subscribe SSE transport. The [DONE] sentinel
     * terminates the generator; non-2xx throws KError before yielding.
     *
     * Per the PHP async-iteration idiom is Generator — caller
     * writes `foreach ($client->compute->llms->invoke_stream(...) as $chunk)`.
     *
     * @param array<string, string> $query
     * @return \Generator<int, mixed>
     */
    public static function stream_sse(
        HttpClientConfig $config,
        string $method,
        string $path,
        mixed $body = null,
        array $query = []
    ): \Generator {
        $url = rtrim(Region::resolve($config), '/') . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $header_lines = ['accept: text/event-stream'];
        foreach ($config->default_headers as $k => $v) {
            $header_lines[] = $k . ': ' . $v;
        }
        if ($config->project_slug !== null) {
            $header_lines[] = 'x-k9o-project-slug: ' . $config->project_slug;
        }
        if ($config->org_slug !== null) {
            $header_lines[] = 'x-k9o-org-slug: ' . $config->org_slug;
        }
        if ($config->api_key !== null) {
            $header_lines[] = 'authorization: Bearer ' . $config->api_key;
        }
        if ($body !== null) {
            $header_lines[] = 'content-type: application/json';
        }

        $context_opts = [
            'http' => [
                'method'  => strtoupper($method),
                'header'  => implode("\r\n", $header_lines),
                'ignore_errors' => true,  // so we can read the body on non-2xx
                'follow_location' => 0,   // never replay a credential to a redirect target
                'max_redirects' => 0,
                'timeout' => 30,
            ],
        ];
        if ($body !== null) {
            $context_opts['http']['content'] = json_encode($body);
        }
        $context = stream_context_create($context_opts);

        if ($config->transport !== null) {
            $res = ($config->transport)(strtoupper($method), $url, $header_lines, $body !== null ? json_encode($body) : null);
            $stream = $res['error'] === '' ? fopen('php://memory', 'w+b') : false;
            if ($stream !== false) {
                fwrite($stream, $res['body']);
                rewind($stream);
                $status = $res['status'];
            }
        } else {
            $stream = @fopen($url, 'rb', false, $context);
        }
        if ($stream === false) {
            $err = error_get_last();
            throw new KError(
                'K9O_UPSTREAM_UNAVAILABLE',
                sprintf('Network error reaching %s: %s', $url, $err['message'] ?? 'unknown'),
                ['url' => $url],
                'Check connectivity to the gateway; retry with backoff if transient.'
            );
        }

        // Parse status from $http_response_header (set by fopen on success).
        $status = $status ?? 0;
        $meta = stream_get_meta_data($stream);
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) && isset($meta['wrapper_data'][0])) {
            $status_line = $meta['wrapper_data'][0];
            if (preg_match('#HTTP/\S+\s+(\d+)#', $status_line, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($status < 200 || $status >= 300) {
            $err_body = stream_get_contents($stream);
            fclose($stream);
            $parsed = json_decode((string) $err_body, true);
            if (is_array($parsed) && isset($parsed['code']) && isset($parsed['message'])
                && is_string($parsed['code']) && is_string($parsed['message'])) {
                throw KError::fromWire($parsed, $status);
            }
            throw new KError(
                'K9O_INTERNAL_ERROR',
                sprintf('Upstream returned %d opening stream', $status),
                ['url' => $url, 'status' => $status],
                null, $status
            );
        }

        try {
            $data_lines = [];
            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line === false) break;
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    if (!empty($data_lines)) {
                        $payload = implode("\n", $data_lines);
                        $data_lines = [];
                        if ($payload === '[DONE]') return;
                        $decoded = json_decode($payload, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new KError(
                                'K9O_INTERNAL_ERROR',
                                'failed to decode stream chunk: ' . json_last_error_msg()
                            );
                        }
                        yield $decoded;
                    }
                    continue;
                }
                if (str_starts_with($line, ':')) continue;
                if (str_starts_with($line, 'data:')) {
                    $rest = substr($line, 5);
                    if (str_starts_with($rest, ' ')) $rest = substr($rest, 1);
                    $data_lines[] = $rest;
                }
            }
            // Flush trailing event without boundary
            if (!empty($data_lines)) {
                $payload = implode("\n", $data_lines);
                if ($payload !== '[DONE]') {
                    $decoded = json_decode($payload, true);
                    if (json_last_error() === JSON_ERROR_NONE) yield $decoded;
                }
            }
        } finally {
            fclose($stream);
        }
    }
}

