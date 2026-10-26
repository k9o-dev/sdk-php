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
 * client->studio->workflows->*. CRUD + manual trigger + runs sub-namespace. Surfaces K9O_WORKFLOW_* error codes via
 * the KError wire mapping (open code-space; narrow on $e->code).
 */
final class Workflows
{
    public readonly WorkflowRuns $runs;

    public function __construct(private readonly HttpClientConfig $config)
    {
        $this->runs = new WorkflowRuns($config);
    }

    /**
     * GET /@<slug>/workflows — the route filters by `status` and pages with `limit` / `cursor`.
     *
     * @param array{status?: string, limit?: int, cursor?: string} $filters
     */
    public function list(array $filters = []): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.workflows');
        $q = [];
        foreach ($filters as $k => $v) {
            if (!in_array($k, ['status', 'limit', 'cursor'], true)) {
                throw new KError('K9O_INVALID_FIELD_VALUE', sprintf('unknown workflows filter "%s"', (string) $k),
                    ['field' => (string) $k, 'reason' => 'unknown_option']);
            }
            if ($v !== null) $q[$k] = (string) $v;
        }
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'workflows'), null, $q);
    }

    /**
     * POST /@<slug>/workflows. `$workflow` is the gateway's body: 'name', 'triggerType', 'triggerConfig',
     * 'actions' (each `['index', 'action_type', 'config', ...]`), optional 'description', 'status', 'rbacBinding'.
     *
     * @param array<string, mixed> $workflow
     */
    public function create(array $workflow): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.workflows');
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug, 'workflows'), $workflow);
    }

    public function read(string $workflow_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.workflows');
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'workflows/' . rawurlencode($workflow_id)));
    }

    /**
     * @param array<string, mixed> $updates
     */
    public function update(string $workflow_id, array $updates): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.workflows');
        return HttpTransport::request($this->config, 'PATCH',
            UrlGrammar::project_path($slug, 'workflows/' . rawurlencode($workflow_id)), $updates);
    }

    public function delete(string $workflow_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.workflows');
        return HttpTransport::request($this->config, 'DELETE',
            UrlGrammar::project_path($slug, 'workflows/' . rawurlencode($workflow_id)));
    }

    /**
     * Manual-trigger a run (manual workflows only): POST /@<slug>/workflows/<id>/runs { triggerContext }.
     *
     * @param array<string, mixed> $trigger_context
     */
    public function trigger(string $workflow_id, array $trigger_context = []): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'studio.workflows');
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug, 'workflows/' . rawurlencode($workflow_id) . '/runs'),
            ['triggerContext' => $trigger_context === [] ? new \stdClass() : $trigger_context]);
    }
}
