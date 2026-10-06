<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;
use Tbtop\Cms\Page\Support\PageRevisionWriter;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Storage::fake('public');
    Language::factory()->default()->create(['locale' => 'en']);

    $settings = app(Settings::class);
    $settings->set('seo.title_suffix', '— Acme');
    $settings->set('seo.default_description', 'Global description');
    $settings->set('seo.default_og_image', (string) media('global.jpg')->getKey());

    app(Cms::class)->frontendRoutes();
});

function media(string $name): Media
{
    return Media::create([
        'name' => $name,
        'disk' => 'public',
        'path' => "media/{$name}",
        'mime' => 'image/jpeg',
        'size' => 123,
    ]);
}

it('lets page SEO overrides win over global defaults', function (): void {
    $pageImage = media('page.jpg');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'is_indexable' => true,
        'seo' => ['description' => 'Page description', 'og_image' => (string) $pageImage->getKey()],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About Page — Acme')
        ->assertJsonPath('props.seo.description', 'Page description')
        ->assertJsonPath('props.seo.ogImage', url(Storage::disk('public')->url('media/page.jpg')))
        ->assertJsonPath('props.seo.noindex', false);
});

it('falls back to global SEO defaults when page fields are empty', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'is_indexable' => false,
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About Page — Acme')
        ->assertJsonPath('props.seo.description', 'Global description')
        ->assertJsonPath('props.seo.ogImage', url(Storage::disk('public')->url('media/global.jpg')))
        ->assertJsonPath('props.seo.canonical', url('/about'))
        ->assertJsonPath('props.seo.noindex', true);
});

it('falls back og:image to the page image when no og_image override is set', function (): void {
    $pageImage = media('page-body.jpg');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'image' => (string) $pageImage->getKey(),
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogImage', url(Storage::disk('public')->url('media/page-body.jpg')));
});

it('resolves og_image to null when the stored media id no longer exists', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'seo' => ['og_image' => '999999'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogImage', null);
});

it('falls back to the page summary for the meta description', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'summary' => ['en' => 'A short page summary.'],
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'A short page summary.');
});

it('falls back og:title/og:description to the resolved meta title/description when unset', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'summary' => ['en' => 'A short page summary.'],
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        // og:title falls back to the bare meta title (no title_suffix appended).
        ->assertJsonPath('props.seo.ogTitle', 'About Page')
        ->assertJsonPath('props.seo.ogDescription', 'A short page summary.');
});

it('lets explicit og_title/og_description overrides win over the meta fallback', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'seo' => [
            'title' => 'Meta title',
            'description' => 'Meta description',
            'og_title' => 'Custom OG title',
            'og_description' => 'Custom OG description',
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogTitle', 'Custom OG title')
        ->assertJsonPath('props.seo.ogDescription', 'Custom OG description');
});

it('ships ogTitle/ogDescription/ogImage props for the CmsPage <Head> to render as og: meta tags', function (): void {
    // No Inertia SSR is configured in this app (client-side <Head> only, see
    // CmsPage.tsx), so og: tags never land in server-rendered HTML — the wire
    // contract this test guards is the seo.og* props CmsPage consumes.
    $pageImage = media('page.jpg');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'seo' => [
            'og_title' => 'Custom OG title',
            'og_description' => 'Custom OG description',
            'og_image' => (string) $pageImage->getKey(),
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogTitle', 'Custom OG title')
        ->assertJsonPath('props.seo.ogDescription', 'Custom OG description')
        ->assertJsonPath('props.seo.ogImage', url(Storage::disk('public')->url('media/page.jpg')));
});

it('falls back the meta title to the page name when title and seo.title are empty', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => ''],
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About — Acme')
        // og:title inherits the same cascade via metaTitle().
        ->assertJsonPath('props.seo.ogTitle', 'About');
});

