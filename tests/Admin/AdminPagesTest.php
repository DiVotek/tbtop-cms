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
});

it('shows the pages admin to an admin user', function (): void {
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);

    $this->actingAs($user, 'web')
        ->get('/admin/pages', ['X-Inertia' => 'true'])
        ->assertOk();
});

it('shows the languages admin to an admin user', function (): void {
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);

    $this->actingAs($user, 'web')
        ->get('/admin/languages', ['X-Inertia' => 'true'])
        ->assertOk();
});

it('redirects a guest away from the pages admin', function (): void {
    $this->get('/admin/pages')->assertRedirect(route('login'));
});

it('redirects a guest away from the languages admin', function (): void {
    $this->get('/admin/languages')->assertRedirect(route('login'));
});
