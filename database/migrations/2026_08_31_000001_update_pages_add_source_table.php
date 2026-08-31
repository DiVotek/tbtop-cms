<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tbtop_pages', 'source_id')) {
            return;
        }

        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->string('source_id')->nullable()->after('type');
            $table->index(['type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->dropIndex(['type', 'source_id']);
            $table->dropColumn('source_id');
        });
    }
};
