<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Api\ApiWiring;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\ApiUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    $this->user = ApiUser::create(['name' => 'Publisher', 'email' => 'social@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $this->user->id]);
    $this->category = Page::factory()->category()->create(['name' => ['en' => 'News'], 'status' => PageStatus::Published]);
});

it('keeps the fields an edit does not name', function (): void {
    $token = issueApiToken($this->category->id);
    $page = Page::factory()->article()->create([
        'parent_id' => $this->category->id,
        'status' => PageStatus::Published,
        'name' => ['en' => 'Winter tyres'],
        'summary' => ['en' => 'When to change them'],
        'is_indexable' => false,
        'seo' => ['title' => ['en' => 'Winter tyres guide'], 'description' => ['en' => 'How to pick winter tyres']],
    ]);

    $this->withToken($token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['translations' => ['en' => ['name' => 'Winter tyres, 2026']]])
        ->assertOk()
        ->assertJsonPath('translations.en.name', 'Winter tyres, 2026')
        ->assertJsonPath('translations.en.seo.title', 'Winter tyres guide');

    $page->refresh();
    expect($page->getTranslation('name', 'en'))->toBe('Winter tyres, 2026')
        ->and($page->getTranslation('summary', 'en'))->toBe('When to change them')
        ->and($page->seo['title'])->toBe(['en' => 'Winter tyres guide'])
        ->and($page->seo['description'])->toBe(['en' => 'How to pick winter tyres'])
        ->and($page->status)->toBe(PageStatus::Published)
        ->and($page->is_indexable)->toBeFalse();
});

it('refuses a token without the ability the endpoint needs', function (): void {
    $token = issueApiToken($this->category->id);
    $record = PersonalAccessToken::findToken($token);
    $record->forceFill(['abilities' => array_values(array_diff($record->abilities, ['pages:write']))])->save();

    $this->withToken($token)
        ->postJson('/api/cms/v1/pages', ['parent_id' => $this->category->id, 'translations' => ['en' => ['name' => 'Winter tyres']]])
        ->assertForbidden();
    $this->withToken($token)->getJson("/api/cms/v1/pages/{$this->category->id}")->assertOk();

    expect(Page::query()->where('parent_id', $this->category->id)->exists())->toBeFalse();
});

it('reaches only the token\'s categories and their articles', function (): void {
    $other = Page::factory()->category()->create(['name' => ['en' => 'Cases']]);
    $home = Page::factory()->create(['is_system' => true, 'name' => ['en' => 'Home']]);
    $subCategory = Page::factory()->category()->create(['parent_id' => $this->category->id, 'name' => ['en' => 'Winter']]);
    $token = issueApiToken($this->category->id);

    $this->withToken($token)->getJson("/api/cms/v1/pages/{$subCategory->id}")->assertForbidden();
    $this->withToken($token)
        ->patchJson("/api/cms/v1/pages/{$subCategory->id}", ['translations' => ['en' => ['name' => 'Renamed']]])
        ->assertForbidden();
    expect($subCategory->refresh()->getTranslation('name', 'en'))->not->toBe('Renamed');

    $this->withToken($token)->getJson("/api/cms/v1/pages/{$home->id}")->assertForbidden();
    $this->withToken($token)->getJson("/api/cms/v1/pages/{$other->id}")->assertForbidden();
    $this->withToken($token)
        ->postJson('/api/cms/v1/pages', ['parent_id' => $other->id, 'translations' => ['en' => ['name' => 'Elsewhere']]])
        ->assertForbidden();
    $this->withToken($token)
        ->postJson('/api/cms/v1/pages', ['parent_id' => 999999, 'translations' => ['en' => ['name' => 'Nowhere']]])
        ->assertForbidden();
    expect(Page::query()->where('parent_id', $other->id)->exists())->toBeFalse();

    $created = $this->withToken($token)
        ->postJson('/api/cms/v1/pages', ['parent_id' => $this->category->id, 'translations' => ['en' => ['name' => 'Winter tyres']]])
        ->assertCreated()
        ->assertJsonPath('type', 'article')
        ->assertJsonPath('status', 'draft')
        ->json('id');

    $this->withToken($token)->getJson("/api/cms/v1/pages/{$created}")->assertOk()->assertJsonPath('parent_id', $this->category->id);
    expect($this->withToken($token)->getJson('/api/cms/v1/pages')->assertOk()->json('data.*.id'))
        ->toEqualCanonicalizing([$this->category->id, $created]);
});

it('refuses to publish a page missing its name in a locale the site shows', function (): void {
    Language::factory()->create(['locale' => 'uk', 'is_frontend_active' => true]);
    $token = issueApiToken($this->category->id);
    $page = Page::factory()->article()->create([
        'parent_id' => $this->category->id,
        'name' => ['en' => 'Winter tyres'],
        'seo' => ['title' => ['en' => 'Winter tyres', 'uk' => 'Зимові шини']],
    ]);

    $this->withToken($token)
        ->postJson("/api/cms/v1/pages/{$page->id}/publish")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['translations.uk.name']);

    expect($page->refresh()->status)->toBe(PageStatus::Draft);
});

it('limits how often one token may call the API', function (): void {
    // The routes read the limit when they register, so register them again under the host's limit.
    config(['tbtop-cms.api.throttle' => '2,1']);
    ApiWiring::wire();
    app('router')->getRoutes()->refreshNameLookups();
    $token = issueApiToken($this->category->id);

    $this->withToken($token)->getJson('/api/cms/v1/locales')->assertOk();
    $this->withToken($token)->getJson('/api/cms/v1/locales')->assertOk();
    $this->withToken($token)->getJson('/api/cms/v1/locales')->assertTooManyRequests();
});
