<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\LocaleCatalog;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    $this->actingAs($user, 'web');
});

/** @return array<string, mixed> First node of the given kind in the page structure. */
function languagesTableNode(): array
{
    $structure = test()->get('/admin/languages', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $found = null;
    $walk = function (array $node) use (&$walk, &$found): void {
        if (($node['kind'] ?? null) === 'table') {
            $found ??= $node;

            return;
        }
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            $walk($child);
        }
    };
    $walk($structure);

    expect($found)->not->toBeNull();

    return $found;
}

/** @return list<array<string, mixed>> Flattened field/action nodes inside the create form. */
function languageCreateFormChildren(): array
{
    $headerActions = test()->get('/admin/languages', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.headerActions') ?? [];

    $action = collect($headerActions)->firstWhere('name', 'languageCreate');
    expect($action)->not->toBeNull();

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

it('carries the create action in the page header, title, and subtitle from translations', function (): void {
    $response = test()->get('/admin/languages', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::languages.title'))
        ->and($response->json('props.subtitle'))->toBe(__('tbtop-cms::languages.subtitle'));

    $headerActions = $response->json('props.headerActions') ?? [];
    $found = collect($headerActions)->firstWhere('name', 'languageCreate');

    expect($found)->not->toBeNull();
});

it('no longer carries a create action on the table itself', function (): void {
    $node = languagesTableNode();

    expect($node['options']['headerActions'] ?? null)->toBeNull();
});

it('serializes the boolean flag fields with kind boolean in the create form', function (): void {
    $byName = array_column(languageCreateFormChildren(), null, 'name');

    expect($byName['is_default']['kind'])->toBe('boolean')
        ->and($byName['is_admin_active']['kind'])->toBe('boolean')
        ->and($byName['is_frontend_active']['kind'])->toBe('boolean');
});

it('offers a searchable locale select in the create form, populated from the catalog minus taken locales', function (): void {
    $byName = array_column(languageCreateFormChildren(), null, 'name');
    $localeField = $byName['locale'];

    expect($localeField['kind'])->toBe('select')
        ->and($localeField['options']['searchable'])->toBeTrue();

    $options = $localeField['options']['options'];
    $values = array_column($options, 'value');

    expect($values)->not->toContain('en')
        ->and($values)->toContain('uk')
        ->and(collect($options)->firstWhere('value', 'uk')['label'])->toBe(LocaleCatalog::name('uk') . ' (uk)');
});

it('renders an All and a Deleted tab', function (): void {
    $node = languagesTableNode();
    $tabs = array_column($node['options']['tabs'], 'label', 'name');

    expect($tabs)->toBe([
        'all' => 'All',
        'deleted' => 'Deleted',
    ]);
});

it('projects a hidden deleted_at helper column', function (): void {
    $node = languagesTableNode();
    $cols = array_column($node['options']['columns'], null, 'name');

    expect($cols['deleted_at']['hiddenByDefault'] ?? null)->toBeTrue();
});

it('hides restore/delete-permanently on active rows and edit/delete on trashed rows via hiddenIf', function (): void {
    $node = languagesTableNode();
    $actions = array_column($node['options']['rowActions'], null, 'name');

    expect($actions['languageRestore']['meta']['hiddenIf'])->toBe(['op' => 'empty', 'field' => 'deleted_at'])
        ->and($actions['languageForceDelete']['meta']['hiddenIf'])->toBe(['op' => 'empty', 'field' => 'deleted_at'])
        ->and($actions['languageEdit']['meta']['hiddenIf'])->toBe(['op' => 'notEmpty', 'field' => 'deleted_at']);

    // Delete hides for is_default OR a trashed row — a composite `any` condition.
    $deleteHiddenIf = $actions['languageDelete']['meta']['hiddenIf'];
    expect($deleteHiddenIf['op'])->toBe('any')
        ->and($deleteHiddenIf['conds'])->toBe([
            ['op' => 'eq', 'field' => 'is_default', 'value' => true],
            ['op' => 'notEmpty', 'field' => 'deleted_at'],
        ]);
});

it('lists only trashed languages in the Deleted tab and excludes them from All', function (): void {
    // beforeEach already seeds the default 'en' language.
    $default = Language::query()->where('locale', 'en')->firstOrFail();
    $active = Language::factory()->create(['locale' => 'uk']);
    $trashed = Language::factory()->create(['locale' => 'de']);
    $trashed->delete();

    $rows = fn (string $tab): array => test()->getJson("/admin/languages/tables/languages?tab={$tab}")
        ->assertOk()
        ->json('data.data');

    expect(array_column($rows('deleted'), 'id'))->toBe([$trashed->id])
        ->and(array_column($rows('all'), 'id'))->toEqualCanonicalizing([$default->id, $active->id]);
});

it('restores a trashed language via the row action', function (): void {
    $language = Language::factory()->create(['locale' => 'de']);
    $language->delete();

    $response = test()->postJson('/admin/languages/actions/languageRestore', ['payload' => ['row' => ['id' => $language->id]]]);

    $response->assertOk();
    expect($language->fresh()->trashed())->toBeFalse();

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('notify', 'refreshTable');
});

it('force-deletes a trashed language permanently via the row action', function (): void {
    $language = Language::factory()->create(['locale' => 'de']);
    $language->delete();

    $response = test()->postJson('/admin/languages/actions/languageForceDelete', ['payload' => ['row' => ['id' => $language->id]]]);

    $response->assertOk();
    expect(Language::withTrashed()->find($language->id))->toBeNull();

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('notify', 'refreshTable');
});

it('blocks force-delete permanently for the default language via the row action', function (): void {
    // The default language can never be soft-deleted in the first place
    // (deleting() blocks it before it becomes trashed) — force-delete is
    // called directly on the active row action, exercising the same guard.
    $language = Language::factory()->default()->create(['locale' => 'de']);

    $response = test()->postJson('/admin/languages/actions/languageForceDelete', ['payload' => ['row' => ['id' => $language->id]]]);

    $response->assertOk();
    expect(Language::withTrashed()->find($language->id))->not->toBeNull();

    $notify = collect($response->json('effects'))->firstWhere('kind', 'notify');
    expect($notify['level'] ?? null)->toBe('error');
});

it('lets a trashed locale be recreated via the create form, reviving the row instead of erroring', function (): void {
    // Documents the existing unique-locale trap handling in LanguageForm::save():
    // the locale column is globally unique (including trashed rows), so
    // re-picking a trashed locale must restore that row rather than attempt
    // a duplicate insert or block the admin with an error.
    $original = Language::factory()->create(['locale' => 'de']);
    $original->delete();

    $response = test()->postJson('/admin/languages/actions/languageCreateSave', [
        'payload' => [
            'form' => [
                'locale' => 'de',
                'is_default' => false,
                'is_admin_active' => true,
                'is_frontend_active' => true,
                'sort_order' => 5,
            ],
        ],
    ]);

    $response->assertOk();
    expect(Language::query()->where('locale', 'de')->count())->toBe(1)
        ->and(Language::withTrashed()->find($original->id)->trashed())->toBeFalse();
});
