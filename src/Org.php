<?php

declare(strict_types=1);

namespace K9o;

/**
 * Org-plane bound namespace — $client->org. Singular
 * bound-org namespace (distinct from $client->orgs collection): members /
 * groups / smtp / audit_log / gate (roles + idp_providers) + default_llm.
 * Org-plane routes live on the org host without the @<projectSlug>/ prefix.
 *
 * Sub-namespace helper classes live in this file (loaded with Org;
 * instantiated only here) to avoid PSR-4 one-file-per-class ceremony.
 */
final class Org
{
    public readonly OrgMembers $members;
    public readonly OrgGroups $groups;
    public readonly OrgSmtp $smtp;
    public readonly OrgAuditLog $audit_log;
    public readonly OrgGate $gate;
    public readonly Org\DefaultLlm $default_llm;

    public function __construct(private readonly HttpClientConfig $config)
    {
        $this->members = new OrgMembers($config);
        $this->groups = new OrgGroups($config);
        $this->smtp = new OrgSmtp($config);
        $this->audit_log = new OrgAuditLog($config);
        $this->gate = new OrgGate($config);
        $this->default_llm = new Org\DefaultLlm($config);
    }

    /** GET /org — the current org (slug + name + status). */
    public function get(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('org'));
    }
}

/** @internal */
final class OrgMembers
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * GET /members. `$status` is the stored membership status ('active' or 'suspended'); `$acceptance_status` is
     * 'accepted' or 'pending'.
     */
    public function list(?string $status = null, ?string $user_id = null, ?string $role = null, ?string $acceptance_status = null, ?string $state = null): Envelope
    {
        $q = [];
        if ($user_id !== null) $q['userId'] = $user_id;
        if ($role !== null) $q['role'] = $role;
        if ($acceptance_status !== null) $q['acceptanceStatus'] = $acceptance_status;
        if ($status !== null) $q['status'] = $status;
        if ($state !== null) $q['state'] = $state;
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('members'), null, $q);
    }

    /** POST /members { email, role } */
    public function invite(string $email, string $role): Envelope
    {
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('members'), ['email' => $email, 'role' => $role]);
    }

    public function remove(string $user_id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('members/' . rawurlencode($user_id)));
    }
}

/** @internal Read-only: the gateway has no group create or delete. */
final class OrgGroups
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /** GET /groups — optionally only the groups `$user_id` belongs to. */
    public function list(?string $user_id = null): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('groups'), null,
            $user_id !== null ? ['userId' => $user_id] : []);
    }

    public function get(string $group_id): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('groups/' . rawurlencode($group_id)));
    }
}

/** @internal */
final class OrgAuditLog
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * GET /audit-log. The org audit route reads only `type` (exact event type) and `limit` (1 to 500, default 100).
     */
    public function query(?string $type = null, ?int $limit = null): Envelope
    {
        $q = [];
        if ($type !== null) $q['type'] = $type;
        if ($limit !== null) $q['limit'] = (string) $limit;
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('audit-log'), null, $q);
    }
}

/** @internal */
final class OrgGate
{
    public readonly OrgRoles $roles;
    public readonly OrgIdpProviders $idp_providers;

    public function __construct(HttpClientConfig $config)
    {
        $this->roles = new OrgRoles($config);
        $this->idp_providers = new OrgIdpProviders($config);
    }
}

/** @internal Org role grants (/role-grants). */
final class OrgRoles
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /** GET /role-grants (active grants). */
    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('role-grants'));
    }

    /** POST /role-grants { userId, role, decisionReason? } */
    public function grant(string $user_id, string $role, ?string $decision_reason = null): Envelope
    {
        $body = ['userId' => $user_id, 'role' => $role];
        if ($decision_reason !== null) $body['decisionReason'] = $decision_reason;
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('role-grants'), $body);
    }

    public function get(string $grant_id): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('role-grants/' . rawurlencode($grant_id)));
    }

    /** DELETE /role-grants/<id> with an optional { decisionReason } body. */
    public function revoke(string $grant_id, ?string $decision_reason = null): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('role-grants/' . rawurlencode($grant_id)),
            $decision_reason !== null ? ['decisionReason' => $decision_reason] : null);
    }
}

