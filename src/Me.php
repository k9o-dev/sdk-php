<?php

declare(strict_types=1);

namespace K9o;

/**
 * Operator-self namespace — $client->me. Cross-org;
 * org-plane routes under /me. Sub-namespaces: profile / sessions /
 * api_tokens / notifications / audit_log / data_export.
 *
 * Sub-namespace helper classes live in this file (loaded with Me;
 * instantiated only here) to avoid PSR-4 one-file-per-class ceremony.
 */
final class Me
{
    public readonly MeProfile $profile;
    public readonly MeSessions $sessions;
    public readonly MeApiTokens $api_tokens;
    public readonly MeNotifications $notifications;
    public readonly MeAuditLog $audit_log;
    public readonly MeDataExport $data_export;

    public function __construct(HttpClientConfig $config)
    {
        $this->profile = new MeProfile($config);
        $this->sessions = new MeSessions($config);
        $this->api_tokens = new MeApiTokens($config);
        $this->notifications = new MeNotifications($config);
        $this->audit_log = new MeAuditLog($config);
        $this->data_export = new MeDataExport($config);
    }
}

/** @internal */
final class MeProfile
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function get(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('me'));
    }

    public function update(?string $display_name = null): Envelope
    {
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('me'), ['displayName' => $display_name]);
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

    public function revoke(string $session_id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('me/sessions/' . rawurlencode($session_id)));
    }

    public function revoke_all(): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('me/sessions'));
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

    public function create(string $name, ?int $expires_in_days = null, ?string $scope = null): Envelope
    {
        $body = ['name' => $name];
        if ($expires_in_days !== null) $body['expiresInDays'] = $expires_in_days;
        if ($scope !== null) $body['scope'] = $scope;
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('me/api-tokens'), $body);
    }

    public function delete(string $token_id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', UrlGrammar::org_path('me/api-tokens/' . rawurlencode($token_id)));
    }
}

/** @internal */
final class MeNotifications
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function list(?bool $unread_only = null, ?int $limit = null): Envelope
    {
        $q = [];
        if ($unread_only !== null) $q['unreadOnly'] = $unread_only ? 'true' : 'false';
        if ($limit !== null) $q['limit'] = (string) $limit;
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('me/notifications'), null, $q);
    }

    public function mark_read(string $notification_id): Envelope
    {
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('me/notifications/' . rawurlencode($notification_id)), ['read' => true]);
    }

    public function mark_all_read(): Envelope
    {
        return HttpTransport::request($this->config, 'PATCH', UrlGrammar::org_path('me/notifications'), ['read' => true]);
    }
}

/** @internal */
final class MeAuditLog
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
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('me/audit-log'), null, $q);
    }
}

/** @internal */
final class MeDataExport
{
    public function __construct(private readonly HttpClientConfig $config) {}

    public function request(string $format = 'json'): Envelope
    {
        return HttpTransport::request($this->config, 'POST', UrlGrammar::org_path('me/data-export'), ['format' => $format]);
    }

    public function status(string $job_id): Envelope
    {
        return HttpTransport::request($this->config, 'GET', UrlGrammar::org_path('me/data-export/' . rawurlencode($job_id)));
    }
}
