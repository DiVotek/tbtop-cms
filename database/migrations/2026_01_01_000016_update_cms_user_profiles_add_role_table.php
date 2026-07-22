<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Admin\Enums\CmsRole;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cms_user_profiles', 'role')) {
            return;
        }

        Schema::table('cms_user_profiles', function (Blueprint $table): void {
            $table->string('role')->nullable()->after('user_id');
        });

        // Upgrade path: before this column existed, every profile row had
        // full admin access. Backfill to superadministrator so nobody loses
        // access they already had; new rows default to editor (least
        // privilege) at the DB level below.
        DB::table('cms_user_profiles')->update(['role' => CmsRole::SuperAdministrator->value]);

        Schema::table('cms_user_profiles', function (Blueprint $table): void {
            $table->string('role')->default(CmsRole::Editor->value)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('cms_user_profiles', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
