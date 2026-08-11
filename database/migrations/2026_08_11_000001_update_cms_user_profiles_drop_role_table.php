<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tbtop_user_profiles', 'role')) {
            return;
        }

        Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    /**
     * Restores the column shape only — the roles themselves are gone, so
     * every row rolls back to the old default rather than its former role.
     */
    public function down(): void
    {
        if (Schema::hasColumn('tbtop_user_profiles', 'role')) {
            return;
        }

        Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
            $table->string('role')->default('editor')->after('user_id');
        });
    }
};
