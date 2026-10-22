<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Models\Setting;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    $this->actingAs($user, 'web');
});

// [field name, store key, unset hint translation key]
dataset('secret_fields', [
    'smtp password' => ['mail_password', 'mail.password', 'tbtop-cms::settings.notifications.helpers.mail_password'],
    'telegram bot token' => ['notification_telegram_bot_token', 'notifications.telegram.bot_token', 'tbtop-cms::settings.notifications.helpers.telegram_bot_token'],
]);

/** Depth-first search of a structure tree for the node carrying this field name. */
function secretsNodeNamed(mixed $node, string $name): ?array
{
    if (! is_array($node)) {
        return null;
    }

    if (($node['name'] ?? null) === $name) {
        return $node;
    }

    foreach ($node as $child) {
        $found = secretsNodeNamed($child, $name);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

function secretsStructure(): array
{
    return test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');
}

it('masks a set secret, says it is set, and offers to clear it', function (string $field, string $key): void {
    app(Settings::class)->set($key, 'stored-secret-value');

    $structure = secretsStructure();

    $input = secretsNodeNamed($structure, $field);
    expect($input['options']['placeholder'] ?? null)->toBe('••••••••')
        ->and($input['options']['helperText'] ?? null)->toBe(__('tbtop-cms::settings.common.secret_set'))
        ->and(secretsNodeNamed($structure, $field . '__clear'))->not->toBeNull();

    expect(json_encode($structure))->not->toContain('stored-secret-value');
})->with('secret_fields');

it('shows an unset secret with its own hint and no clear option', function (string $field, string $key, string $hintKey): void {
    $structure = secretsStructure();

    $input = secretsNodeNamed($structure, $field);
    expect($input['options']['placeholder'] ?? null)->toBeNull()
        ->and($input['options']['helperText'] ?? null)->toBe(__($hintKey))
        ->and(secretsNodeNamed($structure, $field . '__clear'))->toBeNull();
})->with('secret_fields');

it('keeps the telegram token hint apart from the SMTP password hint', function (): void {
    $structure = secretsStructure();

    $telegram = secretsNodeNamed($structure, 'notification_telegram_bot_token');
    expect($telegram['options']['helperText'] ?? null)
        ->toBe(__('tbtop-cms::settings.notifications.helpers.telegram_bot_token'))
        ->and($telegram['options']['helperText'])->not->toBe(__('tbtop-cms::settings.notifications.helpers.mail_password'));
});

it('keeps a set secret when its field is left blank', function (string $field, string $key): void {
    app(Settings::class)->set($key, 'original-secret');

    test()->post('/admin/notification-settings/forms/notification-settings', [$field => ''])->assertRedirect();

    expect(app(Settings::class)->get($key))->toBe('original-secret');
})->with('secret_fields');

it('replaces a set secret when a new value is typed', function (string $field, string $key): void {
    app(Settings::class)->set($key, 'original-secret');

    test()->post('/admin/notification-settings/forms/notification-settings', [$field => 'rotated-secret'])->assertRedirect();

    expect(app(Settings::class)->get($key))->toBe('rotated-secret');
})->with('secret_fields');

it('deletes a set secret when Clear is ticked and the field is blank', function (string $field, string $key): void {
    app(Settings::class)->set($key, 'stored-secret');

    test()->post('/admin/notification-settings/forms/notification-settings', [
        $field => '',
        $field . '__clear' => true,
    ])->assertRedirect();

    expect(Setting::query()->where('key', $key)->exists())->toBeFalse()
        ->and(app(Settings::class)->get($key))->toBeNull();
})->with('secret_fields');
