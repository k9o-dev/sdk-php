<?php

declare(strict_types=1);

namespace K9o;

/**
 * Policy bundle lifecycle namespace — $client->policies.
 *
 * The policy document is an array of sections keyed by resource kind ('collections', 'storage', 'tools',
 * 'functions', 'secrets', 'streams', 'analytics'; other keys are allowed). Pass an empty section as
 * `new \stdClass()` to send `{}` (an empty PHP array encodes as `[]`). Only authoring roles write policy and only
 * custodian roles publish; `validate` is read-only.
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

    /** GET /@<slug>/policy — the currently active policy (data is null when none is published). */
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
     * POST /@<slug>/policies — create a draft bundle from a policy document.
     *
     * @param array<string, mixed> $policy
     * @param 'visual'|'json'|'unspecified'|null $editor_mode
     */
    public function create(array $policy, mixed $rules = null, ?string $editor_mode = null): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'policies'), self::body($policy, $rules, $editor_mode));
    }

    /**
     * POST /@<slug>/policies/validate — lint a candidate policy document without persisting.
     *
     * @param array<string, mixed> $policy
     */
    public function validate(array $policy, mixed $rules = null): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'policies/validate'), self::body($policy, $rules, null));
    }

    public function get(string $bundle_id): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'policies/' . rawurlencode($bundle_id)));
    }

    /**
     * PATCH /@<slug>/policies/<id> — replace a draft bundle's policy document. Published bundles cannot be patched.
     *
     * @param array<string, mixed> $policy
     * @param 'visual'|'json'|'unspecified'|null $editor_mode
     */
    public function update(string $bundle_id, array $policy, mixed $rules = null, ?string $editor_mode = null): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::project_path($slug, 'policies/' . rawurlencode($bundle_id)), self::body($policy, $rules, $editor_mode));
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private static function body(array $policy, mixed $rules, ?string $editor_mode): array
    {
        $body = ['policy' => $policy === [] ? new \stdClass() : $policy];
        if ($rules !== null) $body['rules'] = $rules;
        if ($editor_mode !== null) $body['editorMode'] = $editor_mode;
        return $body;
    }

    /** Delete a draft bundle. Published bundles cannot be deleted. */
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
