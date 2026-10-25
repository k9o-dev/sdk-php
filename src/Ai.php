<?php

declare(strict_types=1);

namespace K9o;

/**
 * client->ai->* — platform AI primitives
 * (`ai.embed` is a platform primitive, not sugar over `context.embed`).
 *
 * Wire mapping (project-plane):
 *   - embed($input, $model = null) -> POST /@<slug>/ai/embeddings
 *       body { model?, input: string | string[] }
 *       -> { data: { embeddings: float[][], model, dimensions }, meta }
 *
 * Callers are an extension (authority is the `(ai, embed, "*")` manifest
 * grant) or a project member's own backend; the gateway refuses an end
 * user's client. Bounds (enforced by the gateway, not here): at most 64
 * inputs, each non-empty, at most 256 KiB of input per request, at most 4
 * requests in flight per project. The platform adds nothing to the input:
 * callers add the model's task prefixes themselves. The SDK sends the input
 * exactly as given and never puts it in an error it constructs.
 */
final class Ai
{
    /** The one declared embedding model today; another `model` is refused with K9O_MODEL_NOT_DECLARED. */
    public const EMBEDDING_MODEL_ID = 'nomic-embed-text-v1.5';

    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * Embed one or more strings. The result envelope's `data` is
     * `['embeddings' => float[][], 'model' => string, 'dimensions' => int]`,
     * one vector per input string, in input order.
     *
     * @param string|list<string> $input one string, or a list of strings (sent as given)
     * @param ?string $model optional; omitted from the body when null (server default)
     * @throws KError K9O_MISSING_PROJECT_CONTEXT when no project_slug is bound
     */
    public function embed(string|array $input, ?string $model = null): Envelope
    {
        $slug = Studio::require_project_slug($this->config, 'ai');
        $body = [];
        if ($model !== null) $body['model'] = $model;
        $body['input'] = is_string($input) ? $input : array_values($input);
        return HttpTransport::request($this->config, 'POST',
            UrlGrammar::project_path($slug, 'ai/embeddings'), $body);
    }
}
