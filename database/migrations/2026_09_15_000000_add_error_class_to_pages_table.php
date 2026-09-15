<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tbtop_pages', 'error_class')) {
            return;
        }

        Schema::table('tbtop_pages', function (Blueprint $table): void {
            // Class, not status code: Tbtop\Cms\Page\Enums\ErrorClass.
            $table->string('error_class')->nullable()->after('is_system')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->dropUnique(['error_class']);
            $table->dropColumn('error_class');
        });
    }
};
