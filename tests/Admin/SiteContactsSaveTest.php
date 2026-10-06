<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Settings\SiteContacts;
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

it('drops an entirely empty contact row and saves the complete one', function (): void {
    $this->post('/admin/site-settings/forms/site-settings', [
        'site_name' => 'Acme',
        'phones' => [['number' => '+380 44 000 00 00'], ['number' => '  ']],
        'emails' => [['address' => ''], ['address' => 'hi@example.com']],
        'socials' => [['label' => 'TG', 'name' => '', 'href' => 'https://t.me/acme'], ['label' => '', 'name' => '', 'href' => '']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settings = app(Settings::class);
    expect($settings->get(SiteContacts::PHONES))->toBe([['number' => '+380 44 000 00 00']])
        ->and($settings->get(SiteContacts::EMAILS))->toBe([['address' => 'hi@example.com']])
        ->and($settings->get(SiteContacts::SOCIALS))->toBe([['label' => 'TG', 'href' => 'https://t.me/acme']]);
});

it('rejects a half-filled social row', function (): void {
    $this->post('/admin/site-settings/forms/site-settings', [
        'site_name' => 'Acme',
        'socials' => [['label' => 'TG', 'name' => '', 'href' => '']],
    ])->assertSessionHasErrors('socials.0.href');

    expect(app(Settings::class)->get(SiteContacts::SOCIALS))->toBeEmpty();
});
