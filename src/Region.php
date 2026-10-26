<?php

declare(strict_types=1);

namespace K9o;

/**
 * Regional host resolution.
 *
 * A region-free org host `<org>.api.<rest>` (or `<org>.api-staging.<rest>`) redirects GET/HEAD only and refuses a
 * request carrying a body, Authorization or cookie. The SDK learns the region with ONE unauthenticated `HEAD /` that
 * does NOT follow redirects, takes the 308 `Location`, caches the regional origin per client config, and sends every
 * request there.
 *
 * Nothing here knows an apex: the host comes from the configured base_url and `<rest>` is whatever follows the
 * gateway label, so public-cloud, internal-apex, air-gapped and localhost deployments all work. Only a region-free
 * org host (see UrlGrammar::parse_api_host) is probed; the probe carries no credential and no default header; the
 * Location is trusted only if https, no userinfo, no port, and its host is exactly the same org and the same
 * remainder with a region code appended to the gateway label. A definite answer is cached; a network failure of the
 * probe is not.
 */
final class Region
{

    /** @var \WeakMap<HttpClientConfig, string>|null */
    private static ?\WeakMap $cache = null;

    private function __construct() {}

    /** The regional origin a `Location` may name for `$host`, or null if it is not exactly that. Pure. */
    public static function origin_from_location(string $host, string $location, string $base): ?string
    {
        $from = UrlGrammar::parse_api_host($host);
        if ($from === null || $from['org_slug'] === null || $from['region'] !== null) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $location) === 1) {
            return null;
        }
        $abs = $location;
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $abs) !== 1) {
            // Relative reference: resolve against the configured base.
            $b = parse_url($base);
            if ($b === false || !isset($b['scheme'], $b['host'])) {
                return null;
            }
            $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
            if (str_starts_with($abs, '//')) {
                $abs = $b['scheme'] . ':' . $abs;
            } elseif (str_starts_with($abs, '/')) {
                $abs = $origin . $abs;
            } else {
                $abs = $origin . '/' . $abs;
            }
        }
        $u = parse_url($abs);
        if ($u === false || ($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass'])
            || isset($u['port']) || !isset($u['host'])) {
            return null;
        }
        if (preg_match('#^[a-z]+://[^/?\#]*[@:]#i', $abs) === 1) {
            return null; // userinfo or an (empty) port that parse_url may have swallowed
        }
        $h = strtolower($u['host']);
        $to = UrlGrammar::parse_api_host($h);
        if ($to === null || $to['region'] === null || $to['org_slug'] !== $from['org_slug']
            || $to['family'] !== $from['family'] || $to['apex'] !== $from['apex']) {
            return null;
        }
        return 'https://' . $h;
    }

    /** The origin requests for this client go to: the cached regional origin once learned, else the configured base. */
    public static function resolve(HttpClientConfig $config): string
    {
        self::$cache ??= new \WeakMap();
        if (isset(self::$cache[$config])) {
            return self::$cache[$config];
        }
        $configured = $config->base_url;
        $parts = parse_url($configured);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $parsed_host = $host === '' ? null : UrlGrammar::parse_api_host($host);
        if ($parsed_host === null || $parsed_host['org_slug'] === null || $parsed_host['region'] !== null) {
            self::$cache[$config] = $configured;
            return $configured;
        }
        // The probe: no Authorization, no cookie, none of the configured default headers.
        $res = HttpTransport::send($config, 'HEAD', rtrim($configured, '/') . '/', ['accept: */*'], null);
        if ($res['error'] !== '') {
            return $configured; // network failure: not cached
        }
        $origin = $configured;
        if ($res['status'] === 308 && $res['location'] !== null) {
            $origin = self::origin_from_location($host, $res['location'], $configured) ?? $configured;
        }
        self::$cache[$config] = $origin;
        return $origin;
    }
}
