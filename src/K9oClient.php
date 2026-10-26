<?php

declare(strict_types=1);

namespace K9o;

use K9o\Data\FromBuilder;

/**
 * k9o — Kilimanjaro platform SDK for PHP.
 *
 * Idiomatic PHP: snake_case methods + throw K9o\KError.
 *
 * Canonical usage:
 *
 * <code>
 * use K9o\K9oClient;
 * use K9o\KError;
 *
 * $k9o = K9oClient::create([
 *     'base_url'     => 'https://acme.api.k9o.io',
 *     'api_key'      => getenv('K9O_API_KEY'),
 *     'org_slug'     => 'acme',
 *     'project_slug' => 'member-portal',
 * ]);
 *
 * try {
 *     $response = $k9o->from('posts')->select()->limit(10)->execute();
 *     foreach ($response->data as $row) {
 *         echo json_encode($row), PHP_EOL;
 *     }
 *
 *     $session = $k9o->gate->sign_in(email: 'u@x.com', password: '…');
 * } catch (KError $e) {
 *     fwrite(STDERR, "[{$e->code}] {$e->getMessage()}: {$e->recommendation}\n");
 * }
 * </code>
 */
final class K9oClient
{
    public readonly Projects $projects;
    public readonly Gate $gate;
    public readonly Studio $studio;
    public readonly Compute $compute;
    public readonly Ai $ai;
    public readonly Realtime $realtime;
    public readonly Org $org;
    public readonly Orgs $orgs;
    public readonly Migrations $migrations;
    public readonly Functions $functions;
    public readonly Policies $policies;
    public readonly Audit $audit;
    public readonly Me $me;
    public readonly Vault $vault;
    public readonly Storage $storage;

    private function __construct(public readonly HttpClientConfig $config)
    {
        $this->projects = new Projects($config);
        $this->gate = new Gate($config);
        $this->studio = new Studio($config);
        $this->compute = new Compute($config);
        $this->ai = new Ai($config);
        $this->realtime = new Realtime($config);
        $this->org = new Org($config);
        $this->orgs = new Orgs($config);
        $this->migrations = new Migrations($config);
        $this->functions = new Functions($config);
        $this->policies = new Policies($config);
        $this->audit = new Audit($config);
        $this->me = new Me($config);
        $this->vault = new Vault($config);
        $this->storage = new Storage($config);
    }

    /**
     * Factory — constructs a K9oClient bound to the supplied opts.
     *
     * `base_url` is OPTIONAL. When omitted it defaults to the public-cloud platform host (a convenience only, never
     * used to parse a host). When the host is a platform host (`api.<apex>` or `api-staging.<apex>`, any apex) and an
     * `org_slug` is set, the org host `<org_slug>.<host>` is derived. Any other host passes through unchanged.
     *
     * @param array{
     *     base_url?: ?string,
     *     api_key?: ?string,
     *     org_slug?: ?string,
     *     project_slug?: ?string,
     *     default_headers?: array<string, string>
     * } $opts
     * @throws KError K9O_INVALID_BASE_URL on path/scheme/query/fragment violation
     */
    public static function create(array $opts): self
    {
        if (isset($opts['base_url']) && !is_string($opts['base_url'])) {
            throw new KError(
                'K9O_INVALID_FIELD_VALUE',
                'K9oClient::create "base_url" must be a string when provided'
            );
        }
        $org_slug = $opts['org_slug'] ?? null;
        $resolved = UrlGrammar::resolve_base_url($opts['base_url'] ?? null, $org_slug);
        $config = new HttpClientConfig(
            base_url: $resolved,
            api_key: $opts['api_key'] ?? null,
            org_slug: $org_slug,
            project_slug: $opts['project_slug'] ?? null,
            default_headers: $opts['default_headers'] ?? []
        );
        return new self($config);
    }

    public function from(string $collection): FromBuilder
    {
        return new FromBuilder($this->config, $collection);
    }

    public function base_url(): string
    {
        return $this->config->base_url;
    }
}