it('uses the default-locale title fallback for a non-default-locale page', function (): void {
    Language::factory()->create(['locale' => 'uk']);

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'title' => ['en' => 'About Page'],
        'seo' => [],
    ]);

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.page.title', 'About Page')
        ->assertJsonPath('props.seo.title', 'About Page — Acme');
});

it('resolves siteName from settings, defaulting to an empty string when unset', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.siteName', '');

    app(Settings::class)->set('site.name', 'Acme Co');

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.siteName', 'Acme Co');
});

it('resolves ogType/ogUrl/ogLocale as a constant, the canonical, and the language territory default', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogType', 'website')
        ->assertJsonPath('props.seo.ogUrl', url($page->pathFor('en')))
        ->assertJsonPath('props.seo.ogLocale', 'en_US');
});

it('publishes the og_locale an editor set, and no og:locale when it is blank', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    Language::query()->where('locale', 'en')->first()->update(['og_locale' => 'en_GB']);
    $this->get('/about', ['X-Inertia' => 'true'])->assertJsonPath('props.seo.ogLocale', 'en_GB');

    Language::query()->where('locale', 'en')->first()->update(['og_locale' => null]);
    $this->get('/about', ['X-Inertia' => 'true'])->assertJsonPath('props.seo.ogLocale', null);
});

it('lists ogLocaleAlternates as the other locales the page has a slug in, excluding the current one', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogLocaleAlternates', ['uk_UA']);

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogLocaleAlternates', ['en_US']);
});

it('sets twitterCard to summary_large_image only when an ogImage resolves', function (): void {
    // beforeEach seeds a global seo.default_og_image fallback; forget it here
    // so the "no image resolves" branch is actually reachable.
    app(Settings::class)->forget('seo.default_og_image');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'No image']]);

    $this->get('/no-image', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogImage', null)
        ->assertJsonPath('props.seo.twitterCard', 'summary');

    $withImage = media('with-image.jpg');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'With image'],
        'image' => (string) $withImage->getKey(),
    ]);

    $this->get('/with-image', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.twitterCard', 'summary_large_image');
});

it('excludes a locale from alternates when the page has no slug there, and nulls xDefault without a default-locale slug', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
    ]);

    // Both locales have a slug: full alternates list, xDefault present (en is default).
    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(2, 'props.seo.alternates')
        ->assertJsonPath('props.seo.xDefault', url($page->pathFor('en')));

    // Remove the default-locale (en) slug: alternates excludes it, xDefault is null.
    $page->slugs()->where('locale', 'en')->delete();

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.seo.alternates')
        ->assertJsonPath('props.seo.alternates.0.locale', 'uk')
        ->assertJsonPath('props.seo.xDefault', null);
});

it('resolves favicon from site.favicon, null when unset', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.favicon', null);

    $favicon = media('favicon.png');
    app(Settings::class)->set('site.favicon', (string) $favicon->getKey());

    // Root-relative, unlike og:image and the identity logo: a favicon is only
    // ever a <link> the browser resolves against the page it is already
    // showing, so it has no reader that needs the full address (see
    // AbsoluteUrl).
    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.favicon', Storage::disk('public')->url('media/favicon.png'));
});

it('emits alternates/xDefault even for a noindex page', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'is_indexable' => false,
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.noindex', true)
        ->assertJsonCount(2, 'props.seo.alternates')
        ->assertJsonPath('props.seo.xDefault', fn (string $url): bool => $url !== '');
});

it('resolves translatable seo fields per locale from a locale-map seo bag', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'seo' => [
            'title' => ['en' => 'About EN', 'uk' => 'Pro EN'],
            'description' => ['en' => 'Desc EN', 'uk' => 'Desc UK'],
            'og_title' => ['en' => 'OG EN', 'uk' => 'OG UK'],
            'og_description' => ['en' => 'OGD EN', 'uk' => 'OGD UK'],
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About EN — Acme')
        ->assertJsonPath('props.seo.description', 'Desc EN')
        ->assertJsonPath('props.seo.ogTitle', 'OG EN')
        ->assertJsonPath('props.seo.ogDescription', 'OGD EN');

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Pro EN — Acme')
        ->assertJsonPath('props.seo.description', 'Desc UK')
        ->assertJsonPath('props.seo.ogTitle', 'OG UK')
        ->assertJsonPath('props.seo.ogDescription', 'OGD UK');
});

