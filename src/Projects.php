<?php

declare(strict_types=1);

namespace K9o;

/**
 * Project lifecycle namespace.
 *
 * All four operations are org-plane: the client needs an org context (an `org_slug` or a bearer token that carries
 * one). Option keys are snake_case spellings of the gateway's body property names.
 */
final class Projects
{
    private const CREATE_FIELDS = [
        'slug' => 'slug', 'product_scope' => 'productScope', 'region' => 'region',
        'default_project_region' => 'defaultProjectRegion', 'custody_mode' => 'custodyMode',
        'processing_contexts' => 'processingContexts', 'exec_seam_confined' => 'execSeamConfined',
        'showroom_vertical' => 'showroomVertical', 'persona' => 'persona', 'showroom_locale' => 'showroomLocale',
        'idempotency_key' => 'idempotencyKey',
    ];

    private const UPDATE_FIELDS = [
        'slug' => 'slug', 'display_name' => 'displayName', 'description' => 'description', 'region' => 'region',
        'product_scope' => 'productScope', 'soft_delete_retention_days' => 'softDeleteRetentionDays',
        'processing_contexts' => 'processingContexts',
    ];

    public function __construct(private readonly HttpClientConfig $config) {}

    /** GET /projects */
    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('projects'));
    }

    /**
     * POST /projects. `$display_name` is required (1 to 128 characters); the gateway derives a slug from it when
     * `slug` is omitted. `custody_mode` is one of PLATFORM_MANAGED, SKR, BYOK, HYOK. `idempotency_key` makes a
     * retried create return the first result instead of a second project.
     *
     * @param array{slug?: string, product_scope?: string, region?: string, default_project_region?: string,
     *     custody_mode?: string, processing_contexts?: list<string>, exec_seam_confined?: bool,
     *     showroom_vertical?: string, persona?: string, showroom_locale?: array<string, mixed>,
     *     idempotency_key?: string} $opts
     * @throws KError K9O_INVALID_FIELD_VALUE on an unknown option or a malformed slug
     */
    public function create(string $display_name, array $opts = []): Envelope
    {
        $body = ['displayName' => $display_name] + self::wire($opts, self::CREATE_FIELDS, 'projects.create', false);
        if (isset($opts['slug'])) {
            UrlGrammar::validate_slug((string) $opts['slug'], 'project');
        }
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('projects'), $body);
    }

    /** GET /projects/<slug> */
    public function get(string $slug): Envelope
    {
        UrlGrammar::validate_slug($slug, 'project');
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('projects/' . $slug));
    }

    /**
     * PATCH /projects/<slug>. Pass only the fields to change; an explicit null is sent as null (for `description`
     * that clears it).
     *
     * @param array{slug?: string, display_name?: string, description?: ?string, region?: string,
     *     product_scope?: string, soft_delete_retention_days?: int, processing_contexts?: list<string>} $fields
     */
    public function update(string $slug, array $fields): Envelope
    {
        UrlGrammar::validate_slug($slug, 'project');
        $patch = self::wire($fields, self::UPDATE_FIELDS, 'projects.update', true);
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('projects/' . $slug), $patch === [] ? new \stdClass() : $patch);
    }

    /**
     * @param array<string, mixed> $opts
     * @param array<string, string> $map
     * @return array<string, mixed>
     */
    private static function wire(array $opts, array $map, string $surface, bool $keep_null): array
    {
        $out = [];
        foreach ($opts as $k => $v) {
            if (!is_string($k) || !isset($map[$k])) {
                throw new KError('K9O_INVALID_FIELD_VALUE', sprintf('unknown %s option "%s"', $surface, (string) $k),
                    ['field' => (string) $k, 'reason' => 'unknown_option']);
            }
            if ($v !== null || $keep_null) {
                $out[$map[$k]] = $v;
            }
        }
        return $out;
    }
}
