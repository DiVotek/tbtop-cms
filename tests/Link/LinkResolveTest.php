<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
    app()->setLocale('en');
});

function linkCtx(string $locale = 'en'): ResolveContext
{
    return app(ResolveContext::class, ['locale' => $locale, 'fallbackLocale' => 'en']);
}

function livePage(string $name = 'Target'): Page
{
    return Page::factory()->create(['name' => ['en' => $name], 'status' => PageStatus::Published]);
}

// --- malformed input ---

it('resolves null for a null value', function (): void {
    expect(linkCtx()->link(null))->toBeNull();
});

it('resolves null for a non-array value', function (): void {
    expect(linkCtx()->link('https://example.com'))->toBeNull();
});

it('resolves null for an unknown type', function (): void {
    expect(linkCtx()->link(['type' => 'ftp', 'value' => 'ftp://x']))->toBeNull();
});

it('resolves null for a missing type key', function (): void {
    expect(linkCtx()->link(['value' => 'https://example.com']))->toBeNull();
});

// --- page ---

it('resolves a live page to its localized path', function (): void {
    $page = livePage();

    expect(linkCtx()->link(['type' => 'page', 'page' => (string) $page->id]))
        ->toBe(['href' => '/' . $page->pathFor('en'), 'type' => 'page', 'label' => 'Target']);
});

it('accepts the page id as an int as well as a string', function (): void {
    $page = livePage();

    expect(linkCtx()->link(['type' => 'page', 'page' => $page->id]))
        ->toBe(linkCtx()->link(['type' => 'page', 'page' => (string) $page->id]));
});

it('appends the anchor', function (): void {
    $page = livePage();

    expect(linkCtx()->link(['type' => 'page', 'page' => (string) $page->id, 'anchor' => 'how'])['href'])
        ->toEndWith('#how');
});

it('resolves null for a draft page', function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'Draft'], 'status' => PageStatus::Draft]);

    expect(linkCtx()->link(['type' => 'page', 'page' => (string) $page->id]))->toBeNull();
});

it('resolves null for a page scheduled to go live later', function (): void {
    $page = Page::factory()->create([
        'name' => ['en' => 'Later'],
        'status' => PageStatus::Published,
        'published_at' => now()->addDay(),
    ]);

    expect(linkCtx()->link(['type' => 'page', 'page' => (string) $page->id]))->toBeNull();
});

it('resolves null for a page already unpublished by its schedule', function (): void {
    $page = Page::factory()->create([
        'name' => ['en' => 'Expired'],
        'status' => PageStatus::Published,
        'unpublish_at' => now()->subDay(),
    ]);

    expect(linkCtx()->link(['type' => 'page', 'page' => (string) $page->id]))->toBeNull();
});

it('resolves null for a missing page id', function (): void {
    expect(linkCtx()->link(['type' => 'page', 'page' => '999999']))->toBeNull();
});

it('resolves null rather than "/" when the page has no slug for the locale', function (): void {
    $page = livePage();
    $page->slugs()->where('locale', 'uk')->delete();

    expect(linkCtx('uk')->link(['type' => 'page', 'page' => (string) $page->id]))->toBeNull();
});

// --- external / email / phone ---

it('resolves an external url as stored', function (): void {
    expect(linkCtx()->link(['type' => 'external', 'value' => 'https://example.com/a?b=c']))
        ->toBe(['href' => 'https://example.com/a?b=c', 'type' => 'external', 'label' => 'https://example.com/a?b=c']);
});

it('resolves a messenger deep link as stored', function (): void {
    expect(linkCtx()->link(['type' => 'external', 'value' => 'viber://chat?number=%2B380441234567'])['href'])
        ->toBe('viber://chat?number=%2B380441234567');
});

it('prefixes an email with mailto:', function (): void {
    expect(linkCtx()->link(['type' => 'email', 'value' => 'hi@example.com']))
        ->toBe(['href' => 'mailto:hi@example.com', 'type' => 'email', 'label' => 'hi@example.com']);
});

it('normalizes a formatted phone number into tel:', function (): void {
    expect(linkCtx()->link(['type' => 'phone', 'value' => '+380 (44) 123-45-67']))
        ->toBe(['href' => 'tel:+380441234567', 'type' => 'phone', 'label' => '+380 (44) 123-45-67']);
});

it('keeps a phone number without a leading plus unprefixed', function (): void {
    expect(linkCtx()->link(['type' => 'phone', 'value' => '044 123 45 67'])['href'])->toBe('tel:0441234567');
});

