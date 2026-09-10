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

it('narrows results by name', function (): void {
    Page::factory()->create(['name' => ['en' => 'About']]);
    Page::factory()->create(['name' => ['en' => 'Contact']]);

    $response = pageSearch(['search' => 'cont']);

    $response->assertOk();
    expect(array_column($response->json('options'), 'label'))->toBe(['Contact']);
});

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
        ->assertExactJson(['option' => ['value' => (string) $page->id, 'label' => 'About']]);
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
