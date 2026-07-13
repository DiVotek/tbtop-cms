<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);
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

it('labels fields from translations', function (): void {
    $structure = test()->get('/admin/profile', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $labels = profileFieldLabels($structure);

    expect($labels['display_name'])->toBe(__('tbtop-cms::profile.fields.display_name'))
        ->and($labels['email'])->toBe(__('tbtop-cms::profile.fields.email'))
        ->and($labels['locale'])->toBe(__('tbtop-cms::profile.fields.locale'))
        ->and($labels)->not->toHaveKey('avatar_media_id');
});

it('shows the signed-in email as a disabled field', function (): void {
    $structure = test()->get('/admin/profile', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $email = profileFindField($structure, 'email');
    expect($email)->not->toBeNull()
        ->and($email['meta']['disabled'] ?? null)->toBeTrue();
});

it('renders the standard EditFooter with cancel visiting this same page', function (): void {
    $structure = test()->get('/admin/profile', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $names = profileFooterActionNames($structure);
    expect($names)->toBe(['cancel', 'save']);

    $save = profileFindAction($structure, 'save');
    expect($save)->not->toBeNull()
        ->and($save['options']['label'] ?? null)->toBe(__('tbtop-cms::profile.actions.save'));

    $cancel = profileFindAction($structure, 'cancel');
    expect($cancel['options']['spec']['href'] ?? null)->toEndWith('/admin/profile');
});

it('saves and notifies from translations', function (): void {
    test()->post('/admin/profile/forms/profile', ['display_name' => 'New Name', 'locale' => 'en'])
        ->assertRedirect();

    // "tbtop.effects" is a single flash key (literal dot in the name), not a
    // nested path — session()'s dot notation would misparse it as 4 segments.
    $effects = session('inertia.flash_data')['tbtop.effects'] ?? null;
    expect($effects)->not->toBeNull();

    $notify = collect($effects)->firstWhere('kind', 'notify');
    expect($notify)->not->toBeNull()
        ->and($notify['message'])->toBe(__('tbtop-cms::profile.actions.saved'));

    expect(CmsUserProfile::first()?->display_name)->toBe('New Name');
});

/**
 * @param  array<string, mixed>  $node
 * @return array<string, string>
 */
function profileFieldLabels(array $node): array
{
    $labels = [];
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (! is_array($child)) {
            continue;
        }
        if (isset($child['name'], $child['options']['label'])) {
            $labels[$child['name']] = $child['options']['label'];
        }
        $labels = [...$labels, ...profileFieldLabels($child)];
    }

    return $labels;
}

/**
 * Action names inside the form's footer flex node (cancel/save) — the only
 * flex node in the profile form tree, mirroring SettingsPagesTest's footer.
 *
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
function profileFooterActionNames(array $node): array
{
    $flexes = [];
    profileCollectByKind($node, 'flex', $flexes);
    expect($flexes)->not->toBeEmpty();

    $footer = $flexes[array_key_last($flexes)];
    $names = [];
    foreach ((array) ($footer['options']['children'] ?? []) as $child) {
        if (is_array($child) && ($child['kind'] ?? null) === 'action') {
            $names[] = $child['name'];
        }
    }

    return $names;
}

/** @param  array<string, mixed>  $node @return array<string, mixed>|null */
function profileFindField(array $node, string $name): ?array
{
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (! is_array($child)) {
            continue;
        }
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

/** @param  array<string, mixed>  $node @return array<string, mixed>|null */
function profileFindAction(array $node, string $name): ?array
{
    $actions = [];
    profileCollectByKind($node, 'action', $actions);

    foreach ($actions as $action) {
        if (($action['name'] ?? null) === $name) {
            return $action;
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
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            profileCollectByKind($child, $kind, $out);
        }
    }
}
