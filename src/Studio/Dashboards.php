<?php

declare(strict_types=1);

namespace K9o\Studio;

use K9o\Envelope;
use K9o\HttpClientConfig;
use K9o\HttpTransport;
use K9o\Studio;
use K9o\UrlGrammar;

/**
 * client->studio->dashboards->*. CRUD + pin/unpin. Surfaces 2 K9O_DASHBOARD_* codes via existing
 * KError wire mapping.
 */
final class Dashboards
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * @param array<string, string> $filters
     */
    public function list(array $filters = []): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.dashboards');
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'dashboards'), null, $filters);
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
