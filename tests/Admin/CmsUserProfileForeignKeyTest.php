<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

// Testbench's shared sqlite `testing` connection ships with
// foreign_key_constraints disabled: the FK exists in the schema (verified by
// inspecting sqlite_master after running the real migration — it produces
// `foreign key("user_id") references "users"("id") on delete cascade`) but
// isn't enforced, and mutating that shared, process-wide connection leaked
// into unrelated suites when tried. Instead, this builds a throwaway
// `fk_test` sqlite connection with FK enforcement on, replicating the exact
// shape update_cms_user_profiles_add_user_id_foreign_table.php produces, to
// prove the cascade semantics actually work end-to-end.
beforeEach(function (): void {
    config(['database.connections.fk_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]]);

    Schema::connection('fk_test')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::connection('fk_test')->create('cms_user_profiles', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->unique();
        $table->string('locale')->nullable();
        $table->string('avatar_media_id')->nullable();
        $table->string('display_name')->nullable();
        $table->timestamps();

        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    });
});

afterEach(function (): void {
    DB::purge('fk_test');
});

it('cascades deletes from users to cms_user_profiles', function (): void {
    $user = AdminUser::on('fk_test')->create(['name' => 'A', 'email' => 'cascade@example.com', 'password' => 'secret']);
    $profile = CmsUserProfile::on('fk_test')->create(['user_id' => $user->id]);

    $user->delete();

    expect(CmsUserProfile::on('fk_test')->find($profile->id))->toBeNull();
});

it('does not let a reused user id inherit an orphaned profile', function (): void {
    $user = AdminUser::on('fk_test')->create(['name' => 'A', 'email' => 'reused@example.com', 'password' => 'secret']);
    $userId = $user->id;
    CmsUserProfile::on('fk_test')->create(['user_id' => $userId]);

    $user->delete();

    expect(CmsUserProfile::on('fk_test')->where('user_id', $userId)->exists())->toBeFalse();
});
