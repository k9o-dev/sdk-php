<?php

declare(strict_types=1);

namespace K9o\Tests;

use K9o\KError;
use K9o\UrlGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlGrammarTest extends TestCase
{
    public static function baseUrlAcceptsProvider(): array
    {
        return [
            ['https://api.k9o.io', 'https://api.k9o.io'],
            ['https://acme.api.k9o.io', 'https://acme.api.k9o.io'],
            ['http://localhost:8080', 'http://localhost:8080'],
            ['https://api.k9o.io/', 'https://api.k9o.io'],
        ];
    }

    #[DataProvider('baseUrlAcceptsProvider')]
    public function testValidateBaseUrlAccepts(string $input, string $expected): void
    {
        $this->assertSame($expected, UrlGrammar::validate_base_url($input));
    }

    public static function baseUrlRejectsProvider(): array
    {
        return [
            ['https://api.k9o.io/v2'],
            ['wss://api.k9o.io'],
            ['api.k9o.io'],
            ['https://api.k9o.io?x=1'],
        ];
    }

    #[DataProvider('baseUrlRejectsProvider')]
    public function testValidateBaseUrlRejects(string $input): void
    {
        $this->expectException(KError::class);
        UrlGrammar::validate_base_url($input);
    }

    public static function slugAcceptsProvider(): array
    {
        return [['acme'], ['member-portal'], ['a1'], ['a-1-z']];
    }

    #[DataProvider('slugAcceptsProvider')]
    public function testValidateSlugAccepts(string $slug): void
    {
        UrlGrammar::validate_slug($slug, 'test');
        $this->expectNotToPerformAssertions();
    }

    public static function slugRejectsProvider(): array
    {
        return [['Acme'], ['1acme'], ['-acme'], ['acme-'], ['a']];
    }

    #[DataProvider('slugRejectsProvider')]
    public function testValidateSlugRejects(string $slug): void
    {
        $this->expectException(KError::class);
        UrlGrammar::validate_slug($slug, 'test');
    }

    public function testResolveBaseUrlDefaultsToApexWhenOmitted(): void
    {
        $this->assertSame('https://api.k9o.io', UrlGrammar::resolve_base_url(null));
    }

    public function testResolveBaseUrlDerivesSubdomainFromApexPlusOrgSlug(): void
    {
        $this->assertSame(
            'https://acme.api.k9o.io',
            UrlGrammar::resolve_base_url(null, 'acme')
        );
        $this->assertSame(
            'https://acme.api.k9o.io',
            UrlGrammar::resolve_base_url('https://api.k9o.io', 'acme')
        );
    }

    public function testResolveBaseUrlPassesThroughExplicitSubdomain(): void
    {
        $this->assertSame(
            'https://acme.api.k9o.io',
            UrlGrammar::resolve_base_url('https://acme.api.k9o.io', 'acme')
        );
        // A different org_slug must NOT override an explicit subdomain host.
        $this->assertSame(
            'https://acme.api.k9o.io',
            UrlGrammar::resolve_base_url('https://acme.api.k9o.io', 'other')
        );
    }

    public function testResolveBaseUrlDerivesSubdomainFromStagingApexPlusOrgSlug(): void
    {
        // Bare staging apex + org_slug → derive org subdomain (the cross-pool fix).
        $this->assertSame(
            'https://acme.api-staging.k9o.io',
            UrlGrammar::resolve_base_url('https://api-staging.k9o.io', 'acme')
        );
    }

    public function testResolveBaseUrlPassesThroughExplicitStagingSubdomain(): void
    {
        $this->assertSame(
            'https://acme.api-staging.k9o.io',
            UrlGrammar::resolve_base_url('https://acme.api-staging.k9o.io', 'acme')
        );
    }

    public function testResolveBaseUrlNoDeriveWithoutOrgSlugOnStagingApex(): void
    {
        $this->assertSame(
            'https://api-staging.k9o.io',
            UrlGrammar::resolve_base_url('https://api-staging.k9o.io')
        );
    }

    public function testResolveBaseUrlValidatesOrgSlugDuringStagingDerivation(): void
    {
        $this->expectException(KError::class);
        UrlGrammar::resolve_base_url('https://api-staging.k9o.io', 'Bad_Slug');
    }

    public function testResolveBaseUrlPassesThroughSelfHostedAndDevHosts(): void
    {
        $this->assertSame(
            'https://platform.acme.internal',
            UrlGrammar::resolve_base_url('https://platform.acme.internal', 'acme')
        );
        $this->assertSame(
            'http://localhost:8080',
            UrlGrammar::resolve_base_url('http://localhost:8080', 'acme')
        );
    }

    public function testResolveBaseUrlNoDeriveWithoutOrgSlug(): void
    {
        $this->assertSame('https://api.k9o.io', UrlGrammar::resolve_base_url('https://api.k9o.io'));
    }

    public function testResolveBaseUrlPreservesSchemeOnDerive(): void
    {
        $this->assertSame(
            'http://acme.api.k9o.io',
            UrlGrammar::resolve_base_url('http://api.k9o.io', 'acme')
        );
    }

    public function testResolveBaseUrlValidatesOrgSlugDuringDerivation(): void
    {
        $this->expectException(KError::class);
        UrlGrammar::resolve_base_url('https://api.k9o.io', 'Bad-Slug');
    }

    public function testResolveBaseUrlStillRejectsInvalidBaseUrl(): void
    {
        $this->expectException(KError::class);
        UrlGrammar::resolve_base_url('https://api.k9o.io/v2', 'acme');
    }

    public function testProjectPathStripsLeadingSlash(): void
    {
        $this->assertSame(
            '/@member-portal/data/posts/rec_123',
            UrlGrammar::project_path('member-portal', 'data/posts/rec_123')
        );
        $this->assertSame('/@acme/project', UrlGrammar::project_path('acme', '/project'));
    }

    public function testOrgPath(): void
    {
        $this->assertSame('/projects', UrlGrammar::org_path('projects'));
        $this->assertSame('/projects', UrlGrammar::org_path('/projects'));
    }
}
