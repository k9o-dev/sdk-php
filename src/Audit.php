<?php

declare(strict_types=1);

namespace K9o;

/**
 * Project-plane audit log namespace — $client->audit.
 * Distinct from $client->org->audit_log.
 */
final class Audit
{
    public function __construct(private readonly HttpClientConfig $config) {}

    private function require_project_slug(): string
    {
        if ($this->config->project_slug === null || $this->config->project_slug === '') {
            throw new KError('K9O_MISSING_PROJECT_CONTEXT', 'audit operations require project_slug bound on the client');
        }
        return $this->config->project_slug;
    }

    private const FILTERS = [
        'event_type' => 'eventType', 'actor_id' => 'actorId', 'after' => 'after', 'before' => 'before',
        'limit' => 'limit', 'cursor' => 'cursor',
    ];

    /**
     * GET /@<slug>/audit-log — reverse-chronological; filters AND-compose. Requires the AUDIT_READER role.
     *
     * Keys: event_type (audit-event name prefix, e.g. 'data.write.access'), actor_id, after / before (ISO 8601
     * bounds: events after / before this instant), limit (page size, bounded server-side), cursor (from the prior
     * response's meta.cursor.next).
     *
     * @param array{event_type?: string, actor_id?: string, after?: string, before?: string, limit?: int, cursor?: string} $filters
     */
    public function query(array $filters = []): Envelope
    {
        $slug = $this->require_project_slug();
        $q = [];
        foreach ($filters as $k => $v) {
            if (!is_string($k) || !isset(self::FILTERS[$k])) {
                throw new KError('K9O_INVALID_FIELD_VALUE', sprintf('unknown audit filter "%s"', (string) $k), ['field' => (string) $k, 'reason' => 'unknown_option']);
            }
            if ($v !== null) $q[self::FILTERS[$k]] = (string) $v;
        }
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'audit-log'), null, $q);
    }
}