it('resolves a legacy scalar-shaped seo bag identically on the default AND a non-default locale', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        // Pre-translatable shape: plain strings, not locale maps — written
        // before seo_title/seo_description became translatable.
        'seo' => ['title' => 'Legacy title', 'description' => 'Legacy description'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Legacy title — Acme')
        ->assertJsonPath('props.seo.description', 'Legacy description');

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Legacy title — Acme')
        ->assertJsonPath('props.seo.description', 'Legacy description');
});

it('falls the identity name back to site.name when seo.identity.name is empty', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.identity.type', 'organization');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $jsonLd = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->json('props.seo.jsonLd');
    $identity = collect($jsonLd)->firstWhere('@type', 'Organization');

    expect($identity)->not->toBeNull()
        ->and($identity['name'])->toBe('Acme Co');
});

it('emits no identity object when both seo.identity.name and site.name are empty', function (): void {
    app(Settings::class)->set('seo.identity.type', 'organization');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $jsonLd = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->json('props.seo.jsonLd');

    expect(collect($jsonLd)->firstWhere('@type', 'Organization'))->toBeNull();
});

it('filters blank entries out of the contacts profile links', function (): void {
    app(Settings::class)->set('seo.identity.type', 'organization');
    app(Settings::class)->set('seo.identity.name', 'Acme Inc');
    app(Settings::class)->set('site.contacts.socials', [
        ['label' => 'X', 'href' => 'https://twitter.com/acme'], ['label' => 'B', 'href' => ''], ['label' => 'GH', 'href' => 'https://github.com/acme'],
    ]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $jsonLd = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->json('props.seo.jsonLd');
    $identity = collect($jsonLd)->firstWhere('@type', 'Organization');

    expect($identity['sameAs'])->toBe(['https://twitter.com/acme', 'https://github.com/acme']);
});

it('resolves seo.identity.logo to a public url on the Organization object', function (): void {
    $logo = media('org-logo.png');
    app(Settings::class)->set('seo.identity.type', 'organization');
    app(Settings::class)->set('seo.identity.name', 'Acme Inc');
    app(Settings::class)->set('seo.identity.logo', (string) $logo->getKey());
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $jsonLd = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->json('props.seo.jsonLd');
    $identity = collect($jsonLd)->firstWhere('@type', 'Organization');

    expect($identity['logo'])->toBe(url(Storage::disk('public')->url('media/org-logo.png')));
});

it('falls the identity logo back to site.logo when seo.identity.logo is empty', function (): void {
    $siteLogo = media('site-logo.png');
    app(Settings::class)->set('seo.identity.type', 'organization');
    app(Settings::class)->set('seo.identity.name', 'Acme Inc');
    app(Settings::class)->set('site.logo', (string) $siteLogo->getKey());
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $jsonLd = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->json('props.seo.jsonLd');
    $identity = collect($jsonLd)->firstWhere('@type', 'Organization');

    expect($identity['logo'])->toBe(url(Storage::disk('public')->url('media/site-logo.png')));
});

it('prefers a saved seo.identity.logo over site.logo when both are set', function (): void {
    $identityLogo = media('org-logo.png');
    $siteLogo = media('site-logo.png');
    app(Settings::class)->set('seo.identity.type', 'organization');
    app(Settings::class)->set('seo.identity.name', 'Acme Inc');
    app(Settings::class)->set('seo.identity.logo', (string) $identityLogo->getKey());
    app(Settings::class)->set('site.logo', (string) $siteLogo->getKey());
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $jsonLd = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->json('props.seo.jsonLd');
    $identity = collect($jsonLd)->firstWhere('@type', 'Organization');

    expect($identity['logo'])->toBe(url(Storage::disk('public')->url('media/org-logo.png')));
});

it('resolves seo correctly after restoring a revision snapshot that carries a legacy scalar-shaped seo payload', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'seo' => ['title' => 'Current title'],
    ]);

    $revision = PageRevision::query()->create([
        'page_id' => $page->id,
        'kind' => 'save',
        // Snapshot frozen before title became translatable — a plain string.
        'payload' => ['seo' => ['title' => 'Snapshot title']],
        'created_at' => now(),
    ]);

    app(PageRevisionWriter::class)->restore($page, $revision);
    $page->refresh();

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Snapshot title — Acme');

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Snapshot title — Acme');
});

