<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Notifications\Models\NotificationTemplate;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function actingAsOwner(): AdminUser
{
    $user = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'is_owner' => true]);
    test()->actingAs($user, 'web');

    return $user;
}

function actingAsNonOwner(): AdminUser
{
    $user = AdminUser::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'is_owner' => false]);
    test()->actingAs($user, 'web');

    return $user;
}

/** @param  array<string, mixed>  $node @return array<string, mixed>|null */
function findFieldNode(array $node, string $name): ?array
{
    if (($node['name'] ?? null) === $name) {
        return $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            $found = findFieldNode($child, $name);
            if ($found !== null) {
                return $found;
            }
        }
    }
    foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
        if (is_array($tab) && is_array($tab['body'] ?? null)) {
            $found = findFieldNode($tab['body'], $name);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

it('groups form.submission under the forms tab with a channel toggle offering every channel a template exists for', function (): void {
    actingAsOwner();

    $structure = test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $tabs = [];
    collectNodesByKind($structure, 'tabs', $tabs);
    expect($tabs)->toHaveCount(1);

    $tabLabels = array_column($tabs[0]['options']['tabs'], 'label');
    expect($tabLabels)->toContain(__('tbtop-cms::settings.notifications.tabs.channels'))
        ->and($tabLabels)->toContain(__('tbtop-cms::settings.notifications.groups.forms'));

    $channelsField = findFieldNode($structure, 'channels__form__submission');
    expect($channelsField)->not->toBeNull()
        ->and($channelsField['kind'])->toBe('checkboxlist');

    $values = array_column($channelsField['options']['options'], 'value');
    // Mutation this catches: listing every registered channel regardless of
    // whether the type has a template for its format — form.submission has
    // templates for html and short, so mail (html), telegram (short) and
    // bell (short) all qualify; a text-only channel would not.
    expect($values)->toBe(['mail', 'telegram', 'bell']);
});

it('titles the form.submission card in the admin user\'s locale, not the boot locale', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $owner = actingAsOwner();
    CmsUserProfile::query()->where('user_id', $owner->id)->update(['locale' => 'uk']);

    $page = test()->withSession(['tbtop.locale' => 'uk'])
        ->get('/admin/notification-settings', ['X-Inertia' => 'true'])
        ->assertOk();

    expect(app()->getLocale())->toBe('uk')
        ->and($page->getContent())->toContain(json_encode(trans('tbtop-cms::notifications.bell.title', [], 'uk')))
        ->and($page->getContent())->not->toContain(json_encode(trans('tbtop-cms::notifications.bell.title', [], 'en')));
});

it('labels the channel toggle options with the short channel names', function (): void {
    actingAsOwner();

    $structure = test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $channelsField = findFieldNode($structure, 'channels__form__submission');

    expect(array_column($channelsField['options']['options'], 'label'))->toBe(['Email', 'Telegram', 'Bell']);
});

it('saves channel toggles and a template body as the owner, and probes the template before persisting it', function (): void {
    actingAsOwner();

    test()->post('/admin/notification-settings/forms/notification-settings', [
        'channels__form__submission' => ['bell', 'mail'],
        'tpl__form__submission__html__en__subject' => 'New submission',
        'tpl__form__submission__html__en__body' => '<p>{{ $pageName }}</p>',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('notifications.form.submission.channels'))->toBe(['bell', 'mail']);

    $row = NotificationTemplate::query()->where('type', 'form.submission')->where('format', 'html')->where('locale', 'en')->first();
    expect($row)->not->toBeNull()
        ->and($row->subject)->toBe('New submission')
        ->and($row->body)->toBe('<p>{{ $pageName }}</p>');
    expect(NotificationTemplate::query()->count())->toBe(1);
});

it('deletes the template row when the body is emptied on a later save', function (): void {
    actingAsOwner();

    test()->post('/admin/notification-settings/forms/notification-settings', [
        'tpl__form__submission__html__en__subject' => 'New submission',
        'tpl__form__submission__html__en__body' => '<p>{{ $pageName }}</p>',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(NotificationTemplate::query()->count())->toBe(1);

    // Mutation this catches: treating an emptied body as "no change" (upsert
    // with an empty string) instead of deleting the row.
    test()->post('/admin/notification-settings/forms/notification-settings', [
        'tpl__form__submission__html__en__subject' => 'New submission',
        'tpl__form__submission__html__en__body' => '   ',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(NotificationTemplate::query()->count())->toBe(0);
});

it('rejects a template body that fails to render and writes no row', function (): void {
    actingAsOwner();

    // Mutation this catches: skipping the TemplateProbe check and persisting
    // whatever was posted.
    $response = test()->post('/admin/notification-settings/forms/notification-settings', [
        'tpl__form__submission__html__en__body' => '@if($pageName)unclosed',
    ])->assertRedirect()->assertSessionHasErrors(['tpl__form__submission__html__en__body']);

    expect(NotificationTemplate::query()->count())->toBe(0);
});

it('hides template fields from a non-owner and ignores a posted template body while still saving channels', function (): void {
    actingAsNonOwner();

    $structure = test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $names = [];
    collectFieldNames($structure, $names);
    $templateFieldNames = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'tpl__')));

    // Mutation this catches: dropping the owner gate on the field builder,
    // so a non-owner's structure still carries template inputs.
    expect($templateFieldNames)->toBe([]);

    test()->post('/admin/notification-settings/forms/notification-settings', [
        'channels__form__submission' => ['telegram'],
        'tpl__form__submission__html__en__body' => 'sneaky body',
    ])->assertRedirect()->assertSessionHasNoErrors();

    // Mutation this catches: reading tpl__* fields regardless of ownership,
    // writing a row a non-owner should never be able to create.
    expect(NotificationTemplate::query()->count())->toBe(0);
    expect(app(Settings::class)->get('notifications.form.submission.channels'))->toBe(['telegram']);
});

it('resets a stored template on owner confirmation, deleting the row and clearing the form field', function (): void {
    actingAsOwner();

    NotificationTemplate::query()->create([
        'type' => 'form.submission',
        'format' => 'html',
        'locale' => 'en',
        'subject' => 'Existing',
        'body' => 'Existing body',
    ]);

    $response = test()->postJson('/admin/notification-settings/actions/resetTemplate__form__submission__html', ['payload' => []])
        ->assertOk();

    // Mutation this catches: clearing the form without deleting the row (or
    // vice versa) — both must happen together.
    expect(NotificationTemplate::query()->count())->toBe(0);

    $effects = $response->json('effects');
    $setFormData = collect($effects)->firstWhere('kind', 'setFormData');
    expect($setFormData)->not->toBeNull()
        ->and($setFormData['data']['tpl__form__submission__html__en__body'])->toBe('');
});
