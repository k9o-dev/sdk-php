<?php

declare(strict_types=1);

namespace K9o;

/**
 * User data streams namespace — $client->streams.
 *
 * Routes under /@<slug>/data-streams/user. Publish needs project admin; subscribe and replay need admin or auditor.
 * While the stream data plane is unavailable the gateway answers K9O_STREAM_UNAVAILABLE (503); a bad subject answers
 * K9O_STREAM_INVALID. Both throw KError.
 */
final class Streams
{
    /** Milliseconds to wait before reconnecting after the server closes a subscribe connection. */
    public static int $reconnect_delay_ms = 250;

    public function __construct(private readonly HttpClientConfig $config) {}

    private function path(?string $stream_slug = null, ?string $suffix = null): string
    {
        if ($this->config->project_slug === null || $this->config->project_slug === '') {
            throw new KError('K9O_MISSING_PROJECT_CONTEXT', 'streams operations require project_slug bound on the client');
        }
        $sub = 'data-streams/user';
        if ($stream_slug !== null) {
            $sub .= '/' . rawurlencode($stream_slug);
        }
        if ($suffix !== null) {
            $sub .= '/' . $suffix;
        }
        return UrlGrammar::project_path($this->config->project_slug, $sub);
    }

    /** POST /@<slug>/data-streams/user — `$max_bytes` is the storage ceiling; the slug is derived from `$display_name`. */
    public function create(string $display_name, int $max_bytes, ?int $retention_seconds = null): Envelope
    {
        $body = ['displayName' => $display_name, 'maxBytes' => $max_bytes];
        if ($retention_seconds !== null) {
            $body['retentionSeconds'] = $retention_seconds;
        }
        return HttpTransport::request($this->config, 'POST', $this->path(), $body);
    }

    /** GET /@<slug>/data-streams/user — the project's active user streams. */
    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', $this->path());
    }

    /** GET /@<slug>/data-streams/user/<slug> */
    public function get(string $slug): Envelope
    {
        return HttpTransport::request($this->config, 'GET', $this->path($slug));
    }

    /** DELETE /@<slug>/data-streams/user/<slug> — retire the stream. */
    public function delete(string $slug): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', $this->path($slug));
    }

    /** POST .../<slug>/publish — `$data` is any JSON value; `$subject` is an optional dot-separated routing suffix. */
    public function publish(string $slug, mixed $data, ?string $subject = null): Envelope
    {
        $body = ['data' => $data];
        if ($subject !== null) {
            $body['subject'] = $subject;
        }
        return HttpTransport::request($this->config, 'POST', $this->path($slug, 'publish'), $body);
    }

    /** POST .../<slug>/replay — a bounded range of past events: data.events, data.nextCursor, data.hasMore. */
    public function replay(string $slug, int|string|null $from_sequence = null, int|string|null $until_sequence = null, ?int $limit = null): Envelope
    {
        $body = [];
        if ($from_sequence !== null) {
            $body['fromSequence'] = $from_sequence;
        }
        if ($until_sequence !== null) {
            $body['untilSequence'] = $until_sequence;
        }
        if ($limit !== null) {
            $body['limit'] = $limit;
        }
        return HttpTransport::request($this->config, 'POST', $this->path($slug, 'replay'), $body === [] ? new \stdClass() : $body);
    }

    /**
     * GET .../<slug>/subscribe — tails live events `['sequence', 'subject', 'data', 'publishedAt']`. The gateway
     * bounds each connection and closes it; the generator reconnects from the last sequence it yielded. Stop by
     * leaving the foreach.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function subscribe(string $slug, int|string|null $from_sequence = null): \Generator
    {
        $path = $this->path($slug, 'subscribe');
        $cursor = $from_sequence;
        while (true) {
            $query = $cursor === null ? [] : ['fromSequence' => (string) $cursor];
            foreach (HttpTransport::stream_sse($this->config, 'GET', $path, null, $query) as $event) {
                if (is_array($event) && isset($event['sequence'])) {
                    $cursor = $event['sequence'];
                    yield $event;
                }
            }
            if (self::$reconnect_delay_ms > 0) {
                usleep(self::$reconnect_delay_ms * 1000);
            }
        }
    }
}
