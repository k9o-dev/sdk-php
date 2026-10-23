<?php

declare(strict_types=1);

namespace K9o;

/**
 * Policy bundle lifecycle namespace — $client->policies.
 */
final class Policies
{
    public function __construct(private readonly HttpClientConfig $config) {}

    private function require_project_slug(): string
    {
        if ($this->config->project_slug === null || $this->config->project_slug === '') {
            throw new KError('K9O_MISSING_PROJECT_CONTEXT', 'policies operations require project_slug bound on the client');
        }
        return $this->config->project_slug;
    }

    public function current(): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'policy'));
    }

    public function list(?string $status = null): Envelope
    {
        $slug = $this->require_project_slug();
        $q = $status !== null ? ['status' => $status] : [];
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'policies'), null, $q);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function create(string $name, array $rules): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'policies'), ['name' => $name, 'rules' => $rules]);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function validate(array $rules): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'policies/validate'), ['rules' => $rules]);
    }

    public function get(string $bundle_id): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'policies/' . rawurlencode($bundle_id)));
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function update(string $bundle_id, array $patch): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::project_path($slug, 'policies/' . rawurlencode($bundle_id)), $patch);
    }

    public function delete(string $bundle_id): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::project_path($slug, 'policies/' . rawurlencode($bundle_id)));
    }

    public function publish(string $bundle_id): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'policies/' . rawurlencode($bundle_id) . '/publish'));
    }
}
