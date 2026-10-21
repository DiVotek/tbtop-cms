<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\RedirectForm;
use Tbtop\Cms\Admin\Pages\Support\RedirectFormOptions;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Redirect\Models\Redirect;
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
function redirectsTableNode(): array
{
    $structure = test()->get('/admin/redirects', ['X-Inertia' => 'true'])
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

it('carries the create action in the page header, title, and subtitle from translations', function (): void {
    $response = test()->get('/admin/redirects', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::redirects.title'))
        ->and($response->json('props.subtitle'))->toBe(__('tbtop-cms::redirects.subtitle'));

    $headerActions = $response->json('props.headerActions') ?? [];
    $found = collect($headerActions)->firstWhere('name', 'redirectCreate');

    expect($found)->not->toBeNull();
});

it('no longer carries a create action on the table itself', function (): void {
    $node = redirectsTableNode();

    expect($node['options']['headerActions'] ?? null)->toBeNull();
});

it('opens the edit modal on row click and hides the separate Edit button', function (): void {
    // 5.10: row click opens editing everywhere — the edit action stays
    // registered (rowClick needs it) but is hidden from the row-actions cell.
    $node = redirectsTableNode();
    $actions = array_column($node['options']['rowActions'], null, 'name');

    expect($node['options']['rowClick'] ?? null)->toBe('redirectEdit')
        ->and($actions['redirectEdit']['meta']['hidden'] ?? null)->toBeTrue()
        ->and($actions['redirectDelete']['meta']['hidden'] ?? null)->not->toBeTrue();
});

// Radix Select throws on <Select.Item value="">, which crashed the whole
// admin page when the create-redirect modal opened. "All locales" must be
// a non-empty sentinel, mapped to null on save.
it('emits no empty option values in the locale select', function (): void {
    $values = array_column(RedirectFormOptions::locales(), 'value');

    expect($values)->not->toContain('')
        ->and($values)->toContain(RedirectFormOptions::ALL_LOCALES);
});

it('round-trips the all-locales sentinel as a null locale', function (): void {
    $redirect = RedirectForm::save([
        'from_path' => 'old-path',
        'to_path' => 'new-path',
        'locale' => RedirectFormOptions::ALL_LOCALES,
        'status_code' => '301',
    ]);

    expect($redirect->locale)->toBeNull()
        ->and(RedirectForm::loadRow($redirect->id)['locale'])
        ->toBe(RedirectFormOptions::ALL_LOCALES);
});

// Action endpoints have no field-error channel: a thrown ValidationException
// becomes a redirect the client silently ignores (form reset, no message).
// Validation failures must come back as a haltModal effect instead.
it('returns a haltModal effect when create validation fails', function (): void {
    $response = $this->postJson('/admin/redirects/actions/redirectCreateSave', [
        'payload' => ['form' => [
            'from_path' => 'old-external',
            'to_path' => 'https://evil.example/page',
            'locale' => RedirectFormOptions::ALL_LOCALES,
            'status_code' => '301',
        ]],
    ]);

    $response->assertOk();
    $effects = $response->json('effects');

    expect($effects)->toHaveCount(1)
        ->and($effects[0]['kind'])->toBe('haltModal')
        ->and($effects[0]['message'])->toContain('not allowlisted')
        ->and(Redirect::count())->toBe(0);
});

it('returns a haltModal effect when edit validation fails', function (): void {
    $redirect = RedirectForm::save([
        'from_path' => 'old-a',
        'to_path' => 'new-a',
        'locale' => RedirectFormOptions::ALL_LOCALES,
        'status_code' => '301',
    ]);

    $response = $this->postJson('/admin/redirects/actions/redirectEditSave', [
        'payload' => [
            'row' => ['id' => $redirect->id],
            'form' => [
                'from_path' => 'old-a',
                'to_path' => 'https://evil.example/page',
                'locale' => RedirectFormOptions::ALL_LOCALES,
                'status_code' => '301',
            ],
        ],
    ]);

    $response->assertOk();
    $effects = $response->json('effects');

    expect($effects)->toHaveCount(1)
        ->and($effects[0]['kind'])->toBe('haltModal')
        ->and(Redirect::find($redirect->id)->to_path)->toBe('new-a');
});

it('preselects all locales and a permanent redirect in the create modal', function (): void {
    $data = test()->get('/admin/redirects', ['X-Inertia' => 'true'])->assertOk()->json('props.data.redirectCreate');

    expect($data['locale'] ?? null)->toBe(RedirectFormOptions::ALL_LOCALES)
        ->and($data['status_code'] ?? null)->toBe('301');
});

it('deletes the record the row key names, not the id column', function (): void {
    $make = static fn (string $from): Redirect => RedirectForm::save([
        'from_path' => $from,
        'to_path' => 'new',
        'locale' => RedirectFormOptions::ALL_LOCALES,
        'status_code' => '301',
    ]);
    $target = $make('old-a');
    $other = $make('old-b');

    $this->postJson('/admin/redirects/actions/redirectDelete', [
        'payload' => ['row' => ['_key' => $target->id, 'id' => $other->id]],
    ])->assertOk();

    expect(Redirect::find($target->id))->toBeNull()
        ->and(Redirect::find($other->id))->not->toBeNull();
});
