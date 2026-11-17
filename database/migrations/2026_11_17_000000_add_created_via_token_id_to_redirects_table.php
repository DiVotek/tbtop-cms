<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tbtop_redirects', 'created_via_token_id')) {
            return;
        }

        Schema::table('tbtop_redirects', function (Blueprint $table): void {
            // The API token that created the row, null for every other source. No FK: a revoked token's row is gone, its redirects stay.
            $table->unsignedBigInteger('created_via_token_id')->nullable()->after('is_auto')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tbtop_redirects', 'created_via_token_id')) {
            return;
        }

        Schema::table('tbtop_redirects', function (Blueprint $table): void {
            $table->dropIndex(['created_via_token_id']);
            $table->dropColumn('created_via_token_id');
        });
    }
};
