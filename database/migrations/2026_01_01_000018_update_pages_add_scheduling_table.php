<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tbtop_pages', 'published_at')) {
            return;
        }

        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable()->after('status');
            $table->timestamp('unpublish_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->dropColumn(['published_at', 'unpublish_at']);
        });
    }
};
