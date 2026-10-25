<?php

declare(strict_types=1);

namespace K9o;

/**
 * Project lifecycle namespace.
 */
final class Projects
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('projects'));
    }

    public function create(string $slug, string $name, ?string $custody_mode = null): Envelope
    {
        UrlGrammar::validate_slug($slug, 'project');
        $body = ['slug' => $slug, 'name' => $name];
        if ($custody_mode !== null) {
            $body['custodyMode'] = $custody_mode;
        }
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('projects'), $body);
    }

    public function get(string $slug): Envelope
    {
        UrlGrammar::validate_slug($slug, 'project');
        return HttpTransport::request($this->config, 'GET', UrlGrammar::project_path($slug, 'project'));
    }

    public function update(string $slug, ?string $name = null): Envelope
    {
        UrlGrammar::validate_slug($slug, 'project');
        $patch = [];
        if ($name !== null) $patch['name'] = $name;
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::project_path($slug, 'project'), $patch);
    }
}
