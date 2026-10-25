<?php

declare(strict_types=1);

namespace K9o;

/**
 * Org-plane bound namespace — $client->org. Singular
 * bound-org namespace (distinct from $client->orgs collection): members /
 * groups / smtp / audit_log / gate (roles + idp_providers) + default_llm.
 * Org-plane URL grammar routes against the org subdomain
 * without the @<projectSlug>/ prefix.
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

    /** Read the current org (slug + name + status + settings). */
    public function get(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('org'));
    }
}

/** @internal */
final class OrgMembers
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(?string $status = null): Envelope
    {
        $q = $status !== null ? ['status' => $status] : [];
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('members'), null, $q);
    }

    public function invite(string $email, string $role): Envelope
    {
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('members'), ['email' => $email, 'role' => $role]);
    }

    public function get(string $user_id): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('members/' . rawurlencode($user_id)));
    }

    public function remove(string $user_id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('members/' . rawurlencode($user_id)));
    }
}

/** @internal */
final class OrgGroups
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('groups'));
    }

    public function create(string $name): Envelope
    {
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('groups'), ['name' => $name]);
    }

    public function get(string $group_id): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('groups/' . rawurlencode($group_id)));
    }

    public function delete(string $group_id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('groups/' . rawurlencode($group_id)));
    }
}

/** @internal */
final class OrgAuditLog
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * @param array<string, scalar> $filters
     */
    public function query(array $filters = []): Envelope
    {
        $q = [];
        foreach ($filters as $k => $v) {
            if ($v !== null) $q[$k] = (string) $v;
        }
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

/** @internal */
final class OrgRoles
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(?string $user_id = null, ?string $role = null): Envelope
    {
        $q = [];
        if ($user_id !== null) $q['userId'] = $user_id;
        if ($role !== null) $q['role'] = $role;
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('org-role-grants'), null, $q);
    }

    public function grant(string $user_id, string $role): Envelope
    {
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('org-role-grants'), ['userId' => $user_id, 'role' => $role]);
    }

    public function get(string $grant_id): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('org-role-grants/' . rawurlencode($grant_id)));
    }

    public function revoke(string $grant_id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('org-role-grants/' . rawurlencode($grant_id)));
    }
}

/** @internal */
final class OrgIdpProviders
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('idp-providers'));
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(string $name, string $kind, array $config): Envelope
    {
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('idp-providers'), ['name' => $name, 'kind' => $kind, 'config' => $config]);
    }

    public function get(string $idp_id): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('idp-providers/' . rawurlencode($idp_id)));
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function configure(string $idp_id, array $patch): Envelope
    {
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('idp-providers/' . rawurlencode($idp_id)), $patch);
    }

    public function delete(string $idp_id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('idp-providers/' . rawurlencode($idp_id)));
    }
}

/** @internal */
final class OrgSmtp
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function get(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('org/smtp'));
    }

    public function set(
        string $host,
        int $port,
        string $username,
        string $password,
        string $from_email,
        string $from_name,
        string $encryption,
        ?string $reply_to_email = null
    ): Envelope {
        $smtp = [
            'host' => $host, 'port' => $port, 'username' => $username, 'password' => $password,
            'fromEmail' => $from_email, 'fromName' => $from_name, 'encryption' => $encryption,
        ];
        if ($reply_to_email !== null) $smtp['replyToEmail'] = $reply_to_email;
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('org'), ['smtpConfig' => $smtp]);
    }

    public function test(string $to, ?string $from_override = null): Envelope
    {
        $body = ['to' => $to];
        if ($from_override !== null) $body['fromOverride'] = $from_override;
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('org/smtp/test-email'), $body);
    }

    public function unset(): Envelope
    {
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('org'), ['smtpConfig' => null]);
    }
}
