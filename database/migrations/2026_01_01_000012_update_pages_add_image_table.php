<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tbtop_pages', 'image')) {
            return;
        }

        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->string('image')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->dropColumn('image');
        });
    }
};
