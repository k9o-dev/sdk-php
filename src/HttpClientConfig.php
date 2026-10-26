<?php

declare(strict_types=1);

namespace K9o;

/**
 * Per-instance HTTP config bound on K9oClient. Split out from
 * HttpTransport.php so Composer PSR-4 autoloading resolves it.
 */
final class HttpClientConfig
{
    /**
     * @param array<string, string> $default_headers
     */
    public function __construct(
        public readonly string $base_url,
        public readonly ?string $api_key = null,
        public readonly ?string $org_slug = null,
        public readonly ?string $project_slug = null,
        public readonly array $default_headers = [],
        public readonly ?RetryConfig $retry_config = null,
        /**
         * Optional transport override (tests): fn(string $method, string $url, list<string> $header_lines,
         * ?string $body): array{status:int, body:string, location:?string, error:string}. Default is ext-curl.
         */
        public readonly ?\Closure $transport = null
    ) {}
}
