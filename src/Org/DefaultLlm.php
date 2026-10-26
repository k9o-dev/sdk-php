<?php

declare(strict_types=1);

namespace K9o\Org;

use K9o\Envelope;
use K9o\HttpClientConfig;
use K9o\HttpTransport;
use K9o\UrlGrammar;

/** client->org->default_llm (ORG_ADMIN; tier-gated). */
final class DefaultLlm
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /** GET /default-llm — the org-default LLM. */
    public function get(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('default-llm'));
    }

    /** PATCH /default-llm { modelId } (ORG_ADMIN; tier-gated). */
    public function set(string $model_id): Envelope
    {
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('default-llm'),
            ['modelId' => $model_id]);
    }
}
