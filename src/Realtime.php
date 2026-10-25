<?php

declare(strict_types=1);

namespace K9o;

/**
 * Realtime Subscriptions namespace (ALL-LIVE primitive).
 *
 * PHP idiom: subscribe() returns Generator iterated via
 * foreach. Per + the SDK-abstracted-primitive discipline the
 * SDK picks the transport; SPLIT (polling) is the internal fallback.
 * The ext-curl/fopen-based PHP SDK uses SSE primary (no stdlib WebSocket);
 * subscribe_poll() exposes the poll fallback for environments blocking
 * streaming. replay() is the one-shot historical catch-up.
 */
final class Realtime
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /** Subscribe via SSE transport (primary); yields event arrays.
     *
     * @param array<string, mixed>|null $filter
     * @return \Generator<int, array<string, mixed>>
     */
    public function subscribe(string $topic, ?array $filter = null): \Generator
    {
        $slug = Studio::require_project_slug($this->config, 'realtime');
        $q = ['topic' => $topic];
        if ($filter !== null) {
            $q['filter'] = json_encode($filter);
        }
        yield from HttpTransport::stream_sse($this->config, 'GET',
            UrlGrammar::project_path($slug, 'realtime/subscribe'), null, $q);
    }

    /**
     * One-shot historical replay (capped 10k server-side).
     *
     * @param array<string, mixed>|null $filter
     */
    public function replay(string $topic, int $from_sequence, ?array $filter = null, ?int $until_sequence = null, ?int $limit = null): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'realtime');
        if ($from_sequence < 0) {
            throw new KError(
                'K9O_INVALID_FILTER',
                'replay from_sequence must be a non-negative integer',
                ['fromSequence' => $from_sequence],
                'Pass the highest sequence number you have already observed (or 0 to replay from the start).'
            );
        }
        $body = ['topic' => $topic, 'fromSequence' => $from_sequence];
        if ($filter !== null) $body['filter'] = $filter;
        if ($until_sequence !== null) $body['untilSequence'] = $until_sequence;
        if ($limit !== null) $body['limit'] = $limit;
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug, 'realtime/replay'), $body);
    }

    /**
     * Poll-transport fallback (SDK-internal SPLIT). Single batch
     * via replay endpoint; caller loops tracking highest observed sequence.
     *
     * @param array<string, mixed>|null $filter
     */
    public function subscribe_poll(string $topic, int $from_sequence, ?array $filter = null): Envelope
    {
        return $this->replay($topic, $from_sequence, $filter);
    }
}

/** Typed builders for the 6 closed topic families. */
final class RealtimeTopic
{
    private function __construct() {}

    public static function collection(string $collection): string { return 'collections.' . $collection . '.*'; }
    public static function workflow_runs(): string { return 'studio.workflows.runs.*'; }
    public static function query_executed(string $query_id): string { return 'studio.queries.executed.' . $query_id; }
    public static function dashboard_refresh(string $dashboard_id): string { return 'studio.dashboards.refresh.' . $dashboard_id; }
    public static function function_invocations(string $function_id): string { return 'forge.functions.invocations.' . $function_id; }
    public static function audit_org(string $event_family): string { return 'audit.org.' . $event_family; }
}
