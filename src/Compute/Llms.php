<?php

declare(strict_types=1);

namespace K9o\Compute;

use K9o\Envelope;
use K9o\HttpClientConfig;
use K9o\HttpTransport;
use K9o\Studio;
use K9o\UrlGrammar;

/**
 * client->compute->llms->*. list/read/invoke + streaming
 * invoke_stream (Generator). Coined error code:
 * K9O_LLM_PROVIDER_UNAVAILABLE; others reused.
 */
final class Llms
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(?string $modality = null): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'compute.llms');
        $q = $modality !== null ? ['modality' => $modality] : [];
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'compute/llms'), null, $q);
    }

    public function read(string $model_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'compute.llms');
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'compute/llms/' . rawurlencode($model_id)));
    }

    /**
     * Synchronous inference (PROJECT_AUTHOR/ADMIN).
     *
     * @param array<string, mixed> $params
     */
    public function invoke(string $model_id, string $prompt, array $params = []): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'compute.llms');
        $body = ['prompt' => $prompt];
        if (!empty($params)) $body['params'] = $params;
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug, 'compute/llms/' . rawurlencode($model_id) . '/invoke'),
            $body);
    }

    /**
     * Streaming inference (SSE per-token). Yields parsed
     * chunk arrays. Consume with `foreach ($llms->invoke_stream(...) as $chunk)`.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function invoke_stream(string $model_id, string $prompt, array $params = []): \Generator
    {
        $slug = Studio::require_project_slug($this->config, 'compute.llms');
        $body = ['prompt' => $prompt];
        if (!empty($params)) $body['params'] = $params;
        yield from HttpTransport::stream_sse($this->config, 'POST',
            UrlGrammar::project_path($slug, 'compute/llms/' . rawurlencode($model_id) . '/invoke-stream'),
            $body);
    }
}
