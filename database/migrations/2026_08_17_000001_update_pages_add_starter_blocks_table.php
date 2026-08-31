<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Section starter blocks: a category-only JSON bag shaped
     * `['article' => <block envelope>, 'category' => <block envelope>]`,
     * each envelope the same shape as `content` (see Admin\Fields\BlockContent).
     * Nullable/absent for every non-category page, and for a category that
     * has not set either starter yet.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tbtop_pages', 'starter_blocks')) {
            return;
        }

        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->json('starter_blocks')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->dropColumn('starter_blocks');
        });
    }
};
