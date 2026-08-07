<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Admin\Pages\Support\SettingsForm;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Settings\Models\Setting;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Settings\Support\SettingCodec;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
});

it('round-trips scalar and array values', function (): void {
    $settings = new Settings;
    $settings->set('site.name', 'Acme');
    $settings->set('seo.meta', ['a' => 1, 'b' => [2, 3]]);

    expect($settings->get('site.name'))->toBe('Acme')
        ->and($settings->get('seo.meta'))->toBe(['a' => 1, 'b' => [2, 3]]);
});

it('returns the default when a key is missing', function (): void {
    $settings = new Settings;

    expect($settings->get('missing.key', 'fallback'))->toBe('fallback')
        ->and($settings->get('missing.key'))->toBeNull();
});

it('invalidates the cache on save', function (): void {
    $settings = new Settings;
    $settings->set('site.name', 'A');
    $settings->get('site.name');

    Setting::query()->where('key', 'site.name')->update(['value' => SettingCodec::encode('B', false)]);

    expect((new Settings)->get('site.name'))->toBe('A');

    $settings->set('seo.other', 'x');

    expect((new Settings)->get('site.name'))->toBe('B');
});

it('excludes secret keys from the frontend payload', function (): void {
    $settings = new Settings;
    $settings->set('site.name', 'My Site');
    $settings->set('mail.password', 'hunter2');

    $public = $settings->forFrontend();

    expect($public)->toHaveKey('site.name')
        ->and($public['site.name'])->toBe('My Site')
        ->and($public)->not->toHaveKey('mail.password');
});

it('stores secret values encrypted at rest', function (): void {
    $settings = new Settings;
    $settings->set('mail.password', 'hunter2');

    $raw = Setting::query()->where('key', 'mail.password')->value('value');

    expect($raw)->not->toContain('hunter2')
        ->and($settings->get('mail.password'))->toBe('hunter2');
});

it('resolves site.logo and site.favicon media ids to public urls in the frontend payload', function (): void {
    Storage::fake('public');
    $logo = Media::create(['name' => 'logo.png', 'disk' => 'public', 'path' => 'media/logo.png', 'mime' => 'image/png', 'size' => 10]);
    $favicon = Media::create(['name' => 'favicon.png', 'disk' => 'public', 'path' => 'media/favicon.png', 'mime' => 'image/png', 'size' => 10]);

    $settings = app(Settings::class);
    $settings->set('site.logo', (string) $logo->getKey());
    $settings->set('site.favicon', (string) $favicon->getKey());

    $public = $settings->forFrontend();

    expect($public['site.logo'])->toBe(Storage::disk('public')->url('media/logo.png'))
        ->and($public['site.favicon'])->toBe(Storage::disk('public')->url('media/favicon.png'));
});

it('resolves site.logo to null when unset or the stored media id no longer exists', function (): void {
    Storage::fake('public');
    $settings = app(Settings::class);
    $settings->set('site.logo', '999999');

    expect($settings->forFrontend()['site.logo'])->toBeNull();
});

it('resolves seo.default_og_image to a public url in the frontend payload', function (): void {
    Storage::fake('public');
    $image = Media::create(['name' => 'og.png', 'disk' => 'public', 'path' => 'media/og.png', 'mime' => 'image/png', 'size' => 10]);

    $settings = app(Settings::class);
    $settings->set('seo.default_og_image', (string) $image->getKey());

    expect($settings->forFrontend()['seo.default_og_image'])
        ->toBe(Storage::disk('public')->url('media/og.png'));
});

it('serves a fresh value from a new Settings instance after SettingsForm::apply()', function (): void {
    (new Settings)->set('site.name', 'Original');
    // Prime the cross-request cache the way a frontend request would.
    (new Settings)->get('site.name');

    SettingsForm::apply(['site_name' => 'Updated'], ['site_name' => 'site.name'], new Settings);

    // A brand-new instance stands in for the next incoming request.
    expect((new Settings)->get('site.name'))->toBe('Updated');
});

it('never stores a decrypted secret value in the cached settings payload', function (): void {
    (new Settings)->set('mail.password', 'hunter2');

    // Read through CmsCache the same way Settings::rawAll() populates it, to
    // inspect exactly what landed in the store under the versioned key.
    /** @var array<string, string|null> $cachedRaw */
    $cachedRaw = app(CmsCache::class)->remember(
        'tbtop-cms.settings',
        static fn (): array => Setting::query()->pluck('value', 'key')->all(),
    );

    expect($cachedRaw['mail.password'])->not->toBeNull()
        ->and($cachedRaw['mail.password'])->not->toContain('hunter2')
        // decode() proves it's still ciphertext: it only comes back out
        // correctly when told this key is secret.
        ->and(SettingCodec::decode($cachedRaw['mail.password'], true))->toBe('hunter2');
});
