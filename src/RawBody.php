<?php

declare(strict_types=1);

namespace K9o;

/**
 * A pre-encoded request body with its own content-type (a multipart/form-data upload, boundary included).
 * `HttpTransport` sends it as given and does not force application/json.
 */
final class RawBody
{
    public function __construct(public readonly string $content, public readonly string $content_type) {}
}
