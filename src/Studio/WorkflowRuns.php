<?php

declare(strict_types=1);

namespace K9o\Studio;

use K9o\Envelope;
use K9o\HttpClientConfig;
use K9o\HttpTransport;
use K9o\Studio;
use K9o\UrlGrammar;

/** client->studio->workflows->runs->*. */
final class WorkflowRuns
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(string $workflow_id, ?string $status = null): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.workflows');
        $q = $status !== null ? ['status' => $status] : [];
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'workflows/' . rawurlencode($workflow_id) . '/runs'),
            null, $q);
    }

    public function read(string $workflow_id, string $run_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.workflows');
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug,
                'workflows/' . rawurlencode($workflow_id) . '/runs/' . rawurlencode($run_id)));
    }

    public function cancel(string $workflow_id, string $run_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.workflows');
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug,
                'workflows/' . rawurlencode($workflow_id) . '/runs/' . rawurlencode($run_id) . '/cancel'));
    }
}
