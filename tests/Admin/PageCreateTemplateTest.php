<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\LandingPageType;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    PageTypeRegistry::extend(fn (): array => [LandingPageType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    test()->actingAs(PageEditHelpers::admin(), 'web');
});

function createPageFromModal(string $type, string $name): Page
{
    test()->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => ['form' => ['name' => ['en' => $name], 'title' => ['en' => ''], 'slug' => ['en' => Str::slug($name)], 'type' => $type, 'parent_id' => 'none']],
    ])->assertOk();

    return Page::query()->where('type', $type)->latest('id')->firstOrFail();
}

it('starts a page created in the admin with its type\'s template() blocks', function (): void {
    $content = createPageFromModal('landing', 'Spring sale')->content;

    expect(array_column($content, 'kind'))->toBe(['Hero', 'Faq'])
        ->and(array_column($content, 'sort'))->toBe([0, 1])
        ->and(array_column($content, 'active'))->toBe([true, false])
        ->and($content[0]['data'])->toBe(['title' => ['en' => 'Welcome']])
        ->and($content[1]['data'])->toBe([])
        ->and($content[0]['id'])->toBeString()->not->toBe('')
        ->and($content[0]['id'])->not->toBe($content[1]['id']);
});

it('keeps the template blocks through the first save in the editor', function (): void {
    $page = createPageFromModal('landing', 'Spring sale');
    $heroId = $page->content[0]['id'];

    test()->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(),
        'type' => 'landing',
        'lock_version' => $page->lock_version,
        "block_{$heroId}_title" => ['en' => 'Spring sale is on'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $content = $page->refresh()->content;

    expect(array_column($content, 'kind'))->toBe(['Hero', 'Faq'])
        ->and($content[0]['data']['title'])->toBe(['en' => 'Spring sale is on']);
});

it('starts a page of a type without a template() empty', function (): void {
    expect(createPageFromModal('page', 'About'))->content->toBe([]);
});