it('resolves null for a typed link with no value', function (): void {
    expect(linkCtx()->link(['type' => 'email', 'value' => null]))->toBeNull();
});

// Stored rows are not trusted: save-time validation can be bypassed by an import or a hand edit.

it('resolves null for a stored external value off the scheme allowlist', function (string $value): void {
    expect(linkCtx()->link(['type' => 'external', 'value' => $value]))->toBeNull();
})->with(['javascript:alert(1)', 'data:text/html,hi', 'file:///etc/passwd', 'ftp://example.com']);

it('resolves null for a stored email that is not an address', function (): void {
    expect(linkCtx()->link(['type' => 'email', 'value' => 'javascript:alert(1)']))->toBeNull();
});

it('resolves null for a stored phone with letters in it', function (): void {
    expect(linkCtx()->link(['type' => 'phone', 'value' => '+380 call-me']))->toBeNull();
});

it('resolves null for a stored anchor that is not a bare fragment', function (): void {
    $page = livePage();

    expect(linkCtx()->link(['type' => 'page', 'page' => (string) $page->id, 'anchor' => '#a?b']))->toBeNull();
});

// --- label ---

it('prefers the stored label over the page name', function (): void {
    $page = livePage('Target');

    expect(linkCtx()->link(['type' => 'page', 'page' => $page->id, 'label' => ['en' => 'Buy now']])['label'])
        ->toBe('Buy now');
});

it('accepts the label already collapsed to a string by the flattener', function (): void {
    $page = livePage('Target');

    expect(linkCtx()->link(['type' => 'page', 'page' => $page->id, 'label' => 'Buy now'])['label'])
        ->toBe('Buy now');
});

it('falls back to the page name in the rendered locale', function (): void {
    $page = Page::factory()->create([
        'name' => ['en' => 'Contact us', 'uk' => 'Звʼязатися'],
        'status' => PageStatus::Published,
    ]);

    expect(linkCtx('uk')->link(['type' => 'page', 'page' => $page->id])['label'])->toBe('Звʼязатися');
});

it('falls back to another locale name when the rendered locale has none', function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'Only English'], 'status' => PageStatus::Published]);

    expect(linkCtx('uk')->link(['type' => 'page', 'page' => $page->id])['label'])->toBe('Only English');
});

it('resolves null rather than an empty label for a live page with no name in any locale', function (): void {
    $page = livePage();
    $page->newQuery()->toBase()->where('id', $page->id)->update(['name' => json_encode(['en' => '', 'uk' => null])]);

    expect(linkCtx()->link(['type' => 'page', 'page' => (string) $page->id]))->toBeNull();
});

it('uses the locale entry of a label map matching the rendered locale', function (): void {
    $page = livePage('Target');
    $value = ['type' => 'page', 'page' => $page->id, 'label' => ['en' => 'Buy', 'uk' => 'Купити']];

    expect(linkCtx('uk')->link($value)['label'])->toBe('Купити');
});

it('falls back to the default locale label when the rendered locale is empty', function (): void {
    $page = livePage('Target');
    $value = ['type' => 'page', 'page' => $page->id, 'label' => ['en' => 'Buy']];

    expect(linkCtx('uk')->link($value)['label'])->toBe('Buy');
});

it('treats an empty label map as no label at all', function (): void {
    expect(linkCtx()->link(['type' => 'email', 'value' => 'hi@example.com', 'label' => []])['label'])
        ->toBe('hi@example.com');
});

it('ignores a blank label entry and falls back to the target', function (): void {
    $value = ['type' => 'external', 'value' => 'https://example.com', 'label' => ['en' => '  ']];

    expect(linkCtx()->link($value)['label'])->toBe('https://example.com');
});

it('labels an external link with its stored label', function (): void {
    $value = ['type' => 'external', 'value' => 'https://example.com', 'label' => ['en' => 'Our shop']];

    expect(linkCtx()->link($value))
        ->toBe(['href' => 'https://example.com', 'type' => 'external', 'label' => 'Our shop']);
});

it('falls back to the page name in the CMS default language before any other', function (): void {
    Language::query()->where('locale', 'uk')->firstOrFail()->update(['is_default' => true]);
    Language::factory()->create(['locale' => 'de']);
    $page = Page::factory()->create([
        'name' => ['en' => 'Contact', 'uk' => 'Контакти'],
        'status' => PageStatus::Published,
    ]);

    expect(linkCtx('de')->link(['type' => 'page', 'page' => (string) $page->id])['label'] ?? null)->toBe('Контакти');
});
