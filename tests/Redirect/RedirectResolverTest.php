<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\Models\Redirect;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('serves a real page even when a redirect exists at its path', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    Redirect::create(['locale' => 'en', 'from_path' => 'about', 'to_path' => 'elsewhere', 'status_code' => 301]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.page.id', $page->id);
});

it('redirects after a page lookup miss', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'old', 'to_path' => 'new', 'status_code' => 301]);

    $this->get('/old')->assertRedirect('/new')->assertStatus(301);
});

it('honours a 302 status code', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'old', 'to_path' => 'new', 'status_code' => 302]);

    $this->get('/old')->assertStatus(302);
});

it('preserves the incoming query string', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'old', 'to_path' => 'new', 'status_code' => 301]);

    $this->get('/old?page=2&ref=abc')->assertRedirect('/new?page=2&ref=abc');
});

it('places the incoming query string before a target fragment', function (string $target, string $expected): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'old', 'to_path' => $target, 'status_code' => 301]);

    $this->get('/old?a=1')->assertRedirect($expected);
})->with([
    'local target' => ['new#section', '/new?a=1#section'],
    'external target' => ['https://example.com/page#section', 'https://example.com/page?a=1#section'],
]);

it('keeps the target query string and drops the incoming one', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'old', 'to_path' => 'new?keep=1', 'status_code' => 301]);

    $this->get('/old?ref=abc')->assertRedirect('/new?keep=1');
});

it('redirects to an external URL', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'gone', 'to_path' => 'https://example.com/landing', 'status_code' => 301]);

    $this->get('/gone')->assertRedirect('https://example.com/landing');
});

it('resolves a locale-specific redirect under its prefix', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    Redirect::create(['locale' => 'uk', 'from_path' => 'staryi', 'to_path' => 'novyi', 'status_code' => 301]);

    $this->get('/uk/staryi')->assertRedirect('/uk/novyi');
});

it('applies a global redirect across locales', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    Redirect::create(['locale' => null, 'from_path' => 'g', 'to_path' => 'g2', 'status_code' => 301]);

    $this->get('/g')->assertRedirect('/g2');
    $this->get('/uk/g')->assertRedirect('/uk/g2');
});

it('follows a redirect chain to its final target', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'a', 'to_path' => 'b', 'status_code' => 301]);
    Redirect::create(['locale' => 'en', 'from_path' => 'b', 'to_path' => 'c', 'status_code' => 301]);

    $this->get('/a')->assertRedirect('/c');
});

it('does not redirect when a stored mixed-locale chain cycles', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'a', 'to_path' => 'b', 'status_code' => 301]);
    Redirect::create(['locale' => null, 'from_path' => 'b', 'to_path' => 'a', 'status_code' => 301]);

    $this->get('/b', ['X-Inertia' => 'true'])->assertNotFound();
});

it('redirects to a normal internal path unchanged', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'old', 'to_path' => '/new/place/', 'status_code' => 301]);

    $this->get('/old')->assertRedirect('/new/place');
});

it('trims backslashes off an internal target so the Location cannot start with an external-looking /\\', function (): void {
    // Legacy row predating validation: "\evil.example" stored as an internal
    // path. Without trimming, the built Location would be "/\evil.example",
    // which browsers fold into the external "//evil.example".
    Redirect::create(['locale' => 'en', 'from_path' => 'gone', 'to_path' => '\\evil.example', 'status_code' => 301]);

    $this->get('/gone')->assertRedirect('/evil.example');
});

it('passes a protocol-relative target through untouched rather than treating it as an internal path', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'gone', 'to_path' => '//evil.example', 'status_code' => 301]);

    // RedirectPath::isExternal() classifies a leading "//" as external, so
    // RedirectResolver hands it to redirect()->to() verbatim instead of
    // routing it through PageUrl::build() as if the rest were an internal
    // page path (which would otherwise turn a stored "//evil.example" into
    // a same-origin-looking "/evil.example" internal link).
    $this->get('/gone')->assertRedirect('//evil.example');
});
