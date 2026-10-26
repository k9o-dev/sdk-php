<?php

declare(strict_types=1);

namespace K9o\Studio;

use K9o\Envelope;
use K9o\HttpClientConfig;
use K9o\HttpTransport;
use K9o\KError;
use K9o\Studio;
use K9o\UrlGrammar;

/**
 * client->studio->dashboards->*. CRUD + pin/unpin. Surfaces K9O_DASHBOARD_* codes via the KError wire mapping.
 * Widget fields on the wire are snake_case ('widget_type', 'data_source', 'config').
 */
final class Dashboards
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * GET /@<slug>/dashboards — filters: `status`, `pinned_only` (bool); pages with `limit` / `cursor`.
     *
     * @param array{status?: string, pinned_only?: bool, limit?: int, cursor?: string} $filters
     */
    public function list(array $filters = []): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.dashboards');
        $q = [];
        foreach ($filters as $k => $v) {
            if (!in_array($k, ['status', 'pinned_only', 'limit', 'cursor'], true)) {
                throw new KError('K9O_INVALID_FIELD_VALUE', sprintf('unknown dashboards filter "%s"', (string) $k),
                    ['field' => (string) $k, 'reason' => 'unknown_option']);
            }
            if ($v === null) continue;
            if ($k === 'pinned_only') $q['pinnedOnly'] = $v ? 'true' : 'false';
            else $q[$k] = (string) $v;
        }
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'dashboards'), null, $q);
    }

    /**
     * @param array<string, mixed> $dashboard
     */
    public function create(array $dashboard): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.dashboards');
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug, 'dashboards'), $dashboard);
    }

    public function read(string $dashboard_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.dashboards');
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'dashboards/' . rawurlencode($dashboard_id)));
    }

    /**
     * @param array<string, mixed> $updates
     */
    public function update(string $dashboard_id, array $updates): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.dashboards');
        return HttpTransport::request($this->config, 'PATCH',
            UrlGrammar::project_path($slug, 'dashboards/' . rawurlencode($dashboard_id)), $updates);
    }

    public function delete(string $dashboard_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.dashboards');
        return HttpTransport::request($this->config, 'DELETE',
            UrlGrammar::project_path($slug, 'dashboards/' . rawurlencode($dashboard_id)));
    }

    public function pin(string $dashboard_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.dashboards');
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug, 'dashboards/' . rawurlencode($dashboard_id) . '/pin'));
    }

    public function unpin(string $dashboard_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.dashboards');
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug, 'dashboards/' . rawurlencode($dashboard_id) . '/unpin'));
    }
}
