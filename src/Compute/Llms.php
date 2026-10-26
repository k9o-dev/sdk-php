<?php

declare(strict_types=1);

namespace K9o\Compute;

use K9o\Envelope;
use K9o\HttpClientConfig;
use K9o\HttpTransport;
use K9o\KError;
use K9o\Studio;
use K9o\UrlGrammar;

/**
 * client->compute->llms->*. list/read/invoke + streaming invoke_stream (a Generator). Error code:
 * K9O_LLM_PROVIDER_UNAVAILABLE when the inference endpoint is unavailable.
 */
final class Llms
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /** Inference parameters the gateway reads (top-level body fields next to `prompt`). */
    private const PARAM_FIELDS = [
        'max_tokens' => 'maxTokens', 'maxTokens' => 'maxTokens',
        'temperature' => 'temperature',
        'usage_type' => 'usageType', 'usageType' => 'usageType',
    ];

    /** GET /@<slug>/compute/llms */
    public function list(): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'compute.llms');
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'compute/llms'));
    }

    public function read(string $model_id): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'compute.llms');
        return HttpTransport::request($this->config, 'GET',
            UrlGrammar::project_path($slug, 'compute/llms/' . rawurlencode($model_id)));
    }

    /**
     * Synchronous inference: POST /@<slug>/compute/llms/<id>/invoke { prompt, maxTokens?, temperature?, usageType? }.
     * `$params` takes max_tokens / temperature / usage_type (the gateway's spellings also work). The envelope's data
     * is `['modelId', 'content', 'finishReason', 'usage' => ['promptTokens', 'completionTokens', 'totalTokens'],
     * 'usageType']`.
     *
     * @param array{max_tokens?: int, temperature?: float|int, usage_type?: string} $params
     */
    public function invoke(string $model_id, string $prompt, array $params = []): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'compute.llms');
        $body = $this->invoke_body($prompt, $params);
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug, 'compute/llms/' . rawurlencode($model_id) . '/invoke'),
            $body);
    }

    /**
     * Streaming inference (SSE per-token). Yields parsed chunk arrays. Consume with
     * `foreach ($llms->invoke_stream(...) as $chunk)`.
     *
     * @param array{max_tokens?: int, temperature?: float|int, usage_type?: string} $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function invoke_stream(string $model_id, string $prompt, array $params = []): \Generator
    {
        $slug = Studio::require_project_slug($this->config, 'compute.llms');
        $body = $this->invoke_body($prompt, $params);
        yield from HttpTransport::stream_sse($this->config, 'POST',
            UrlGrammar::project_path($slug, 'compute/llms/' . rawurlencode($model_id) . '/invoke-stream'),
            $body);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function invoke_body(string $prompt, array $params): array
    {
        $body = ['prompt' => $prompt];
        foreach ($params as $k => $v) {
            if (!is_string($k) || !isset(self::PARAM_FIELDS[$k])) {
                throw new KError('K9O_INVALID_FIELD_VALUE', sprintf('unknown inference parameter "%s"', (string) $k),
                    ['field' => (string) $k, 'reason' => 'unknown_option']);
            }
            if ($v !== null) $body[self::PARAM_FIELDS[$k]] = $v;
        }
        return $body;
    }
}
