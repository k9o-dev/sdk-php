<?php

declare(strict_types=1);

namespace K9o;

/**
 * Gate (Identity-Aware Kernel) namespace. Project-scoped; the gateway closes each body to the fields shown.
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

    /** POST /@<slug>/gate/signup { email, password, displayName? } */
    public function sign_up(string $email, string $password, ?string $display_name = null): Envelope
    {
        $body = ['email' => $email, 'password' => $password];
        if ($display_name !== null) $body['displayName'] = $display_name;
        return HttpTransport::request(
            $this->config, 'POST', UrlGrammar::project_path($this->require_project(), 'gate/signup'), $body
        );
    }

    /** POST /@<slug>/gate/signin { email, password } */
    public function sign_in(string $email, string $password): Envelope
    {
        return HttpTransport::request(
            $this->config, 'POST', UrlGrammar::project_path($this->require_project(), 'gate/signin'),
            ['email' => $email, 'password' => $password]
        );
    }

    /** POST /@<slug>/gate/signout { refreshToken } — the refresh token is required (the gateway answers 400 without it). */
    public function sign_out(string $refresh_token): Envelope
    {
        return HttpTransport::request(
            $this->config, 'POST', UrlGrammar::project_path($this->require_project(), 'gate/signout'),
            ['refreshToken' => $refresh_token]
        );
    }

    /** GET /@<slug>/gate/whoami */
    public function whoami(): Envelope
    {
        return HttpTransport::request(
            $this->config, 'GET', UrlGrammar::project_path($this->require_project(), 'gate/whoami')
        );
    }

    /** POST /@<slug>/gate/refresh { refreshToken } */
    public function refresh_token(string $refresh_token): Envelope
    {
        return HttpTransport::request(
            $this->config, 'POST', UrlGrammar::project_path($this->require_project(), 'gate/refresh'),
            ['refreshToken' => $refresh_token]
        );
    }
}
