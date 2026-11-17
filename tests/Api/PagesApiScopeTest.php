<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\ApiUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    $this->user = ApiUser::create(['name' => 'Publisher', 'email' => 'social@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $this->user->id]);
});

/** @param  array<string, string>  $options  `cms:api-token` options, `--parents` => '12'. */
function issueScopedToken(array $options): string
{
    expect(Artisan::call('cms:api-token', ['user' => 'social@example.com', ...$options]))->toBe(0);
    // The guard keeps the first request's user, so a second token in one test would be ignored.
    app('auth')->forgetGuards();
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    return (string) end($lines);
}

/** @return list<string> */
function storedAbilities(): array
{
    return PersonalAccessToken::query()->latest('id')->firstOrFail()->abilities;
}

it('tree grant reaches descendants but not system pages', function (): void {
    $root = Page::factory()->category()->create(['name' => ['en' => 'Services']]);
    $branch = Page::factory()->category()->create(['parent_id' => $root->id, 'name' => ['en' => 'Tyres']]);
    $grandchild = Page::factory()->article()->create(['parent_id' => $branch->id, 'name' => ['en' => 'Winter']]);
    $system = Page::factory()->category()->create(['parent_id' => $root->id, 'is_system' => true, 'name' => ['en' => 'Error']]);
    $outside = Page::factory()->article()->create(['parent_id' => Page::factory()->category()->create()->id, 'name' => ['en' => 'Elsewhere']]);
    $token = issueScopedToken(['--trees' => (string) $root->id, '--actions' => 'read,create,update', '--fields' => 'seo,text,type']);

    expect($this->withToken($token)->getJson('/api/cms/v1/pages')->assertOk()->json('data.*.id'))
        ->toEqualCanonicalizing([$root->id, $branch->id, $grandchild->id]);
    $this->withToken($token)->getJson("/api/cms/v1/pages/{$system->id}")->assertForbidden();
    $this->withToken($token)->getJson("/api/cms/v1/pages/{$outside->id}")->assertForbidden();

    $this->withToken($token)
        ->patchJson("/api/cms/v1/pages/{$grandchild->id}", ['translations' => ['en' => ['name' => 'Winter, 2026']]])
        ->assertOk();
    expect($grandchild->refresh()->getTranslation('name', 'en'))->toBe('Winter, 2026');

    foreach ([$root, $branch] as $category) {
        $this->withToken($token)
            ->patchJson("/api/cms/v1/pages/{$category->id}", ['translations' => ['en' => ['name' => 'Renamed']]])
            ->assertForbidden();
    }
    $this->withToken($token)
        ->postJson('/api/cms/v1/pages', ['parent_id' => $branch->id, 'translations' => ['en' => ['name' => 'Summer']]])
        ->assertCreated()
        ->assertJsonPath('parent_id', $branch->id);
    $this->withToken($token)
        ->postJson('/api/cms/v1/pages', ['parent_id' => $outside->id, 'translations' => ['en' => ['name' => 'Nope']]])
        ->assertForbidden();
    $this->withToken($token)
        ->postJson('/api/cms/v1/pages', ['parent_id' => $system->id, 'translations' => ['en' => ['name' => 'Under error']]])
        ->assertForbidden();
});

