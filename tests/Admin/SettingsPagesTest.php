<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Cms;
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

it('splits the SEO settings into one tab per topic, indexing first', function (): void {
    $structure = test()->get('/admin/seo-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $tabs = [];
    settingsCollectByKind($structure, 'tabs', $tabs);

    expect($tabs)->toHaveCount(1)
        ->and(array_column($tabs[0]['options']['tabs'], 'label'))->toBe([
            __('tbtop-cms::settings.seo.tabs.indexing'),
            __('tbtop-cms::settings.seo.tabs.titles'),
            __('tbtop-cms::settings.seo.tabs.type_templates'),
            __('tbtop-cms::settings.seo.tabs.social'),
            __('tbtop-cms::settings.seo.tabs.identity'),
            __('tbtop-cms::settings.seo.tabs.integrations'),
            __('tbtop-cms::settings.seo.tabs.not_found'),
            __('tbtop-cms::settings.seo.tabs.ai'),
        ]);
});

it('keeps every settings field reachable after the regrouping', function (): void {
    // Regrouping is layout only: a field that fell out of the tree would stop
    // being validated and stop being saved, silently.
    $structure = test()->get('/admin/seo-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $names = array_keys(settingsFieldLabels($structure));

    expect($names)->toContain(
        'seo_indexing_enabled',
        'seo_sitemap_per_chunk',
        'seo_robots_extra',
        'seo_title_template',
        'seo_title_separator',
        'seo_description_template',
        'seo_default_description',
        'seo_default_og_image',
        'seo_twitter_card',
        'seo_identity_type',
        'seo_gtm_container_id',
        'seo_verification_google',
        'seo_verification_bing',
        'seo_not_found_enabled',
        'seo_not_found_retention_days',
        'seo_type_page_title',
        'seo_type_page_description',
        'seo_type_category_title',
        'seo_type_category_description',
        'seo_type_article_title',
        'seo_type_article_description',
        'seo_llms_txt_enabled',
        'seo_llms_txt_summary',
        'seo_llms_txt_intro',
        'seo_llms_txt_types',
        'seo_llms_txt_per_type',
        'seo_markdown_enabled',
        'seo_content_signals_enabled',
        'seo_content_signals_search',
        'seo_content_signals_ai_input',
        'seo_content_signals_ai_train',
    );
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
        ->and($labels['seo_verification_google'])->toBe(__('tbtop-cms::settings.seo.fields.verification_google'))
        ->and($labels['seo_verification_bing'])->toBe(__('tbtop-cms::settings.seo.fields.verification_bing'))
        ->and($labels['seo_identity_type'])->toBe(__('tbtop-cms::settings.seo.fields.identity_type'))
        ->and($labels['seo_twitter_card'])->toBe(__('tbtop-cms::settings.seo.fields.twitter_card'));

    $verificationGoogle = settingsFindFieldByName($structure, 'seo_verification_google');
    expect($verificationGoogle['options']['helperText'] ?? null)->toBe(__('tbtop-cms::settings.seo.helpers.verification_google'));

    $verificationBing = settingsFindFieldByName($structure, 'seo_verification_bing');
    expect($verificationBing['options']['helperText'] ?? null)->toBe(__('tbtop-cms::settings.seo.helpers.verification_bing'));

    // The cms frontend is an Inertia SPA: internal navigation never reloads
    // the page, so the helper must tell admins to add a History Change
    // trigger or GTM pageview tags only fire once per session.
    $gtmContainerId = settingsFindFieldByName($structure, 'seo_gtm_container_id');
    expect($gtmContainerId['options']['helperText'] ?? null)
        ->toBe(__('tbtop-cms::settings.seo.helpers.gtm_container_id'))
        ->and($gtmContainerId['options']['helperText'])->toContain('History Change');
});

it('labels notification settings fields and helper text from translations', function (): void {
    $structure = test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $labels = settingsFieldLabels($structure);

    expect($labels['notification_mail_recipients'])->toBe(__('tbtop-cms::settings.notifications.fields.recipients'))
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

it('rejects an extra robots rule that claims a directive the site writes itself', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_robots_extra' => "Crawl-delay: 10\nDisallow: /",
    ])->assertSessionHasErrors('seo_robots_extra');

    expect(app(Settings::class)->get('seo.robots_extra'))->toBeNull();
});

it('rejects a Content-Signal line in the extra robots rules', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_robots_extra' => 'Content-Signal: ai-train=yes',
    ])->assertSessionHasErrors('seo_robots_extra');
});

it('saves the For AI tab under the keys llms.txt and robots.txt read', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_llms_txt_enabled' => true,
        'seo_llms_txt_types' => ['article'],
        'seo_llms_txt_per_type' => 20,
        'seo_markdown_enabled' => true,
        'seo_content_signals_enabled' => true,
        'seo_content_signals_ai_train' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $settings = app(Settings::class);

    expect($settings->get('seo.llms_txt.enabled'))->toBeTrue()
        ->and($settings->get('seo.llms_txt.types'))->toBe(['article'])
        ->and((int) $settings->get('seo.llms_txt.per_type'))->toBe(20)
        ->and($settings->get('seo.markdown.enabled'))->toBeTrue()
        ->and($settings->get('seo.content_signals.ai_train'))->toBeTrue();
});

it('turns a content signal off when the editor unticks it', function (): void {
    app(Cms::class)->frontendRoutes();

    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_content_signals_enabled' => true,
        'seo_content_signals_search' => false,
        'seo_content_signals_ai_input' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect((string) test()->get('/robots.txt')->getContent())
        ->toContain('Content-Signal: search=no, ai-input=no, ai-train=no');
});