it('persists and resolves the canonical override per locale, winning over the computed URL', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'seo' => ['canonical' => ['en' => 'https://example.com/custom-en']],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.canonical', 'https://example.com/custom-en')
        ->assertJsonPath('props.seo.ogUrl', 'https://example.com/custom-en');

    // No uk override set — falls back to the computed URL for that locale.
    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.canonical', url('/uk/' . $page->pathFor('uk')));
});

it('lets an explicit seo.twitter_card choice win over the auto-derived value', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    app(Settings::class)->set('seo.twitter_card', 'summary');

    // beforeEach seeds seo.default_og_image, so auto-derivation would pick
    // summary_large_image here — proving the explicit choice actually wins.
    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.twitterCard', 'summary');
});

it('sets noindex on every page when seo.indexing_enabled is false, regardless of is_indexable', function (): void {
    app(Settings::class)->set('seo.indexing_enabled', false);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'is_indexable' => true]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.noindex', true);
});

it('resolves seo.default_description per locale from a locale-map setting', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Settings::class)->set('seo.default_description', ['en' => 'Default EN', 'uk' => 'Default UK']);
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About', 'uk' => 'Pro nas']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'Default EN');

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'Default UK');
});

it('still resolves a legacy scalar seo.default_description on a non-default locale', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    // beforeEach already sets this as a plain string ('Global description').
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About', 'uk' => 'Pro nas']]);

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'Global description');
});

it('treats a literally-stored JSON null for seo.indexing_enabled as indexing enabled', function (): void {
    // Settings::get() only applies its $default when the KEY is absent, not
    // when it decodes to null — set() with a literal null value simulates a
    // row whose stored JSON is the string "null".
    app(Settings::class)->set('seo.indexing_enabled', null);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'is_indexable' => true]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.noindex', false);
});

it('falls back to the default locale for every page text left blank in the requested one', function (): void {
    // Page::getTranslation() falls back to app.fallback_locale, which a host need not align with the CMS default language.
    config(['app.fallback_locale' => 'de']);
    Language::factory()->create(['locale' => 'uk']);
    $parent = Page::factory()->create([
        'type' => 'category',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Company', 'uk' => '  '],
    ]);
    $page = Page::factory()->create([
        'type' => 'article',
        'status' => PageStatus::Published,
        'parent_id' => $parent->id,
        'name' => ['en' => 'About', 'uk' => ''],
        'title' => ['en' => 'About Page', 'uk' => ''],
        'heading' => ['en' => 'Hello', 'uk' => ''],
        'summary' => ['en' => 'Who we are.', 'uk' => ' '],
        'seo' => [],
    ]);

    $response = $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])->assertOk();

    $response->assertJsonPath('props.page.name', 'About')
        ->assertJsonPath('props.page.title', 'About Page')
        ->assertJsonPath('props.page.heading', 'Hello')
        ->assertJsonPath('props.page.summary', 'Who we are.')
        ->assertJsonPath('props.seo.title', 'About Page — Acme')
        ->assertJsonPath('props.seo.description', 'Who we are.');
    expect(array_column($response->json('props.breadcrumbs'), 'label'))->toContain('Company');
});
