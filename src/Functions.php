<?php

declare(strict_types=1);

namespace K9o;

/**
 * Function lifecycle namespace — $client->functions.
 */
final class Functions
{
    public function __construct(private readonly HttpClientConfig $config) {}

    private function require_project_slug(): string
    {
        if ($this->config->project_slug === null || $this->config->project_slug === '') {
            throw new KError('K9O_MISSING_PROJECT_CONTEXT', 'functions operations require project_slug bound on the client');
        }
        return $this->config->project_slug;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function deploy(string $name, string $runtime, string $code, ?string $tier = null, array $config = []): Envelope
    {
        $slug = $this->require_project_slug();
        $body = ['name' => $name, 'runtime' => $runtime, 'code' => $code];
        if ($tier !== null) $body['tier'] = $tier;
        if (!empty($config)) $body['config'] = $config;
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'functions'), $body);
    }

    public function list(?string $status = null): Envelope
    {
        $slug = $this->require_project_slug();
        $q = $status !== null ? ['status' => $status] : [];
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'functions'), null, $q);
    }

    public function get(string $name): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'functions/' . rawurlencode($name)));
    }

    public function delete(string $name): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::project_path($slug, 'functions/' . rawurlencode($name)));
    }

    /** Invoke a function; payload is passed to the runtime verbatim. */
    public function invoke(string $name, mixed $payload): Envelope
    {
        $slug = $this->require_project_slug();
        return HttpTransport::request($this->config, 'POST', UrlGrammar::project_path($slug, 'functions/' . rawurlencode($name) . '/invoke'), $payload);
    }
}
