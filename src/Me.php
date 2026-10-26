<?php

declare(strict_types=1);

namespace K9o;

/**
 * Operator-self namespace — $client->me. Cross-org; org-plane routes under /me.
 * Sub-namespaces: profile / sessions / api_tokens.
 *
 * Sub-namespace helper classes live in this file (loaded with Me;
 * instantiated only here) to avoid PSR-4 one-file-per-class ceremony.
 */
final class Me
{
    public readonly MeProfile $profile;
    public readonly MeSessions $sessions;
    public readonly MeApiTokens $api_tokens;

    public function __construct(HttpClientConfig $config)
    {
        $this->profile = new MeProfile($config);
        $this->sessions = new MeSessions($config);
        $this->api_tokens = new MeApiTokens($config);
    }
}

/** @internal */
final class MeProfile
{
    private const FIELDS = ['display_name' => 'displayName', 'locale' => 'locale', 'timezone' => 'timezone'];

    public function __construct(private readonly HttpClientConfig $config) {}

    public function get(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('me'));
    }

    /**
     * PATCH /me. Pass only the fields to change (display_name, locale, timezone); an explicit null is sent as null.
     *
     * @param array{display_name?: ?string, locale?: ?string, timezone?: ?string} $fields
     */
    public function update(array $fields): Envelope
    {
        $patch = [];
        foreach ($fields as $k => $v) {
            if (!is_string($k) || !isset(self::FIELDS[$k])) {
                throw new KError('K9O_INVALID_FIELD_VALUE', sprintf('unknown profile field "%s"', (string) $k),
                    ['field' => (string) $k, 'reason' => 'unknown_option']);
            }
            $patch[self::FIELDS[$k]] = $v;
        }
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('me'), $patch === [] ? new \stdClass() : $patch);
    }
}

/** @internal */
final class MeSessions
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('me/sessions'));
    }

    /** POST /auth/sessions/<id>/revoke */
    public function revoke(string $session_id): Envelope
    {
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('auth/sessions/' . rawurlencode($session_id) . '/revoke'));
    }

    /** POST /me/sessions/revoke-all */
    public function revoke_all(): Envelope
    {
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('me/sessions/revoke-all'));
    }
}

/** @internal */
final class MeApiTokens
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('me/api-tokens'));
    }

    /**
     * POST /me/api-tokens { label, scopeManifest, ttlSeconds? }. `$scope_manifest` is an array with the gateway's
     * keys (each may be null for "no restriction"): 'operations' (read / write / admin), 'projects',
     * 'resourceClasses', 'collections', 'cidrs'. The response carries the token's plaintext once.
     *
     * @param array{operations?: ?list<string>, projects?: ?list<string>, resourceClasses?: ?list<string>, collections?: ?array<string, mixed>, cidrs?: ?list<string>} $scope_manifest
     */
    public function create(string $label, array $scope_manifest, ?int $ttl_seconds = null): Envelope
    {
        $body = ['label' => $label, 'scopeManifest' => $scope_manifest === [] ? new \stdClass() : $scope_manifest];
        if ($ttl_seconds !== null) $body['ttlSeconds'] = $ttl_seconds;
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('me/api-tokens'), $body);
    }

    public function delete(string $token_id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('me/api-tokens/' . rawurlencode($token_id)));
    }
}