/** @internal The org's identity providers (/identity-providers). */
final class OrgIdpProviders
{
    private const CREATE_OPTIONAL = [
        'redirect_uri' => 'redirectUri', 'metadata_url' => 'metadataUrl', 'attribute_map' => 'attributeMap', 'enabled' => 'enabled',
    ];

    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('identity-providers'));
    }

    /**
     * POST /identity-providers { idpProvider, displayName, clientId, clientSecretRef, tierGating, ... }.
     *
     * idp_provider: google github microsoft oidc saml scim ldap active_directory apple facebook twitter.
     * tier_gating: 'free_tier_ok' or 'ridge_plus'. client_secret_ref: a reference to the stored client secret,
     * never the secret itself. `$opts` keys: redirect_uri, metadata_url, attribute_map, enabled.
     *
     * @param array{redirect_uri?: string, metadata_url?: ?string, attribute_map?: array<string, mixed>, enabled?: bool} $opts
     */
    public function create(string $idp_provider, string $display_name, string $client_id, string $client_secret_ref, string $tier_gating, array $opts = []): Envelope
    {
        $body = [
            'idpProvider' => $idp_provider, 'displayName' => $display_name, 'clientId' => $client_id,
            'clientSecretRef' => $client_secret_ref, 'tierGating' => $tier_gating,
        ];
        foreach ($opts as $k => $v) {
            if (!is_string($k) || !isset(self::CREATE_OPTIONAL[$k])) {
                throw new KError('K9O_INVALID_FIELD_VALUE', sprintf('unknown idp_providers.create option "%s"', (string) $k),
                    ['field' => (string) $k, 'reason' => 'unknown_option']);
            }
            $body[self::CREATE_OPTIONAL[$k]] = $v;
        }
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('identity-providers'), $body);
    }

    public function get(string $idp_id): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('identity-providers/' . rawurlencode($idp_id)));
    }

    /**
     * PATCH /identity-providers/<id>. `$patch` keys are the gateway's: displayName, clientId, clientSecretRef,
     * redirectUri, metadataUrl, attributeMap, enabled.
     *
     * @param array<string, mixed> $patch
     */
    public function configure(string $idp_id, array $patch): Envelope
    {
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('identity-providers/' . rawurlencode($idp_id)), $patch === [] ? new \stdClass() : $patch);
    }

    public function delete(string $idp_id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('identity-providers/' . rawurlencode($idp_id)));
    }
}

/** @internal The org's SMTP relay, stored in the org record (PATCH /org { smtpConfig }). */
final class OrgSmtp
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * PATCH /org { smtpConfig: { host, port, username, passwordRef, fromAddress, fromName? } }.
     * `$password_ref` references the stored SMTP password; it is never the password itself.
     */
    public function set(
        string $host,
        int $port,
        string $username,
        string $password_ref,
        string $from_address,
        ?string $from_name = null
    ): Envelope {
        $smtp = [
            'host' => $host, 'port' => $port, 'username' => $username, 'passwordRef' => $password_ref,
            'fromAddress' => $from_address,
        ];
        if ($from_name !== null) $smtp['fromName'] = $from_name;
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('org'), ['smtpConfig' => $smtp]);
    }

    /** POST /org/smtp/test-email { to, subject? } */
    public function test(string $to, ?string $subject = null): Envelope
    {
        $body = ['to' => $to];
        if ($subject !== null) $body['subject'] = $subject;
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('org/smtp/test-email'), $body);
    }

    /** PATCH /org { smtpConfig: null } */
    public function unset(): Envelope
    {
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('org'), ['smtpConfig' => null]);
    }
}
