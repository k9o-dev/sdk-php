<?php

declare(strict_types=1);

namespace K9o;

/**
 * Compute namespace (Compute LLMs registry). Composition: $client->compute->llms.
 *
 * Curated 9-model LLM enum + invoke (sync) + invoke_stream (Generator
 * PHP idiom).
 */
final class Compute
{
    /** @var list<string> 9-model closed enum (extensible v2.x). */
    public const LLM_MODEL_IDS = [
        'mistral-7b', 'mistral-8x7b', 'llama-3-8b', 'llama-3-70b',
        'claude-3-5-sonnet', 'gpt-4o', 'gpt-4o-mini',
        'text-embedding-3-small', 'text-embedding-3-large',
    ];

    public readonly Compute\Llms $llms;

    public function __construct(HttpClientConfig $config)
    {
        $this->llms = new Compute\Llms($config);
    }
}
