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

    /**
     * Read the org-default LLM model id (null when unset → gateway
     * defaults to mistral-7b).
     */
    public function get(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('org/default-llm'));
    }

    /** Set the org-default LLM (ORG_ADMIN; tier-gated). */
    public function set(string $model_id): Envelope
    {
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('org/default-llm'),
            ['modelId' => $model_id]);
    }
}
