<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

const PROFILE_PASSWORD = 'secret-pass-1';

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => PROFILE_PASSWORD,
    ]);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    test()->actingAs($user, 'web');
});

it('carries title, subtitle, and breadcrumbs from translations', function (): void {
    $response = test()->get('/admin/profile', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::profile.title'))
        ->and($response->json('props.subtitle'))->toBe(__('tbtop-cms::profile.subtitle'));

    expect($response->json('props.breadcrumbs'))->toBe([
        ['label' => __('tbtop-cms::profile.title')],
    ]);
});

it('renders three tabs labelled from translations', function (): void {
    expect(profileTabLabels(profileStructure()))->toBe([
        __('tbtop-cms::profile.sections.personal'),
        __('tbtop-cms::profile.sections.password'),
        __('tbtop-cms::profile.sections.preferences'),
    ]);
});

it('labels fields from translations', function (): void {
    $labels = profileFieldLabels(profileStructure());

    expect($labels['display_name'])->toBe(__('tbtop-cms::profile.fields.display_name'))
        ->and($labels['email'])->toBe(__('tbtop-cms::profile.fields.email'))
        ->and($labels['locale'])->toBe(__('tbtop-cms::profile.fields.locale'))
        ->and($labels['current_password'])->toBe(__('tbtop-cms::profile.fields.current_password'))
        ->and($labels['password'])->toBe(__('tbtop-cms::profile.fields.new_password'))
        ->and($labels['password_confirmation'])->toBe(__('tbtop-cms::profile.fields.confirm_password'))
        ->and($labels['theme'])->toBe(__('tbtop-cms::profile.fields.theme'))
        ->and($labels)->not->toHaveKey('avatar_media_id');
});

it('shows the signed-in email as a disabled field', function (): void {
    $email = profileFindField(profileStructure(), 'email');
    expect($email)->not->toBeNull()
        ->and($email['meta']['disabled'] ?? null)->toBeTrue();
});

it('gives every tab form its own submit action and no cancel', function (): void {
    $actions = [];
    profileCollectByKind(profileStructure(), 'action', $actions);
    $names = array_column($actions, 'name');

    expect($names)->toBe(['save_profile', 'update_password', 'save_preferences']);
});

it('saves personal info and notifies from translations', function (): void {
    test()->post('/admin/profile/forms/profile', ['display_name' => 'New Name'])
        ->assertRedirect();

    expect(profileNotifyMessage())->toBe(__('tbtop-cms::profile.actions.saved'));
    expect(CmsUserProfile::first()?->display_name)->toBe('New Name');
});

it('saves the locale preference into the profile and session', function (): void {
    test()->post('/admin/profile/forms/preferences', ['locale' => 'en'])
        ->assertRedirect()
        ->assertSessionHas('tbtop.locale', 'en');

    expect(CmsUserProfile::first()?->locale)->toBe('en');
});

it('saves the theme preference as a plain client-readable cookie', function (): void {
    test()->post('/admin/profile/forms/preferences', ['locale' => 'en', 'theme' => 'dark'])
        ->assertRedirect()
        ->assertPlainCookie('tbtop_theme', 'dark');
});

it('rejects an unknown theme value', function (): void {
    test()->post('/admin/profile/forms/preferences', ['theme' => 'sepia'])
        ->assertSessionHasErrors('theme');
});

it('hydrates the theme select from the cookie', function (): void {
    $theme = test()->withUnencryptedCookie('tbtop_theme', 'dark')
        ->get('/admin/profile', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.data.preferences.theme');

    expect($theme)->toBe('dark');
});

it('falls back to the system theme on a tampered cookie', function (): void {
    $theme = test()->withUnencryptedCookie('tbtop_theme', 'purple')
        ->get('/admin/profile', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.data.preferences.theme');

    expect($theme)->toBe('system');
});

it('changes the password when the current password is correct', function (): void {
    test()->post('/admin/profile/forms/password', [
        'current_password' => PROFILE_PASSWORD,
        'password' => 'brand-new-pass-1',
        'password_confirmation' => 'brand-new-pass-1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(profileNotifyMessage())->toBe(__('tbtop-cms::profile.actions.password_updated'));
    expect(Hash::check('brand-new-pass-1', AdminUser::first()?->password))->toBeTrue();
});

it('rejects a wrong current password and keeps the old one', function (): void {
    test()->post('/admin/profile/forms/password', [
        'current_password' => 'not-the-password',
        'password' => 'brand-new-pass-1',
        'password_confirmation' => 'brand-new-pass-1',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check(PROFILE_PASSWORD, AdminUser::first()?->password))->toBeTrue();
});

it('rejects a short new password', function (): void {
    test()->post('/admin/profile/forms/password', [
        'current_password' => PROFILE_PASSWORD,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');
});

it('rejects a mismatched confirmation', function (): void {
    test()->post('/admin/profile/forms/password', [
        'current_password' => PROFILE_PASSWORD,
        'password' => 'brand-new-pass-1',
        'password_confirmation' => 'something-else-1',
    ])->assertSessionHasErrors('password');
});

it('rejects a new password equal to the current one', function (): void {
    test()->post('/admin/profile/forms/password', [
        'current_password' => PROFILE_PASSWORD,
        'password' => PROFILE_PASSWORD,
        'password_confirmation' => PROFILE_PASSWORD,
    ])->assertSessionHasErrors('password');
});

it('hides the password tab and form when disabled in config', function (): void {
    config(['tbtop-cms.profile.password_change' => false]);

    expect(profileTabLabels(profileStructure()))->toBe([
        __('tbtop-cms::profile.sections.personal'),
        __('tbtop-cms::profile.sections.preferences'),
    ]);

    test()->post('/admin/profile/forms/password', [
        'current_password' => PROFILE_PASSWORD,
        'password' => 'brand-new-pass-1',
        'password_confirmation' => 'brand-new-pass-1',
    ])->assertNotFound();
});

/** @return array<string, mixed> */
function profileStructure(): array
{
    return test()->get('/admin/profile', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');
}

/**
 * Child nodes of a structure node: options.children plus tab bodies,
 * so traversal helpers see fields inside the profile tabs.
 *
 * @param  array<string, mixed>  $node
 * @return list<array<string, mixed>>
 */
function profileChildNodes(array $node): array
{
    $children = array_values(array_filter((array) ($node['options']['children'] ?? []), 'is_array'));
    foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
        if (is_array($tab) && is_array($tab['body'] ?? null)) {
            $children[] = $tab['body'];
        }
    }

    return $children;
}

/** @param  array<string, mixed>  $node @return list<string> */
function profileTabLabels(array $node): array
{
    $tabsNodes = [];
    profileCollectByKind($node, 'tabs', $tabsNodes);
    expect($tabsNodes)->toHaveCount(1);

    return array_column((array) ($tabsNodes[0]['options']['tabs'] ?? []), 'label');
}

/**
 * @param  array<string, mixed>  $node
 * @return array<string, string>
 */
function profileFieldLabels(array $node): array
{
    $labels = [];
    foreach (profileChildNodes($node) as $child) {
        if (isset($child['name'], $child['options']['label'])) {
            $labels[$child['name']] = $child['options']['label'];
        }
        $labels = [...$labels, ...profileFieldLabels($child)];
    }

    return $labels;
}

/** @param  array<string, mixed>  $node @return array<string, mixed>|null */
function profileFindField(array $node, string $name): ?array
{
    foreach (profileChildNodes($node) as $child) {
        if (($child['name'] ?? null) === $name && isset($child['options']['label'])) {
            return $child;
        }
        $found = profileFindField($child, $name);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $node
 * @param  list<array<string, mixed>>  $out
 */
function profileCollectByKind(array $node, string $kind, array &$out): void
{
    if (($node['kind'] ?? null) === $kind) {
        $out[] = $node;
    }
    foreach (profileChildNodes($node) as $child) {
        profileCollectByKind($child, $kind, $out);
    }
}

function profileNotifyMessage(): ?string
{
    // "tbtop.effects" is a single flash key (literal dot in the name), not a
    // nested path — session()'s dot notation would misparse it as 4 segments.
    $effects = session('inertia.flash_data')['tbtop.effects'] ?? null;
    expect($effects)->not->toBeNull();

    $notify = collect($effects)->firstWhere('kind', 'notify');

    return is_array($notify) ? (string) $notify['message'] : null;
}
