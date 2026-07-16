<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Enums\CmsRole;
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

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);
    $this->actingAs($user, 'web');
});

/** @return array<string, mixed> */
function pageCreateAction(): array
{
    $headerActions = test()->get('/admin/pages', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.headerActions') ?? [];

    $found = collect($headerActions)->firstWhere('name', 'pageCreate');

    expect($found)->not->toBeNull();

    return $found;
}

/** @return list<array<string, mixed>> Flattened field/action nodes inside the create form. */
function pageCreateFormChildren(): array
{
    $action = pageCreateAction();
    $body = $action['options']['spec']['body'] ?? $action['spec']['body'] ?? null;
    expect($body)->not->toBeNull();

    $children = [];
    $collect = function (array $node) use (&$collect, &$children): void {
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $children[] = $child;
            $collect($child);
        }
    };
    $collect($body);

    return $children;
}

it('composes the create form with name/title/slug in Content and type/parent in Settings', function (): void {
    $children = pageCreateFormChildren();
    $names = array_column($children, 'name');

    expect($names)->toContain('name', 'title', 'slug', 'type', 'parent_id')
        ->and($names)->not->toContain('heading', 'summary', 'is_indexable', 'sort_order', 'content', 'status');

    $byName = array_column($children, null, 'name');
    expect($byName['slug']['kind'])->toBe('slug')
        ->and($byName['slug']['options']['fromField'] ?? null)->toBe('name');
});

it('hides parent_id unless type is category or article', function (): void {
    $byName = array_column(pageCreateFormChildren(), null, 'name');
    $parentField = $byName['parent_id'];

    $hiddenIf = $parentField['meta']['hiddenIf'] ?? $parentField['options']['meta']['hiddenIf'] ?? null;

    expect($hiddenIf)->not->toBeNull()
        ->and($hiddenIf['op'])->toBe('notIn')
        ->and($hiddenIf['field'])->toBe('type')
        ->and($hiddenIf['value'])->toBe(['category', 'article']);
});

it('creates a page as draft when the form sends no status (status is edit-only)', function (): void {
    $this->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => [
            'form' => [
                'name' => ['en' => 'Statusless'],
                'title' => ['en' => ''],
                'slug' => ['en' => ''],
                'type' => 'page',
                'parent_id' => 'none',
            ],
        ],
    ])->assertOk();

    $page = Page::query()->where('type', 'page')->firstOrFail();
    expect($page->status)->toBe(PageStatus::Draft);
});

it('creates a page using the submitted per-locale slug instead of deriving it from the name', function (): void {
    $response = $this->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => [
            'form' => [
                'name' => ['en' => 'About Us'],
                'title' => ['en' => ''],
                'slug' => ['en' => 'custom-about'],
                'type' => 'page',
                'parent_id' => 'none',
            ],
        ],
    ]);

    $response->assertOk();

    $page = Page::query()->where('type', 'page')->firstOrFail();
    expect($page->pathFor('en'))->toBe('custom-about');
});

it('falls back to name-derived slug generation when the slug field is left empty', function (): void {
    $this->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => [
            'form' => [
                'name' => ['en' => 'Fresh Page'],
                'title' => ['en' => ''],
                'slug' => ['en' => ''],
                'type' => 'page',
                'parent_id' => 'none',
            ],
        ],
    ])->assertOk();

    $page = Page::query()->where('type', 'page')->firstOrFail();
    expect($page->pathFor('en'))->toBe('fresh-page');
});

it('falls the title back to the name per locale when title is left empty', function (): void {
    $this->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => [
            'form' => [
                'name' => ['en' => 'About Us'],
                'title' => ['en' => ''],
                'slug' => ['en' => ''],
                'type' => 'page',
                'parent_id' => 'none',
            ],
        ],
    ])->assertOk();

    $page = Page::query()->where('type', 'page')->firstOrFail();
    expect($page->getTranslation('title', 'en'))->toBe('About Us');
});

it('keeps an explicit title over the name fallback', function (): void {
    $this->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => [
            'form' => [
                'name' => ['en' => 'About Us'],
                'title' => ['en' => 'Custom Title'],
                'slug' => ['en' => ''],
                'type' => 'page',
                'parent_id' => 'none',
            ],
        ],
    ])->assertOk();

    $page = Page::query()->where('type', 'page')->firstOrFail();
    expect($page->getTranslation('title', 'en'))->toBe('Custom Title');
});

it('always creates pages as indexable, since the create form has no is_indexable field', function (): void {
    $this->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => [
            'form' => [
                'name' => ['en' => 'About Us'],
                'title' => ['en' => ''],
                'slug' => ['en' => ''],
                'type' => 'page',
                'parent_id' => 'none',
            ],
        ],
    ])->assertOk();

    $page = Page::query()->where('type', 'page')->firstOrFail();
    expect($page->is_indexable)->toBeTrue();
});

it('redirects to the new page edit screen after create', function (): void {
    $response = $this->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => [
            'form' => [
                'name' => ['en' => 'About Us'],
                'title' => ['en' => ''],
                'slug' => ['en' => ''],
                'type' => 'page',
                'parent_id' => 'none',
            ],
        ],
    ]);

    $response->assertOk();
    $page = Page::query()->where('type', 'page')->firstOrFail();

    $effects = collect($response->json('effects'));
    $redirect = $effects->firstWhere('kind', 'redirect');

    expect($redirect)->not->toBeNull()
        ->and($redirect['href'] ?? $redirect['url'] ?? null)->toEndWith("/admin/pages/{$page->id}/edit");
});
