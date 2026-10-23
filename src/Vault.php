<?php

declare(strict_types=1);

namespace K9o;

/**
 * Vault namespace — Kibo's Zero-Knowledge secrets surface,
 * mirrors the TS `client.kibo.vault` composition
 * (`packages/kibo/src/vault.ts`).
 *
 * v1 PHP surface ships only the REST `secrets` family
 * (`services/gateway/src/routes/vault-secrets.ts`) — the opaque ZK-DEK
 * store/fetch (`vault.zkDeks`, F.5) is a separate
 * native-binding surface shipped as its own package in the languages that
 * have it (go/kibo, ruby's k9o/kibo.rb, kotlin's k9o-kibo, swift's
 * K9OKibo, dotnet-kibo, java-kibo); PHP has no such package yet, so
 * `zkDeks` is intentionally not part of this namespace.
 *
 * Composition: $client->vault->secrets.
 */
final class Vault
{
    public readonly Vault\Secrets $secrets;

    public function __construct(HttpClientConfig $config)
    {
        $this->secrets = new Vault\Secrets($config);
    }

    /** @internal shared by Vault\Secrets (and any future vault sub-namespace). */
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
