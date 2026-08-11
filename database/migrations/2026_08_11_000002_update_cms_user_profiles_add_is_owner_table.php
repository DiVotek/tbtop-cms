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
        if (Schema::hasColumn('tbtop_user_profiles', 'is_owner')) {
            return;
        }

        Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
            $table->boolean('is_owner')->default(false)->after('user_id');
        });

        $this->backfillOwner();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tbtop_user_profiles', 'is_owner')) {
            return;
        }

        Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
            $table->dropColumn('is_owner');
        });
    }

    /**
     * Existing installs have profile rows but no owner yet — the earliest
     * admin (smallest id) becomes the owner, which on a pre-upgrade install
     * is whoever ran cms:admin:create first.
     */
    private function backfillOwner(): void
    {
        // Each call below builds its own query: the Illuminate query builder
        // mutates in place, so reusing one $table instance across where()
        // calls would silently accumulate conditions from a prior check.
        if (DB::table('tbtop_user_profiles')->count() === 0) {
            return;
        }

        if (DB::table('tbtop_user_profiles')->where('is_owner', true)->exists()) {
            return;
        }

        $earliestId = DB::table('tbtop_user_profiles')->min('id');

        if ($earliestId !== null) {
            DB::table('tbtop_user_profiles')->where('id', $earliestId)->update(['is_owner' => true]);
        }
    }
};
