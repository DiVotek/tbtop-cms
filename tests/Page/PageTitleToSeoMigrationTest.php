<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk', 'sort_order' => 1]);
});

function pageTitleToSeoMigration(): Migration
{
    return require dirname(__DIR__, 2) . '/database/migrations/2026_10_26_000000_copy_page_title_to_seo_title.php';
}

/** @param  array<string, mixed>  $attributes */
function legacyTitledPage(array $attributes): Page
{
    return Page::createQuietly(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Name', 'uk' => 'Назва'], ...$attributes]);
}

it('copies each locale\'s title into an empty seo.title and leaves filled ones, trashed pages included', function (): void {
    $mixed = legacyTitledPage([
        'title' => ['en' => 'Shop title', 'uk' => 'Магазин'],
        'seo' => ['title' => ['en' => 'Hand-written meta', 'uk' => ''], 'description' => ['en' => 'Kept']],
    ]);
    $noSeo = legacyTitledPage(['title' => ['en' => 'About title', 'uk' => '  ']]);
    $legacyScalar = legacyTitledPage(['title' => ['en' => 'Ignored'], 'seo' => ['title' => 'Legacy meta']]);
    $untitled = legacyTitledPage(['title' => ['en' => ''], 'seo' => ['description' => ['en' => 'Only a description']]]);
    $trashed = legacyTitledPage(['title' => ['en' => 'Old title']]);
    $trashed->delete();

    $migration = pageTitleToSeoMigration();
    $migration->up();
    $migration->up();

    expect($mixed->refresh()->seo)->toBe([
        'title' => ['en' => 'Hand-written meta', 'uk' => 'Магазин'],
        'description' => ['en' => 'Kept'],
    ])
        ->and($mixed->getTranslations('title'))->toBe(['en' => 'Shop title', 'uk' => 'Магазин'])
        ->and($noSeo->refresh()->seo)->toBe(['title' => ['en' => 'About title']])
        ->and($legacyScalar->refresh()->seo)->toBe(['title' => 'Legacy meta'])
        ->and($untitled->refresh()->seo)->toBe(['description' => ['en' => 'Only a description']])
        ->and(Page::withTrashed()->findOrFail($trashed->id)->seo)->toBe(['title' => ['en' => 'Old title']]);
});

it('renders the same <title> in every locale after the copy', function (): void {
    app(Settings::class)->set('seo.title_template', '{title} {sep} {site_name}');
    app(Settings::class)->set('site.name', 'Acme');
    // uk has a name but no title of its own: its <title> is the uk name, not
    // the default language's title, and the copy must not change that.
    $page = legacyTitledPage(['title' => ['en' => 'Shop title', 'uk' => '']]);
    $render = static fn (string $locale): string => app(SeoResolver::class)->resolve($page->refresh(), $locale)['title'];

    pageTitleToSeoMigration()->up();

    expect($render('en'))->toBe('Shop title — Acme')
        ->and($render('uk'))->toBe('Назва — Acme')
        ->and($page->seo)->toBe(['title' => ['en' => 'Shop title']]);
});
