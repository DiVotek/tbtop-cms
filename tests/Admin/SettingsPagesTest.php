<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Settings;
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

// The form name equals the URL slug for every settings page (Page::path()).
dataset('settings_pages', [
    'site' => ['site-settings', 'settings.site.title', 'settings.site.subtitle'],
    'seo' => ['seo-settings', 'settings.seo.title', 'settings.seo.subtitle'],
    'notifications' => ['notification-settings', 'settings.notifications.title', 'settings.notifications.subtitle'],
]);

it('carries title, subtitle, and breadcrumbs from translations', function (string $url, string $titleKey, string $subtitleKey): void {
    $response = test()->get("/admin/{$url}", ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__("tbtop-cms::{$titleKey}"))
        ->and($response->json('props.subtitle'))->toBe(__("tbtop-cms::{$subtitleKey}"));

    $breadcrumbs = $response->json('props.breadcrumbs');
    expect($breadcrumbs)->toBe([
        ['label' => __('tbtop-cms::nav.groups.settings')],
        ['label' => __("tbtop-cms::{$titleKey}")],
    ]);
})->with('settings_pages');

it('labels the save action from common translations and renders the standard EditFooter', function (string $url): void {
    $structure = test()->get("/admin/{$url}", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $names = settingsFooterActionNames($structure);
    expect($names)->toBe(['cancel', 'save']);

    $save = settingsFindAction($structure, 'save');
    expect($save)->not->toBeNull()
        ->and($save['options']['label'] ?? null)->toBe(__('tbtop-cms::settings.common.save'));

    $cancel = settingsFindAction($structure, 'cancel');
    expect($cancel['options']['spec']['href'] ?? null)->toEndWith("/admin/{$url}");
})->with('settings_pages');

it('saves and notifies from common translations', function (string $url): void {
    $payload = match ($url) {
        'site-settings' => ['site_name' => 'Acme Inc'],
        'seo-settings' => ['seo_title_separator' => '|'],
        'notification-settings' => ['notification_mail_recipients' => ['admin@example.com']],
    };

    test()->post("/admin/{$url}/forms/{$url}", $payload)->assertRedirect();

    // "tbtop.effects" is a single flash key (literal dot in the name), not a
    // nested path — session()'s dot notation would misparse it as 4 segments.
    $effects = session('inertia.flash_data')['tbtop.effects'] ?? null;
    expect($effects)->not->toBeNull();

    $notify = collect($effects)->firstWhere('kind', 'notify');
    expect($notify)->not->toBeNull()
        ->and($notify['message'])->toBe(__('tbtop-cms::settings.common.saved'));
})->with('settings_pages');

it('labels site settings fields from translations', function (): void {
    $structure = test()->get('/admin/site-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $labels = settingsFieldLabels($structure);

    expect($labels['site_name'])->toBe(__('tbtop-cms::settings.site.fields.site_name'))
        ->and($labels['site_logo'])->toBe(__('tbtop-cms::settings.site.fields.logo'))
        ->and($labels['site_favicon'])->toBe(__('tbtop-cms::settings.site.fields.favicon'));
});

it('labels SEO settings fields from translations and placeholders the title template', function (): void {
    $structure = test()->get('/admin/seo-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $labels = settingsFieldLabels($structure);

    expect($labels['seo_title_template'])->toBe(__('tbtop-cms::settings.seo.fields.title_template'))
        ->and($labels['seo_title_separator'])->toBe(__('tbtop-cms::settings.seo.fields.title_separator'))
        ->and($labels['seo_description_template'])->toBe(__('tbtop-cms::settings.seo.fields.description_template'))
        ->and($labels['seo_default_description'])->toBe(__('tbtop-cms::settings.seo.fields.default_description'))
        ->and($labels['seo_default_og_image'])->toBe(__('tbtop-cms::settings.seo.fields.default_og_image'))
        ->and($labels['seo_indexing_enabled'])->toBe(__('tbtop-cms::settings.seo.fields.indexing_enabled'));

    // seo_title_suffix stays a mapped/functional setting (SeoResolver's legacy
    // fallback) but is intentionally no longer a field in this form's tree.
    expect(settingsFindFieldByName($structure, 'seo_title_suffix'))->toBeNull();

    $titleTemplate = settingsFindFieldByName($structure, 'seo_title_template');
    expect($titleTemplate['options']['placeholder'] ?? null)->toBe(__('tbtop-cms::settings.seo.placeholders.title_template'));
});

it('labels SEO identity/GTM fields from translations', function (): void {
    $structure = test()->get('/admin/seo-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $labels = settingsFieldLabels($structure);

    expect($labels['seo_gtm_container_id'])->toBe(__('tbtop-cms::settings.seo.fields.gtm_container_id'))
        ->and($labels['seo_identity_type'])->toBe(__('tbtop-cms::settings.seo.fields.identity_type'))
        ->and($labels['seo_identity_name'])->toBe(__('tbtop-cms::settings.seo.fields.identity_name'))
        ->and($labels['seo_identity_logo'])->toBe(__('tbtop-cms::settings.seo.fields.identity_logo'))
        ->and($labels['seo_identity_same_as'])->toBe(__('tbtop-cms::settings.seo.fields.identity_same_as'))
        ->and($labels['seo_twitter_card'])->toBe(__('tbtop-cms::settings.seo.fields.twitter_card'));
});

it('labels notification settings fields and helper text from translations', function (): void {
    $structure = test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $labels = settingsFieldLabels($structure);

    expect($labels['notification_mail_enabled'])->toBe(__('tbtop-cms::settings.notifications.fields.mail_enabled'))
        ->and($labels['notification_mail_recipients'])->toBe(__('tbtop-cms::settings.notifications.fields.recipients'))
        ->and($labels['mail_host'])->toBe(__('tbtop-cms::settings.notifications.fields.mail_host'))
        ->and($labels['mail_port'])->toBe(__('tbtop-cms::settings.notifications.fields.mail_port'))
        ->and($labels['mail_username'])->toBe(__('tbtop-cms::settings.notifications.fields.mail_username'))
        ->and($labels['mail_password'])->toBe(__('tbtop-cms::settings.notifications.fields.mail_password'))
        ->and($labels['mail_encryption'])->toBe(__('tbtop-cms::settings.notifications.fields.mail_encryption'))
        ->and($labels['mail_from_address'])->toBe(__('tbtop-cms::settings.notifications.fields.mail_from_address'))
        ->and($labels['mail_from_name'])->toBe(__('tbtop-cms::settings.notifications.fields.mail_from_name'));

    $recipients = settingsFindFieldByName($structure, 'notification_mail_recipients');
    expect($recipients['options']['helperText'] ?? null)->toBe(__('tbtop-cms::settings.notifications.helpers.recipients'));

    $mailPassword = settingsFindFieldByName($structure, 'mail_password');
    expect($mailPassword['options']['helperText'] ?? null)->toBe(__('tbtop-cms::settings.notifications.helpers.mail_password'));
});

it('round-trips recipients and chat ids between the stored string and tag chips', function (): void {
    app(Settings::class)->set('notifications.mail.recipients', "a@example.com\nb@example.com");
    app(Settings::class)->set('notifications.telegram.chat_ids', "111 # Dima\n222");

    $data = test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.data.notification-settings');

    // Stored separator-joined strings render as one chip per entry, a
    // labeled chat id staying a single chip.
    expect($data['notification_mail_recipients'])->toBe(['a@example.com', 'b@example.com'])
        ->and($data['notification_telegram_chat_ids'])->toBe(['111 # Dima', '222']);

    test()->post('/admin/notification-settings/forms/notification-settings', [
        'notification_mail_recipients' => ['c@example.com'],
        'notification_telegram_chat_ids' => ['333 # Olena', '444'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('notifications.mail.recipients'))->toBe('c@example.com')
        ->and(app(Settings::class)->get('notifications.telegram.chat_ids'))->toBe("333 # Olena\n444");
});

it('normalizes a pasted full GTM install snippet down to the bare container id on save', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_gtm_container_id' => "<!-- Google Tag Manager -->\n<script>j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;(function(w,d,s,l,i){...})(window,document,'script','dataLayer','GTM-AB12CD3');</script>",
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('seo.gtm_container_id'))->toBe('GTM-AB12CD3');

    $record = test()->get('/admin/seo-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.data.seo-settings');

    expect($record['seo_gtm_container_id'])->toBe('GTM-AB12CD3');
});

it('drops non-URL profile links from seo.identity.same_as on save', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_identity_same_as' => ['https://github.com/acme', 'not a url', 'javascript:alert(1)', 'ftp://x.example'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('seo.identity.same_as'))->toBe(['https://github.com/acme']);
});

it('does not return mail.password on the notification settings record', function (): void {
    app(Settings::class)->set('mail.password', 'hunter2');

    $response = test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.data.notification-settings.mail_password'))->toBeNull();
});

/**
 * Action names inside the form's footer flex node (cancel/save) — the only
 * flex node in a settings form tree, mirroring TemplateFormPage's footer.
 *
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
function settingsFooterActionNames(array $node): array
{
    $flexes = [];
    settingsCollectByKind($node, 'flex', $flexes);
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
function settingsFindAction(array $node, string $name): ?array
{
    $actions = [];
    settingsCollectByKind($node, 'action', $actions);

    foreach ($actions as $action) {
        if (($action['name'] ?? null) === $name) {
            return $action;
        }
    }

    return null;
}

/** @param  array<string, mixed>  $node @return array<string, mixed>|null */
function settingsFindFieldByName(array $node, string $name): ?array
{
    if (($node['name'] ?? null) === $name) {
        return $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            $found = settingsFindFieldByName($child, $name);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * Maps every field's `name` to its label, skipping non-field nodes (action,
 * spacer, flex) that carry no `label` option.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, string>
 */
function settingsFieldLabels(array $node): array
{
    $labels = [];
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (! is_array($child)) {
            continue;
        }
        if (isset($child['name'], $child['options']['label'])) {
            $labels[$child['name']] = $child['options']['label'];
        }
        $labels = [...$labels, ...settingsFieldLabels($child)];
    }

    return $labels;
}

/**
 * @param  array<string, mixed>  $node
 * @param  list<array<string, mixed>>  $out
 */
function settingsCollectByKind(array $node, string $kind, array &$out): void
{
    if (($node['kind'] ?? null) === $kind) {
        $out[] = $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            settingsCollectByKind($child, $kind, $out);
        }
    }
}
