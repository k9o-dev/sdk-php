<?php

declare(strict_types=1);

namespace K9o;

/**
 * Gate (Identity-Aware Kernel) namespace. Project-scoped.
 */
final class Gate
{
    public function __construct(private readonly HttpClientConfig $config) {}

    private function require_project(): string
    {
        if ($this->config->project_slug === null || $this->config->project_slug === '') {
            throw new KError(
                'K9O_MISSING_PROJECT_CONTEXT',
                '$client->gate operations require project_slug bound on the client'
            );
        }
        return $this->config->project_slug;
    }

    public function sign_up(string $email, string $password, ?string $display_name = null): Envelope
    {
        $body = ['email' => $email, 'password' => $password];
        if ($display_name !== null) $body['displayName'] = $display_name;
        return HttpTransport::request(
            $this->config, 'POST', UrlGrammar::project_path($this->require_project(), 'gate/signup'), $body
        );
    }

    public function sign_in(string $email, string $password): Envelope
    {
        return HttpTransport::request(
            $this->config, 'POST', UrlGrammar::project_path($this->require_project(), 'gate/signin'),
            ['email' => $email, 'password' => $password]
        );
    }

    public function sign_out(?string $refresh_token = null): Envelope
    {
        $body = [];
        if ($refresh_token !== null) $body['refreshToken'] = $refresh_token;
        return HttpTransport::request(
            $this->config, 'POST', UrlGrammar::project_path($this->require_project(), 'gate/signout'), $body
        );
    }

    public function whoami(): Envelope
    {
        return HttpTransport::request(
            $this->config, 'GET', UrlGrammar::project_path($this->require_project(), 'gate/whoami')
        );
    }

    public function refresh_token(string $refresh_token): Envelope
    {
        return HttpTransport::request(
            $this->config, 'POST', UrlGrammar::project_path($this->require_project(), 'gate/refresh'),
            ['refreshToken' => $refresh_token]
        );
    }

    public function verify_session(): Envelope
    {
        return HttpTransport::request(
            $this->config, 'GET', UrlGrammar::project_path($this->require_project(), 'gate/verify')
        );
    }

    /**
     * The project's hosted sign-in page URL and WebAuthn rpId: `GET /@<slug>/gate/hosted`, unauthenticated and
     * cacheable. Sends no Authorization header (even with an api_key configured) and no cookie. Returns an
     * Envelope whose data is `['signInUrl' => string, 'rpId' => string]`. An unknown org, a deleted project and a
     * non-Gate project all answer the same 404 K9O_PROJECT_NOT_FOUND; take both values as given.
     */
    public function hosted(): Envelope
    {
        return HttpTransport::request(
            $this->config, 'GET', UrlGrammar::project_path($this->require_project(), 'gate/hosted'),
            null, [], [], true
        );
    }
}
