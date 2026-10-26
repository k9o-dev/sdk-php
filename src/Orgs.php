<?php

declare(strict_types=1);

namespace K9o;

/**
 * Org lifecycle namespace (collection-style) — $client->orgs.
 * Distinct from $client->org (singular bound-org namespace).
 */
final class Orgs
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('orgs'));
    }

    public function get(string $org_slug): Envelope
    {
        UrlGrammar::validate_slug($org_slug, 'org');
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('orgs/' . rawurlencode($org_slug)));
    }

    public function restore(string $org_id): Envelope
    {
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('orgs/' . rawurlencode($org_id) . '/restore'));
    }

    public function current(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('org'));
    }
}
