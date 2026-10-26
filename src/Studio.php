<?php

declare(strict_types=1);

namespace K9o;

/**
 * Studio namespace (workflows + dashboards).
 *
 * Composition: $client->studio->workflows + $client->studio->dashboards.
 * PHP idiom: snake_case methods + throw K9o\KError + Envelope return.
 */
final class Studio
{
    public readonly Studio\Workflows $workflows;
    public readonly Studio\Dashboards $dashboards;

    public function __construct(HttpClientConfig $config)
    {
        $this->workflows = new Studio\Workflows($config);
        $this->dashboards = new Studio\Dashboards($config);
    }

    /** @internal */
    public static function require_project_slug(HttpClientConfig $config, string $surface): string
    {
        if ($config->project_slug === null || $config->project_slug === '') {
            throw new KError(
                'K9O_MISSING_PROJECT_CONTEXT',
                $surface . ' operations require project_slug bound on the client'
            );
        }
        return $config->project_slug;
    }
}
