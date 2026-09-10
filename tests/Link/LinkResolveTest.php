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
        ->toBe(['href' => '/' . $page->pathFor('en'), 'type' => 'page']);
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
        ->toBe(['href' => 'https://example.com/a?b=c', 'type' => 'external']);
});

it('resolves a messenger deep link as stored', function (): void {
    expect(linkCtx()->link(['type' => 'external', 'value' => 'viber://chat?number=%2B380441234567'])['href'])
        ->toBe('viber://chat?number=%2B380441234567');
});

it('prefixes an email with mailto:', function (): void {
    expect(linkCtx()->link(['type' => 'email', 'value' => 'hi@example.com']))
        ->toBe(['href' => 'mailto:hi@example.com', 'type' => 'email']);
});

it('normalizes a formatted phone number into tel:', function (): void {
    expect(linkCtx()->link(['type' => 'phone', 'value' => '+380 (44) 123-45-67']))
        ->toBe(['href' => 'tel:+380441234567', 'type' => 'phone']);
});

it('keeps a phone number without a leading plus unprefixed', function (): void {
    expect(linkCtx()->link(['type' => 'phone', 'value' => '044 123 45 67'])['href'])->toBe('tel:0441234567');
});

it('resolves null for a typed link with no value', function (): void {
    expect(linkCtx()->link(['type' => 'email', 'value' => null]))->toBeNull();
});
