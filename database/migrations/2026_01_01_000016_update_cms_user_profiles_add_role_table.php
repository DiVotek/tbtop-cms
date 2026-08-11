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
        if (Schema::hasColumn('tbtop_user_profiles', 'role')) {
            return;
        }

        Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
            $table->string('role')->nullable()->after('user_id');
        });

        // Role slugs are literals, not enum refs: the CmsRole enum was
        // removed in a later version and an applied migration must keep
        // replaying on a fresh database.
        DB::table('tbtop_user_profiles')->update(['role' => 'superadministrator']);

        Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
            $table->string('role')->default('editor')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
