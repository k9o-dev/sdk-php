<?php

declare(strict_types=1);

namespace K9o;

/**
 * Realtime Subscriptions namespace.
 *
 * Transport: SSE over GET /@<slug>/realtime/subscribe. The gateway serves no WebSocket upgrade and no durable
 * replay, so neither is offered; after an `event: resync` frame the caller re-reads current state from the data
 * plane and resubscribes. subscribe() returns a Generator iterated via foreach.
 */
final class Realtime
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * Subscribe via SSE; yields event arrays `['topic', 'eventType', 'payload', 'occurredAt']` (the gateway's
     * spelling). Control frames such as `resync` carry no `eventType` and are skipped.
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
        foreach (HttpTransport::stream_sse($this->config, 'GET',
            UrlGrammar::project_path($slug, 'realtime/subscribe'), null, $q) as $event) {
            if (is_array($event) && isset($event['eventType']) && is_string($event['eventType'])) {
                yield $event;
            }
        }
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
