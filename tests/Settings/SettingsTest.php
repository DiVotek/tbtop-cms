<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
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
    $settings->set('notifications.mail.smtp_password', 'hunter2');

    $public = $settings->forFrontend();

    expect($public)->toHaveKey('site.name')
        ->and($public['site.name'])->toBe('My Site')
        ->and($public)->not->toHaveKey('notifications.mail.smtp_password');
});

it('stores secret values encrypted at rest', function (): void {
    $settings = new Settings;
    $settings->set('notifications.mail.smtp_password', 'hunter2');

    $raw = Setting::query()->where('key', 'notifications.mail.smtp_password')->value('value');

    expect($raw)->not->toContain('hunter2')
        ->and($settings->get('notifications.mail.smtp_password'))->toBe('hunter2');
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