it('rejects more pages per type than llms.txt lists', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_llms_txt_per_type' => 501,
    ])->assertSessionHasErrors('seo_llms_txt_per_type');
});

it('accepts extra robots rules that stay clear of the owned directives', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_robots_extra' => "# slow the bots down\nCrawl-delay: 10",
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('seo.robots_extra'))->toBe("# slow the bots down\nCrawl-delay: 10");
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

it('normalizes pasted verification meta tags down to their bare tokens on save', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_verification_google' => '<meta name="google-site-verification" content="google-token-123" />',
        'seo_verification_bing' => '<meta name="msvalidate.01" content="bing-token-456" />',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('seo.verification.google'))->toBe('google-token-123')
        ->and(app(Settings::class)->get('seo.verification.bing'))->toBe('bing-token-456');

    $record = test()->get('/admin/seo-settings', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.data.seo-settings');

    expect($record['seo_verification_google'])->toBe('google-token-123')
        ->and($record['seo_verification_bing'])->toBe('bing-token-456');
});

it('rejects a verification value that does not normalize to a valid token', function (): void {
    // A Bing tag pasted into the Google field must produce a form error at
    // save time, not be stored verbatim as if verification were configured.
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_verification_google' => '<meta name="msvalidate.01" content="bing-token-456" />',
    ])->assertRedirect()->assertSessionHasErrors([
        'seo_verification_google' => __('tbtop-cms::settings.seo.validation.verification_unrecognized'),
    ]);

    expect(app(Settings::class)->get('seo.verification.google'))->toBeNull();

    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_verification_bing' => 'not a token at all!!',
    ])->assertRedirect()->assertSessionHasErrors(['seo_verification_bing']);

    expect(app(Settings::class)->get('seo.verification.bing'))->toBeNull();
});

it('leaves telephone, email and profile links to Site settings contacts, pointing there from the Identity tab', function (): void {
    $structure = test()->get('/admin/seo-settings', ['X-Inertia' => 'true'])->assertOk()->json('props.structure');

    expect(settingsFindFieldByName($structure, 'seo_identity_telephone'))->toBeNull()
        ->and(settingsFindFieldByName($structure, 'seo_identity_email'))->toBeNull()
        ->and(settingsFindFieldByName($structure, 'seo_identity_same_as'))->toBeNull()
        ->and(json_encode($structure, JSON_UNESCAPED_UNICODE))->toContain(__('tbtop-cms::settings.seo.helpers.identity_contacts'));

    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_identity_telephone' => '+380 44 123 4567',
        'seo_identity_email' => 'press@example.com',
        'seo_identity_same_as' => ['https://github.com/acme'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('seo.identity.telephone'))->toBeNull()
        ->and(app(Settings::class)->get('seo.identity.email'))->toBeNull()
        ->and(app(Settings::class)->get('seo.identity.same_as'))->toBeNull();
});

it('does not return mail.password on the notification settings record', function (): void {
    app(Settings::class)->set('mail.password', 'hunter2');

    $response = test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.data.notification-settings.mail_password'))->toBeNull();
});

/**
 * Action names inside the form's footer flex node (cancel/save) — the only
 * flex node in a settings form tree.
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
    foreach (settingsChildNodes($node) as $child) {
        $found = settingsFindFieldByName($child, $name);
        if ($found !== null) {
            return $found;
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
    foreach (settingsChildNodes($node) as $child) {
        if (isset($child['name'], $child['options']['label'])) {
            $labels[$child['name']] = $child['options']['label'];
        }
        $labels = [...$labels, ...settingsFieldLabels($child)];
    }

    return $labels;
}

/**
 * Child nodes of a structure node, from either shape a container uses:
 * `options.children` for stacks and sections, `options.tabs[].body` for a
 * tabs node. Mirrors Node::nestedChildren() on the PHP side, which is what
 * lets RuleWalker validate fields nested inside tabs.
 *
 * @param  array<string, mixed>  $node
 * @return list<array<string, mixed>>
 */
function settingsChildNodes(array $node): array
{
    $out = [];

    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            $out[] = $child;
        }
    }

    foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
        if (is_array($tab) && is_array($tab['body'] ?? null)) {
            $out[] = $tab['body'];
        }
    }

    return $out;
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
    foreach (settingsChildNodes($node) as $child) {
        settingsCollectByKind($child, $kind, $out);
    }
}

it('shows the runtime fallback in a select whose setting was never stored, and the stored value once it is', function (): void {
    expect(test()->get('/admin/seo-settings', ['X-Inertia' => 'true'])->assertOk()->json('props.data.seo-settings'))
        ->toMatchArray(['seo_twitter_card' => 'auto', 'seo_identity_type' => 'none']);
    expect(test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])->assertOk()->json('props.data.notification-settings'))
        ->toMatchArray(['mail_encryption' => 'tls']);

    app(Settings::class)->set('seo.twitter_card', 'summary');
    app(Settings::class)->set('mail.encryption', 'ssl');

    expect(test()->get('/admin/seo-settings', ['X-Inertia' => 'true'])->json('props.data.seo-settings.seo_twitter_card'))->toBe('summary')
        ->and(test()->get('/admin/notification-settings', ['X-Inertia' => 'true'])->json('props.data.notification-settings.mail_encryption'))->toBe('ssl');
});
