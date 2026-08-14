<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\Seo\VerificationToken;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

// Plain GETs render through the @vite-free root view — see
// workbench/resources/views/server-head-test.blade.php's docblock.
beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.render.root_view' => 'server-head-test']);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('extracts a bare Google verification token as-is', function (): void {
    expect(VerificationToken::google()->extract('abc123DEF_-token'))->toBe('abc123DEF_-token')
        ->and(VerificationToken::google()->extract('  abc123  '))->toBe('abc123');
});

it('extracts the content value from a pasted Google verification meta tag', function (): void {
    $pasted = '<meta name="google-site-verification" content="XyZ_09-abc123" />';

    expect(VerificationToken::google()->extract($pasted))->toBe('XyZ_09-abc123');
});

it('extracts the content value from a pasted Bing verification meta tag', function (): void {
    $pasted = '<meta name="msvalidate.01" content="ABCDEF1234567890" />';

    expect(VerificationToken::bing()->extract($pasted))->toBe('ABCDEF1234567890');
});

it('does not mine a token out of a meta tag for the other verification method', function (): void {
    $googleTag = '<meta name="google-site-verification" content="abc123">';

    // Asking the Bing parser to read a Google tag must not succeed — it
    // would silently store a Google token under seo.verification.bing.
    expect(VerificationToken::bing()->extract($googleTag))->toBe($googleTag)
        ->and(VerificationToken::bing()->valid(VerificationToken::bing()->extract($googleTag)))->toBeFalse();
});

it('returns garbage trimmed as-is and rejects it as invalid', function (): void {
    expect(VerificationToken::google()->extract('not a meta tag at all'))->toBe('not a meta tag at all')
        ->and(VerificationToken::google()->valid(VerificationToken::google()->extract('not a meta tag at all')))->toBeFalse()
        ->and(VerificationToken::google()->extract(''))->toBe('')
        ->and(VerificationToken::google()->valid(''))->toBeFalse();
});

it('validates the token charset and length', function (): void {
    expect(VerificationToken::google()->valid('abc123DEF_-token'))->toBeTrue()
        ->and(VerificationToken::google()->valid('base64+with/slash='))->toBeTrue()
        ->and(VerificationToken::google()->valid('has spaces'))->toBeFalse()
        ->and(VerificationToken::google()->valid('<script>alert(1)</script>'))->toBeFalse()
        ->and(VerificationToken::google()->valid(str_repeat('a', 513)))->toBeFalse();
});

it('renders both verification meta tags server-side when configured', function (): void {
    app(Settings::class)->set('seo.verification.google', 'google-token-123');
    app(Settings::class)->set('seo.verification.bing', 'bing-token-456');
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain('<meta data-inertia="verification-google" name="google-site-verification" content="google-token-123">')
        ->toContain('<meta data-inertia="verification-bing" name="msvalidate.01" content="bing-token-456">');
});

it('normalizes a stored full meta tag down to its token at render time', function (): void {
    app(Settings::class)->set('seo.verification.google', '<meta name="google-site-verification" content="pasted-token" />');
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->toContain('content="pasted-token"');
});

it('renders neither verification tag when both settings are empty', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->not->toContain('google-site-verification')
        ->not->toContain('msvalidate.01');
});

it('renders no verification markup when the stored value is malformed', function (): void {
    app(Settings::class)->set('seo.verification.google', '"><script>alert(1)</script>');
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->not->toContain('google-site-verification')
        ->not->toContain('<script>alert(1)</script>');
});
