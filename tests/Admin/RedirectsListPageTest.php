<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\RedirectForm;
use Tbtop\Cms\Admin\Pages\Support\RedirectFormOptions;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);
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
