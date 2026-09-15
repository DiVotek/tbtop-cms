<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\Seo\RobotsRegistry;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('closes the admin prefix and the login redirect to crawlers', function (): void {
    $body = (string) $this->get('/robots.txt')->assertOk()->getContent();

    expect($body)->toContain('Disallow: /admin')
        ->toContain('Disallow: /login');

    // Inside the User-agent group and above Sitemap: a Disallow after the
    // blank line that precedes Sitemap belongs to no agent and is discarded.
    $group = substr($body, 0, strpos($body, "\n\n") ?: strlen($body));

    expect($group)->toContain('Disallow: /admin')
        ->toContain('Disallow: /login');
});

it('follows a renamed admin prefix', function (): void {
    config(['tbtop-cms.admin.prefix' => 'manage']);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /manage', false)
        ->assertDontSee('Disallow: /admin', false);
});

it('adds the paths a host configures', function (): void {
    config(['tbtop-cms.seo.robots.disallow' => ['/_storefront']]);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /_storefront', false);
});

it('adds the paths a package registers', function (): void {
    RobotsRegistry::extend(fn (): array => ['/_storefront']);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /_storefront', false);
});

it('normalizes a configured path to one rooted line', function (): void {
    config(['tbtop-cms.seo.robots.disallow' => ['cart/', '/cart']]);

    $body = (string) $this->get('/robots.txt')->assertOk()->getContent();

    expect(substr_count($body, 'Disallow: /cart'))->toBe(1);
});

it('leaves the site-wide disallow alone when indexing is off', function (): void {
    app(Settings::class)->set('seo.indexing_enabled', false);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /', false)
        ->assertDontSee('Disallow: /admin', false)
        ->assertDontSee('Disallow: /login', false);
});
