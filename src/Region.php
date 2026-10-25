<?php

declare(strict_types=1);

namespace K9o;

/**
 * Regional host resolution.
 *
 * The region-free org host `<org>.api[-staging].k9o.io` redirects GET/HEAD only and refuses a request carrying a
 * body, Authorization or cookie. The SDK learns the region with ONE unauthenticated `HEAD /` that does NOT follow
 * redirects, takes the 308 `Location`, caches the regional origin per client config, and sends every request there.
 *
 * Only a region-free org host is probed; the probe carries no credential and no default header; the Location is
 * trusted only if https, no userinfo, no port, and host exactly `<same org>.<same apex>-<rgn>.k9o.io`. A definite
 * answer is cached; a network failure of the probe is not.
 */
final class Region
{
    private const REGION_FREE_ORG_HOST = '/^([a-z][a-z0-9-]{1,62})\.(api|api-staging)\.k9o\.io$/D';
    private const REGION_CODE = '/^[a-z]{2}[0-9]{2}$/D';

    /** @var \WeakMap<HttpClientConfig, string>|null */
    private static ?\WeakMap $cache = null;

    private function __construct() {}

    /** The regional origin a `Location` may name for `$host`, or null if it is not exactly that. Pure. */
    public static function origin_from_location(string $host, string $location, string $base): ?string
    {
        if (preg_match(self::REGION_FREE_ORG_HOST, $host, $m) !== 1) {
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
        $want = '/^' . preg_quote($m[1], '/') . '\.' . preg_quote($m[2], '/') . '-([a-z0-9]+)\.k9o\.io$/D';
        if (preg_match($want, $h, $r) !== 1 || preg_match(self::REGION_CODE, $r[1]) !== 1) {
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
        if ($host === '' || preg_match(self::REGION_FREE_ORG_HOST, $host) !== 1) {
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