it('page grant reaches a system page, parent and tree never do', function (): void {
    $home = Page::factory()->create(['is_system' => true, 'name' => ['en' => 'Home']]);
    $category = Page::factory()->category()->create(['name' => ['en' => 'News']]);
    $childHome = Page::factory()->category()->create(['parent_id' => $category->id, 'is_system' => true, 'name' => ['en' => 'Hidden']]);
    $seo = ['translations' => ['en' => ['seo' => ['title' => 'Tyres in Dnipro']]]];

    $pageToken = issueScopedToken(['--pages' => (string) $home->id, '--actions' => 'read,update', '--fields' => 'seo,url']);
    $this->withToken($pageToken)->patchJson("/api/cms/v1/pages/{$home->id}", $seo)->assertOk();
    expect($home->refresh()->seo['title'])->toBe(['en' => 'Tyres in Dnipro']);
    $this->withToken($pageToken)
        ->patchJson("/api/cms/v1/pages/{$home->id}", ['translations' => ['en' => ['slug' => 'main']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('translations.en.slug');

    foreach (['--parents', '--trees'] as $option) {
        $token = issueScopedToken([$option => (string) $category->id, '--actions' => 'read,update']);
        $this->withToken($token)->patchJson("/api/cms/v1/pages/{$childHome->id}", $seo)->assertForbidden();
        $this->withToken($token)->getJson("/api/cms/v1/pages/{$childHome->id}")->assertForbidden();
    }
});

it('field groups gate writes', function (): void {
    $page = Page::factory()->article()->create([
        'parent_id' => Page::factory()->category()->create()->id,
        'status' => PageStatus::Published,
        'name' => ['en' => 'Tyres'],
        'body' => null,
        'seo' => ['title' => ['en' => 'Old title']],
    ]);
    $token = issueScopedToken(['--pages' => (string) $page->id, '--actions' => 'read,update', '--fields' => 'seo,heading']);

    $this->withToken($token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['translations' => ['en' => ['heading' => 'New H1', 'seo' => ['title' => 'New title']]]])
        ->assertOk();
    $page->refresh();
    expect($page->getTranslation('heading', 'en'))->toBe('New H1')
        ->and($page->seo['title'])->toBe(['en' => 'New title']);

    $this->withToken($token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['translations' => ['en' => ['heading' => 'Changed', 'body' => '<p>Rewritten</p>', 'slug' => 'moved']]])
        ->assertForbidden()
        ->assertJsonPath('fields', ['translations.en.body', 'translations.en.slug']);
    $this->withToken($token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['image' => null, 'fields' => ['x' => 1]])
        ->assertForbidden()
        ->assertJsonPath('fields', ['image']);
    expect($page->refresh()->getTranslation('heading', 'en'))->toBe('New H1');

    $this->withToken($token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['translations' => ['en' => ['title' => 'Unknown']]])
        ->assertUnprocessable();

    $category = Page::factory()->category()->create();
    $article = Page::factory()->article()->create(['parent_id' => $category->id]);
    $legacy = issueScopedToken(['--parents' => (string) $category->id]);
    $this->withToken($legacy)
        ->patchJson("/api/cms/v1/pages/{$article->id}", ['translations' => ['en' => ['body' => '<p>Text</p>', 'seo' => ['title' => 'T']]]])
        ->assertOk();
});

it('actions are separate, legacy write still means all', function (): void {
    $category = Page::factory()->category()->create(['name' => ['en' => 'News']]);
    $article = Page::factory()->article()->create(['parent_id' => $category->id, 'name' => ['en' => 'Tyres']]);
    $updateOnly = issueScopedToken(['--parents' => (string) $category->id, '--actions' => 'read,update']);

    $this->withToken($updateOnly)->postJson("/api/cms/v1/pages/{$article->id}/publish")->assertForbidden();
    $this->withToken($updateOnly)->deleteJson("/api/cms/v1/pages/{$article->id}")->assertForbidden();
    $this->withToken($updateOnly)->postJson('/api/cms/v1/media', ['url' => 'https://example.com/a.png'])->assertForbidden();
    $this->withToken($updateOnly)
        ->patchJson("/api/cms/v1/pages/{$article->id}", ['translations' => ['en' => ['name' => 'Tyres 2026']]])
        ->assertOk();
    expect($article->refresh()->status)->toBe(PageStatus::Draft);

    $legacy = issueScopedToken(['--parents' => (string) $category->id]);
    $this->withToken($legacy)->postJson("/api/cms/v1/pages/{$article->id}/publish")->assertOk()->assertJsonPath('status', 'published');
});

it('command refuses inconsistent grants and keeps legacy abilities by default', function (): void {
    $category = Page::factory()->category()->create(['name' => ['en' => 'News']]);
    $before = PersonalAccessToken::query()->count();
    $id = (string) $category->id;

    $refused = [
        'no scope' => ['--actions' => 'read'],
        'unknown page' => ['--pages' => '999999'],
        'unknown tree' => ['--trees' => 'abc'],
        'unknown action' => ['--parents' => $id, '--actions' => 'read,fly'],
        'unknown field group' => ['--parents' => $id, '--fields' => 'seo,colour'],
        'create without text' => ['--parents' => $id, '--actions' => 'read,create', '--fields' => 'seo'],
    ];
    foreach ($refused as $options) {
        expect(Artisan::call('cms:api-token', ['user' => 'social@example.com', ...$options]))->toBe(1);
    }
    expect(PersonalAccessToken::query()->count())->toBe($before);

    issueScopedToken(['--parents' => "{$id}"]);
    expect(storedAbilities())->toBe(['pages:read', 'pages:write', "pages:parent:{$id}", 'pages:type:article']);

    Artisan::call('cms:api-token', ['user' => 'social@example.com', '--parents' => $id, '--pages' => $id, '--actions' => 'read,update', '--fields' => 'seo,heading']);
    $output = Artisan::output();
    expect(storedAbilities())->toBe(['pages:read', 'pages:update', "pages:page:{$id}", "pages:parent:{$id}", 'pages:type:article', 'fields:seo', 'fields:heading']);
    expect($output)->toContain("pages {$id}")->toContain('actions read, update')->toContain('fields seo, heading');
});
