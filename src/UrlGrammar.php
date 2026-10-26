<?php

declare(strict_types=1);

namespace K9o;

/**
 * URL grammar helpers.
 */
final class UrlGrammar
{
    private const SLUG_RE = '/^[a-z][a-z0-9-]{1,62}$/';

    /** The convenience default when the caller passes no base_url. A default value only: never used to parse a host. */
    public const DEFAULT_BASE_URL = 'https://api.k9o.io';

    /** `api` or `api-staging`, optionally followed by `-<rgn>`: the label that marks a gateway host. */
    private const API_LABEL = '/^(api|api-staging)(?:-([a-z]{2}[0-9]{2}))?$/D';

    private function __construct() {}

    /**
     * Validate + normalise a base_url (scheme + host + optional port; no path, query or fragment).
     *
     * @throws KError K9O_INVALID_BASE_URL on violation
     */
    public static function validate_base_url(string $base_url): string
    {
        $parsed = parse_url($base_url);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw new KError(
                'K9O_INVALID_BASE_URL',
                sprintf('base_url "%s" is not a valid URL', $base_url),
                ['base_url' => $base_url],
                'Pass scheme + host like "https://api.k9o.io".'
            );
        }
        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            throw new KError(
                'K9O_INVALID_BASE_URL',
                sprintf('base_url "%s" uses unsupported scheme "%s"; expected http or https', $base_url, $parsed['scheme']),
                ['base_url' => $base_url, 'scheme' => $parsed['scheme']]
            );
        }
        if (isset($parsed['path']) && $parsed['path'] !== '' && $parsed['path'] !== '/') {
            throw new KError(
                'K9O_INVALID_BASE_URL',
                sprintf('base_url "%s" must not contain a path segment; got "%s"', $base_url, $parsed['path']),
                ['base_url' => $base_url, 'path' => $parsed['path']]
            );
        }
        if (isset($parsed['query']) || isset($parsed['fragment'])) {
            throw new KError(
                'K9O_INVALID_BASE_URL',
                sprintf('base_url "%s" must not contain query or fragment', $base_url),
                ['base_url' => $base_url]
            );
        }
        $host_port = isset($parsed['port']) ? $parsed['host'] . ':' . $parsed['port'] : $parsed['host'];
        return $parsed['scheme'] . '://' . $host_port;
    }

    /**
     * Read the org slug and region out of a gateway host without knowing its apex. The gateway label is `api` or
     * `api-staging`, optionally followed by `-<rgn>`; the label before it is the org slug and whatever follows it is
     * the apex. Returns null for any other shape (localhost, an IP, a single-label host, a host with no gateway
     * label), which callers use exactly as given.
     *
     * @return array{org_slug: ?string, family: string, region: ?string, apex: string}|null
     */
    public static function parse_api_host(string $host): ?array
    {
        $labels = explode('.', strtolower($host));
        foreach ($labels as $l) {
            if ($l === '') {
                return null;
            }
        }
        $build = static function (string $gateway_label, ?string $org_slug, array $rest): ?array {
            if (preg_match(self::API_LABEL, $gateway_label, $m) !== 1 || $rest === []) {
                return null;
            }
            return ['org_slug' => $org_slug, 'family' => $m[1], 'region' => ($m[2] ?? '') === '' ? null : $m[2], 'apex' => implode('.', $rest)];
        };
        $n = count($labels);
        // Org host first: <org>.<api[-rgn]>.<apex>.
        if ($n >= 3 && preg_match(self::SLUG_RE, $labels[0]) === 1 && !str_ends_with($labels[0], '-')
            && preg_match(self::API_LABEL, $labels[1]) === 1) {
            return $build($labels[1], $labels[0], array_slice($labels, 2));
        }
        // Platform host: api.<apex>.
        if ($n >= 2 && preg_match(self::API_LABEL, $labels[0]) === 1) {
            return $build($labels[0], null, array_slice($labels, 1));
        }
        return null;
    }

    /**
     * Resolve the effective base_url. base_url is OPTIONAL: omitted, it defaults to DEFAULT_BASE_URL (the public
     * cloud, a convenience only). When the (validated) host is a platform host (`api.<apex>` or
     * `api-staging.<apex>`, any apex) and an org_slug is supplied, the canonical org host `<org_slug>.<host>` is
     * composed. Any other host (an org host already, an internal or air-gapped host, localhost) passes through
     * unchanged; org_slug is then only sent as a header.
     *
     * @throws KError K9O_INVALID_BASE_URL on path/scheme/query/fragment violation
     * @throws KError K9O_INVALID_FIELD_VALUE on malformed org_slug during derivation
     */
    public static function resolve_base_url(?string $base_url, ?string $org_slug = null): string
    {
        $validated = self::validate_base_url($base_url ?? self::DEFAULT_BASE_URL);
        $parsed = parse_url($validated);
        $host = isset($parsed['host']) ? self::parse_api_host($parsed['host']) : null;
        if ($host !== null && $host['org_slug'] === null && $host['region'] === null && $org_slug !== null && $org_slug !== '') {
            self::validate_slug($org_slug, 'org');
            $host_port = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            return $parsed['scheme'] . '://' . $org_slug . '.' . $host_port;
        }
        return $validated;
    }

    /**
     * Validate an org/project slug.
     *
     * @throws KError K9O_INVALID_FIELD_VALUE on violation
     */
    public static function validate_slug(string $slug, string $kind): void
    {
        if (!preg_match(self::SLUG_RE, $slug) || str_ends_with($slug, '-')) {
            throw new KError(
                'K9O_INVALID_FIELD_VALUE',
                sprintf('%s_slug "%s" does not match required format ^[a-z][a-z0-9-]{1,62}$ (must not end with hyphen)', $kind, $slug),
                ['slug' => $slug, 'kind' => $kind]
            );
        }
    }

    public static function project_path(string $project_slug, string $sub_resource): string
    {
        $cleaned = ltrim($sub_resource, '/');
        return '/@' . $project_slug . '/' . $cleaned;
    }

    public static function org_path(string $resource): string
    {
        return '/' . ltrim($resource, '/');
    }

    public static function platform_path(string $resource): string
    {
        return '/' . ltrim($resource, '/');
    }
}
