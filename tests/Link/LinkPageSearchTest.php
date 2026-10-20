<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
});

function pageSearchAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

function pageSearch(array $body): TestResponse
{
    return test()->actingAs(pageSearchAdmin(), 'web')->postJson('/admin/link/page-search', $body);
}

it('lists pages for an empty search', function (): void {
    Page::factory()->create(['name' => ['en' => 'About']]);

    $response = pageSearch(['search' => '']);

    $response->assertOk();
    expect(array_column($response->json('options'), 'label'))->toContain('About');
});

it('answers an array search with no options instead of erroring', function (): void {
    Page::factory()->create(['name' => ['en' => 'About']]);

    pageSearch(['search' => ['x']])->assertOk()->assertExactJson(['options' => []]);
});

it('answers an array value with no option instead of erroring', function (): void {
    Page::factory()->create(['name' => ['en' => 'About']]);

    pageSearch(['value' => ['1']])->assertOk()->assertExactJson(['option' => null]);
});

it('narrows results by name', function (): void {
    Page::factory()->create(['name' => ['en' => 'About']]);
    Page::factory()->create(['name' => ['en' => 'Contact']]);

    $response = pageSearch(['search' => 'cont']);

    $response->assertOk();
    expect(array_column($response->json('options'), 'label'))->toBe(['Contact']);
});

it('matches a name regardless of case, Cyrillic included', function (string $search, string $expected): void {
    Page::factory()->create(['name' => ['en' => 'About']]);
    Page::factory()->create(['name' => ['en' => 'Контакти']]);

    $response = pageSearch(['search' => $search]);

    $response->assertOk();
    expect(array_column($response->json('options'), 'label'))->toBe([$expected]);
})->with([
    'upper-case query' => ['ABOUT', 'About'],
    'lower-case Cyrillic query' => ['контакти', 'Контакти'],
    'upper-case Cyrillic query' => ['КОНТ', 'Контакти'],
]);

it('matches % and _ in the search literally', function (string $search, array $expected): void {
    Page::factory()->create(['name' => ['en' => 'About']]);
    Page::factory()->create(['name' => ['en' => '100% natural']]);
    Page::factory()->create(['name' => ['en' => 'snake_case!']]);

    $response = pageSearch(['search' => $search]);

    $response->assertOk();
    expect(array_column($response->json('options'), 'label'))->toBe($expected);
})->with([
    'percent' => ['%', ['100% natural']],
    'underscore' => ['_', ['snake_case!']],
    'escape character' => ['e!', ['snake_case!']],
]);

it('offers a draft page an editor is about to publish', function (): void {
    $draft = Page::factory()->create(['name' => ['en' => 'Unpublished'], 'status' => PageStatus::Draft]);

    $response = pageSearch(['search' => 'Unpub']);

    $response->assertOk();
    expect(array_column($response->json('options'), 'value'))->toBe([(string) $draft->id]);
});

it('caps results at the relation search cap', function (): void {
    Page::factory()->count(55)->create();

    expect(pageSearch(['search' => ''])->assertOk()->json('options'))->toHaveCount(50);
});

it('resolves a page by id', function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'About']]);

    pageSearch(['value' => (string) $page->id])
        ->assertOk()
        ->assertExactJson([
            'option' => ['value' => (string) $page->id, 'label' => 'About', 'names' => ['en' => 'About']],
        ]);
});

it('resolves a missing id to a null option', function (): void {
    pageSearch(['value' => '999999'])->assertOk()->assertExactJson(['option' => null]);
});

it('resolves a non-numeric id to a null option', function (): void {
    pageSearch(['value' => 'abc'])->assertOk()->assertExactJson(['option' => null]);
});

it('redirects an unauthenticated request to login', function (): void {
    test()->post('/admin/link/page-search', ['search' => ''])->assertRedirect();
});

it('forbids an authenticated user without a cms profile', function (): void {
    $user = AdminUser::create(['name' => 'Nobody', 'email' => 'nobody@example.com', 'password' => 'secret']);

    test()->actingAs($user, 'web')->postJson('/admin/link/page-search', ['search' => ''])->assertForbidden();
});

it('returns a per-locale name map beside the label', function (): void {
    Page::factory()->create(['name' => ['en' => 'Contact us', 'uk' => 'Звʼязатися']]);

    $option = pageSearch(['search' => 'Contact'])->json('options.0');

    expect($option['names'])->toBe(['en' => 'Contact us', 'uk' => 'Звʼязатися']);
});

it('omits locales the page has no name for', function (): void {
    Page::factory()->create(['name' => ['en' => 'Only English']]);

    $option = pageSearch(['search' => 'Only'])->json('options.0');

    expect($option['names'])->toBe(['en' => 'Only English']);
});

it('returns the name map when resolving a stored id', function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'About', 'uk' => 'Про нас']]);

    $option = pageSearch(['value' => (string) $page->id])->json('option');

    expect($option['names'])->toBe(['en' => 'About', 'uk' => 'Про нас']);
});

it('searches and labels pages by the CMS default language when it is not English', function (): void {
    Language::query()->where('locale', 'uk')->firstOrFail()->update(['is_default' => true]);
    Page::factory()->create(['name' => ['uk' => 'Контакти', 'en' => 'Contacts']]);
    Page::factory()->create(['name' => ['uk' => 'Про нас']]);

    test()->actingAs(pageSearchAdmin(), 'web');
    $found = test()->postJson('/admin/link/page-search', ['search' => 'конт']);
    $listed = test()->postJson('/admin/link/page-search', ['search' => '']);

    expect(array_column($found->json('options'), 'label'))->toBe(['Контакти'])
        ->and(array_column($listed->json('options'), 'label'))->toBe(['Контакти', 'Про нас']);
});
