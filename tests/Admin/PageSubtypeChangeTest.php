<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\LegalServicePageType;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\ReviewerPageType;
use Tbtop\Cms\Tests\Fixtures\SpecialistPageType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    // Specialist's `services` accepts only article/legal_service; Reviewer's accepts any page.
    PageTypeRegistry::extend(fn (): array => [SpecialistPageType::class, ReviewerPageType::class, LegalServicePageType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    test()->actingAs(PageEditHelpers::admin(), 'web');
});

/** @param  array<string, mixed>  $attributes */
function subtypeChangeArticle(?string $subtype, string $name, array $attributes = []): Page
{
    $category = Page::query()->where('type', 'category')->first()
        ?? Page::create(['type' => 'category', 'status' => PageStatus::Published, 'name' => ['en' => 'Team']]);

    return Page::create([
        'type' => 'article', 'subtype' => $subtype, 'parent_id' => $category->id,
        'status' => PageStatus::Published, 'name' => ['en' => $name],
        ...$attributes,
    ]);
}

/**
 * The footer save buttons the client shows once the editor has put $state
 * into $page's edit form, with the confirm each one asks.
 *
 * @param  array<string, mixed>  $state
 * @return Collection<int, array{name: string, confirm: mixed}>
 */
function subtypeChangeSaves(Page $page, array $state = []): Collection
{
    $actions = [];
    collectNodesByKind(test()->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])->assertOk()->json('props.structure'), 'action', $actions);
    $data = [...PageForm::loadRow($page->id), ...$state];

    return collect($actions)
        ->filter(static fn (array $action): bool => str_starts_with((string) $action['name'], 'save') && ! str_starts_with((string) $action['name'], 'saveTop'))
        ->filter(static fn (array $action): bool => isShownFor($action, $data))
        ->map(static fn (array $action): array => ['name' => $action['name'], 'confirm' => $action['options']['confirm'] ?? null])
        ->values();
}

it('confirms a subtype pick that pages referencing this one do not accept, naming them', function (): void {
    $service = subtypeChangeArticle('legal_service', 'Divorce');
    subtypeChangeArticle('specialist', 'Anna', ['fields' => ['services' => [(string) $service->id]]]);
    subtypeChangeArticle('specialist', 'Boris', ['status' => PageStatus::Draft, 'fields' => ['services' => [(string) $service->id]]]);

    expect(subtypeChangeSaves($service)->pluck('name')->all())->toBe(['save']);

    foreach (['', 'specialist'] as $pick) {
        $shown = subtypeChangeSaves($service, ['subtype' => $pick]);

        expect($shown)->toHaveCount(1)
            ->and($shown[0]['name'])->not->toBe('save')
            ->and($shown[0]['confirm']['title'])->toBe('Change the subtype?')
            ->and($shown[0]['confirm']['description'])->toContain('(2): Anna, Boris.');
    }
});

it('does not confirm when every referencing field accepts the new subtype', function (): void {
    $service = subtypeChangeArticle('legal_service', 'Divorce');
    subtypeChangeArticle('reviewer', 'Rita', ['fields' => ['services' => [(string) $service->id]]]);

    expect(subtypeChangeSaves($service, ['subtype' => ''])->pluck('name')->all())->toBe(['save']);
});

it('leaves out referrers in the trash and fields that already reject the stored subtype', function (): void {
    $service = subtypeChangeArticle('legal_service', 'Divorce');
    $trashed = subtypeChangeArticle('specialist', 'Anna', ['fields' => ['services' => [(string) $service->id]]]);
    $trashed->delete();
    $plain = subtypeChangeArticle(null, 'Plain');
    subtypeChangeArticle('specialist', 'Boris', ['fields' => ['services' => [(string) $plain->id]]]);

    expect(subtypeChangeSaves($service, ['subtype' => ''])->pluck('name')->all())->toBe(['save'])
        ->and(subtypeChangeSaves($plain, ['subtype' => 'specialist'])->pluck('name')->all())->toBe(['save']);
});

it('lets the leaf-type flip confirm win when the same save also changes the subtype', function (): void {
    $service = subtypeChangeArticle('legal_service', 'Divorce');
    subtypeChangeArticle('specialist', 'Anna', ['fields' => ['services' => [(string) $service->id]]]);

    // The type select stays on article, so the subtype pick holds too; emptying the category flips it to a page.
    expect(subtypeChangeSaves($service, ['parent_id' => '', 'subtype' => ''])->pluck('name')->all())->toBe(['saveLeafFlip']);
});
