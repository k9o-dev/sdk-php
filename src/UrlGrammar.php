<?php

declare(strict_types=1);

namespace K9o;

/**
 * URL grammar v2.5.68 helpers.
 */
final class UrlGrammar
{
    private const SLUG_RE = '/^[a-z][a-z0-9-]{1,62}$/';

    /**
     * Bare platform apex hosts org-subdomain derivation applies to —
     * production and staging share the "<org_slug>.<apex>" wire-routing
     * scheme (confirmed live). A third apex needs the same confirmation
     * before joining this set.
     */
    private const ORG_DERIVABLE_APEXES = ['api.k9o.io', 'api-staging.k9o.io'];

    private function __construct() {}

    /**
     * Validate + normalise a baseUrl.
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
     * Resolve the effective base_url.
     *
     * base_url is OPTIONAL — it defaults to the platform apex
     * "https://api.k9o.io". When the (validated) host is a bare apex in
     * self::ORG_DERIVABLE_APEXES and an org_slug is supplied, the SDK
     * composes the canonical org-subdomain wire root
     * "https://<org_slug>.<apex>". An explicit "<org_slug>"
     * subdomain, a self-hosted / Sovereign host, or a dev host (anything
     * not in that set) passes through unchanged — org_slug is
     * intentionally NOT composed onto a non-apex host.
     *
     * Fixed 2026-08-30 (SDK-2): this used to silently ignore org_slug for
     * "api-staging.k9o.io" too, not just genuine self-hosted/custom hosts,
     * since only the literal production apex string was checked.
     *
     * @throws KError K9O_INVALID_BASE_URL on path/scheme/query/fragment violation
     * @throws KError K9O_INVALID_FIELD_VALUE on malformed org_slug during derivation
     */
    public static function resolve_base_url(?string $base_url, ?string $org_slug = null): string
    {
        $validated = self::validate_base_url($base_url ?? 'https://api.k9o.io');
        $parsed = parse_url($validated);
        $host = $parsed['host'] ?? null;
        if ($host !== null && in_array($host, self::ORG_DERIVABLE_APEXES, true) && $org_slug !== null && $org_slug !== '') {
            self::validate_slug($org_slug, 'org');
            return $parsed['scheme'] . '://' . $org_slug . '.' . $host;
        }
        return $validated;
    }

    /**
     * Validate org/project slug.
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
