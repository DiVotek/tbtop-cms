<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // EnsureCmsAdmin grants access purely by the existence of a
        // cms_user_profiles row for the user id. Without a FK, deleting a
        // user orphans the profile and a later reused user id would
        // silently inherit admin access. Delete orphans first so the FK
        // below can be added without failing on pre-existing bad data.
        DB::table('cms_user_profiles')
            ->whereNotIn('user_id', DB::table('users')->select('id'))
            ->delete();

        Schema::table('cms_user_profiles', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cms_user_profiles', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
    }
};
