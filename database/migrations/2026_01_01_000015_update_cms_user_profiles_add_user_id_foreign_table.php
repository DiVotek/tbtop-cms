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
        $hasUserIdForeignKey = collect(Schema::getForeignKeys('cms_user_profiles'))
            ->contains(fn (array $foreignKey): bool => in_array('user_id', $foreignKey['columns'], true));

        if ($hasUserIdForeignKey) {
            return;
        }

        // Deletes orphaned profiles first: without this FK a deleted user's
        // reused id would otherwise inherit admin access via the old row.
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
