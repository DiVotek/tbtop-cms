<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Frontend\Seo\SeoAudit;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Language::factory()->default()->create(['locale' => 'en']);
});

/** @return list<array{severity: string, issue: string, page: string, locale: string, detail: string}> */
function seoAuditIssues(): array
{
    return app(SeoAudit::class)->run();
}

/** @return list<array{severity: string, issue: string, page: string, locale: string, detail: string}> */
function seoAuditIssuesFor(string $issueKey): array
{
    return array_values(array_filter(
        seoAuditIssues(),
        static fn (array $row): bool => $row['issue'] === __('tbtop-cms::seo_report.issues.' . $issueKey . '.label'),
    ));
}

it('reports missing_title as a fail when a published page has no own title in the locale', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => ''],
        'title' => ['en' => ''],
        'seo' => [],
    ]);

    $rows = seoAuditIssuesFor('missing_title');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['severity'])->toBe('fail')
        ->and($rows[0]['locale'])->toBe('en');
});

it('reports missing_description as a fail when a published page has no own summary or seo description', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About'],
        'summary' => ['en' => ''],
        'seo' => [],
    ]);

    $rows = seoAuditIssuesFor('missing_description');

    expect($rows)->toHaveCount(1)->and($rows[0]['severity'])->toBe('fail');
});

it('does not report missing_description when the page has its own summary', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About'],
        'summary' => ['en' => 'What we do.'],
        'seo' => [],
    ]);

    expect(seoAuditIssuesFor('missing_description'))->toBeEmpty();
});

it('reports duplicate_title as a warn when two published pages share the same own title in a locale', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Same Title'], 'title' => ['en' => 'Same Title']]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Same Title'], 'title' => ['en' => 'Same Title']]);

    $rows = seoAuditIssuesFor('duplicate_title');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['severity'])->toBe('warn')
        ->and($rows[0]['detail'])->toContain('Same Title');
});

it('does not report duplicate_title for pages with distinct titles', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'One'], 'title' => ['en' => 'One']]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Two'], 'title' => ['en' => 'Two']]);

    expect(seoAuditIssuesFor('duplicate_title'))->toBeEmpty();
});

it('reports missing_og_image as a warn when neither the page nor the site default has an image', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'image' => null,
        'seo' => [],
    ]);

    $rows = seoAuditIssuesFor('missing_og_image');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['severity'])->toBe('warn')
        ->and($rows[0]['locale'])->toBe('');
});

it('does not report missing_og_image when the site has a default og image configured', function (): void {
    app(Settings::class)->set('seo.default_og_image', '1');

    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'image' => null, 'seo' => []]);

    expect(seoAuditIssuesFor('missing_og_image'))->toBeEmpty();
});

it('reports noindex as a warn for a published page marked not indexable', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'is_indexable' => false,
    ]);

    $rows = seoAuditIssuesFor('noindex');

    expect($rows)->toHaveCount(1)->and($rows[0]['severity'])->toBe('warn');
});

it('does not report noindex for a draft page', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Draft,
        'name' => ['en' => 'About'],
        'is_indexable' => false,
    ]);

    expect(seoAuditIssues())->toBeEmpty();
});

it('reports missing_translation as a warn when an active frontend locale has no title and no name', function (): void {
    Language::factory()->create(['locale' => 'uk', 'is_frontend_active' => true]);

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About'],
        'seo' => [],
    ]);

    $rows = seoAuditIssuesFor('missing_translation');

    expect($rows)->toHaveCount(1)->and($rows[0]['locale'])->toBe('uk');
});

it('reports no issues for a fully optimized published page', function (): void {
    app(Settings::class)->set('seo.default_og_image', '1');

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'A well optimized page'],
        'title' => ['en' => 'A well optimized, unique title'],
        'summary' => ['en' => 'A concise, useful summary of the page content.'],
        'is_indexable' => true,
        'seo' => [],
    ]);

    expect(seoAuditIssues())->toBeEmpty();
});

it('does not include draft pages in the audit at all', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Draft,
        'name' => ['en' => ''],
        'title' => ['en' => ''],
        'summary' => ['en' => ''],
        'seo' => [],
    ]);

    expect(seoAuditIssues())->toBeEmpty();
});

it('sorts fail-severity rows before warn-severity rows', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Missing OG'],
        'title' => ['en' => 'Missing OG'],
        'summary' => ['en' => 'Has a summary.'],
        'image' => null,
        'seo' => [],
    ]);
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => ''],
        'title' => ['en' => ''],
        'seo' => [],
    ]);

    $severities = collect(seoAuditIssues())->pluck('severity')->all();
    $firstWarnIndex = array_search('warn', $severities, true);
    $lastFailIndex = 0;
    foreach ($severities as $index => $severity) {
        if ($severity === 'fail') {
            $lastFailIndex = $index;
        }
    }

    expect($firstWarnIndex)->not->toBeFalse()
        ->and($severities)->toContain('fail')
        ->and($lastFailIndex)->toBeLessThan($firstWarnIndex);
});
