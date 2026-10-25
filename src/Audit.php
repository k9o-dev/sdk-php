<?php

declare(strict_types=1);

namespace K9o;

/**
 * Project-plane audit log namespace — $client->audit.
 * Distinct from $client->org->audit_log + $client->me->audit_log.
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

    /**
     * Query the audit log (reverse-chronological; filters AND-compose).
     *
     * @param array<string, scalar> $filters keys: since/until/type/actorId/targetKind/targetId/limit/cursor
     */
    public function query(array $filters = []): Envelope
    {
        $slug = $this->require_project_slug();
        $q = [];
        foreach ($filters as $k => $v) {
            if ($v !== null) $q[$k] = (string) $v;
        }
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'audit-log'), null, $q);
    }
}
