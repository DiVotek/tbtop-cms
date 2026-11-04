<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Tests\Fixtures\ApiUser;
use Tbtop\Cms\Tests\Fixtures\PosterArticleType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PageTypeRegistry::extend(fn (): array => [PosterArticleType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    $this->user = ApiUser::create(['name' => 'Publisher', 'email' => 'social@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $this->user->id]);
    $this->category = Page::factory()->category()->create(['name' => ['en' => 'News'], 'status' => PageStatus::Published]);
    $this->token = issueApiToken($this->category->id);
});

function posterArticle(Page $category, array $attributes = []): Page
{
    return Page::factory()->article()->create([
        'parent_id' => $category->id,
        'subtype' => 'poster',
        'name' => ['en' => 'Winter tyres'],
        ...$attributes,
    ]);
}

function libraryImage(): Media
{
    return Media::query()->create(['name' => 'a.png', 'disk' => 'public', 'path' => 'a.png', 'mime' => 'image/png', 'size' => 1000]);
}

it('maps a type\'s fields once for a whole page of results', function (): void {
    $poster = libraryImage();
    foreach (['Winter tyres', 'Summer tyres', 'All-season tyres'] as $name) {
        posterArticle($this->category, ['name' => ['en' => $name], 'fields' => ['poster' => (string) $poster->id]]);
    }
    PosterArticleType::$fieldsBuilt = 0;

    $response = $this->withToken($this->token)->getJson('/api/cms/v1/pages?type=article')->assertOk();

    expect($response->json('data'))->toHaveCount(3)
        ->and($response->json('data.*.fields.poster'))->toBe([$poster->id, $poster->id, $poster->id])
        ->and(PosterArticleType::$fieldsBuilt)->toBe(1);
});

it('schedules a publish for a future date', function (): void {
    $page = posterArticle($this->category);
    $at = Carbon::now()->addDays(3)->startOfMinute();

    $this->withToken($this->token)
        ->postJson("/api/cms/v1/pages/{$page->id}/publish", ['published_at' => $at->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('status', 'published')
        ->assertJsonPath('display_state', 'scheduled')
        ->assertJsonPath('published_at', $at->toIso8601String());

    expect($page->refresh()->published_at->equalTo($at))->toBeTrue()
        ->and($page->isLive())->toBeFalse();
});

it('refuses to publish a page whose take-down date has passed, and a page already published', function (): void {
    $expired = posterArticle($this->category, ['unpublish_at' => Carbon::now()->subDay()]);
    $live = posterArticle($this->category, ['status' => PageStatus::Published, 'published_at' => Carbon::now()->subWeek()]);

    $this->withToken($this->token)
        ->postJson("/api/cms/v1/pages/{$expired->id}/publish")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['unpublish_at']);
    $this->withToken($this->token)->postJson("/api/cms/v1/pages/{$live->id}/publish")->assertConflict();

    expect($expired->refresh()->status)->toBe(PageStatus::Draft);
});

it('moves a published page to its new slug and leaves a redirect from the old one', function (): void {
    $page = posterArticle($this->category, ['status' => PageStatus::Published, 'published_at' => Carbon::now()->subDay()]);
    $old = $page->refresh()->pathFor('en');

    $this->withToken($this->token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['translations' => ['en' => ['slug' => 'winter-tyres-2026']]])
        ->assertOk()
        ->assertJsonPath('translations.en.slug', 'winter-tyres-2026');

    $new = $page->refresh()->pathFor('en');
    expect($new)->toEndWith('winter-tyres-2026')->not->toBe($old)
        ->and(Redirect::query()->where('from_path', $old)->where('to_path', $new)->exists())->toBeTrue();
});

it('holds type fields to their shape', function (array $fields, string $error): void {
    $page = posterArticle($this->category);

    $this->withToken($this->token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['fields' => $fields])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);
})->with([
    'a select value outside its options' => [['tone' => 'shrill'], 'fields.tone'],
    'a media id not in the library' => [['poster' => 999999], 'fields.poster'],
    'a single media where a list is due' => [['gallery' => 5], 'fields.gallery'],
    'a repeater row with a key it does not take' => [['facts' => [['label' => 'Grip', 'note' => 'x']]], 'fields.facts.0'],
    'a repeater that is not a list of rows' => [['facts' => 'Grip'], 'fields.facts'],
    'a field the type does not declare' => [['colour' => 'red'], 'fields'],
]);

it('stores type fields that keep to their shape', function (): void {
    $page = posterArticle($this->category);
    $image = libraryImage();

    $this->withToken($this->token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['fields' => [
            'tone' => 'calm',
            'poster' => $image->id,
            'gallery' => [$image->id],
            'facts' => [['label' => 'Grip'], ['label' => 'Noise']],
        ]])
        ->assertOk()
        ->assertJsonPath('fields.tone', 'calm')
        ->assertJsonPath('fields.poster', $image->id)
        ->assertJsonPath('fields.gallery', [$image->id])
        ->assertJsonPath('fields.facts.1.label', 'Noise');
});

it('refuses HTML a richtext would not keep', function (string $html): void {
    $page = posterArticle($this->category);

    $this->withToken($this->token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['translations' => ['en' => ['body' => $html]], 'fields' => ['aside' => $html]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['translations.en.body', 'fields.aside']);

    expect($page->refresh()->getTranslation('body', 'en', false))->toBeEmpty();
})->with([
    'a tag outside the list' => ['<p>Hi</p><script>alert(1)</script>'],
    'an attribute on a tag' => ['<p class="lead">Hi</p>'],
    'a javascript: link' => ['<p><a href="javascript:alert(1)">Hi</a></p>'],
    'a protocol-relative link' => ['<p><a href="//evil.example">Hi</a></p>'],
    'markup that lands in head' => ['<title>Hi</title><p>Hi</p>'],
]);

it('stores HTML within the allowed tags', function (): void {
    $page = posterArticle($this->category);
    $html = '<h2>Grip</h2><p>Read <a href="https://example.com/tyres">the <strong>guide</strong></a>.</p><ul><li>One</li></ul>';

    $body = $this->withToken($this->token)
        ->patchJson("/api/cms/v1/pages/{$page->id}", ['translations' => ['en' => ['body' => $html]]])
        ->assertOk()
        ->json('translations.en.body');

    expect($body)->toContain('<h2>Grip</h2>')->toContain('href="https://example.com/tyres"')->toContain('<strong>guide</strong>');
});

it('answers a refused media import with the admin\'s reason', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/cms/v1/media', ['url' => 'http://127.0.0.1/poster.png'])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('tbtop-admin::admin.media.errors.blocked_url'));

    expect(Media::query()->count())->toBe(0);
});

it('issues no token for an id that is not a category', function (): void {
    $article = posterArticle($this->category);
    $before = PersonalAccessToken::query()->count();

    $exit = Artisan::call('cms:api-token', ['user' => 'social@example.com', '--parents' => "{$this->category->id},{$article->id},abc"]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain((string) $article->id)->toContain('abc')
        ->and(PersonalAccessToken::query()->count())->toBe($before);
});
