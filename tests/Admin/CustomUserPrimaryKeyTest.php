<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Support\AdminUsers;
use Tbtop\Cms\Admin\Support\AuthorNames;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\CustomKeyAdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The profile FK migration targets users.id; this host keys its users
    // on `uid`, so the constraint is off for the custom table.
    Schema::disableForeignKeyConstraints();
    Schema::create('custom_key_users', function (Blueprint $table): void {
        $table->id('uid');
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    config(['tbtop-cms.admin.model' => CustomKeyAdminUser::class]);
    config(['auth.providers.users.model' => CustomKeyAdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

afterEach(function (): void {
    Schema::enableForeignKeyConstraints();
});

function customKeyAdmin(string $email = 'ada@example.com', string $name = 'Ada'): CustomKeyAdminUser
{
    $user = CustomKeyAdminUser::create(['uid' => 7001, 'name' => $name, 'email' => $email, 'password' => 'secret123']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->getKey()]);

    return $user;
}

it('lists admin recipients for a user model keyed on another column', function (): void {
    $admin = customKeyAdmin();

    expect(AdminUsers::all()->map->getKey()->all())->toBe([$admin->getKey()]);
});

it('resolves author names for a user model keyed on another column', function (): void {
    $admin = customKeyAdmin();

    $authors = AuthorNames::lookup([$admin->getKey()]);
    $subselected = DB::table('tbtop_user_profiles')
        ->selectSub(AuthorNames::subselect('tbtop_user_profiles', 'user_id'), 'author')
        ->value('author');

    expect(AuthorNames::label($admin->getKey(), $authors))->toBe('Ada')
        ->and($subselected)->toBe('Ada');
});

it('lists users with their access for a user model keyed on another column', function (): void {
    $admin = customKeyAdmin();
    $this->actingAs($admin, 'web');

    $row = $this->getJson('/admin/users/tables/users')->assertOk()->json('data.data.0');

    expect($row['has_access'])->not->toBeNull()
        ->and((bool) $row['is_self'])->toBeTrue();
});
