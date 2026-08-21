<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbtop_not_found_hits')) {
            return;
        }

        Schema::table('tbtop_not_found_hits', function (Blueprint $table): void {
            $table->string('locale', 32)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbtop_not_found_hits')) {
            return;
        }

        Schema::table('tbtop_not_found_hits', function (Blueprint $table): void {
            $table->string('locale', 5)->change();
        });
    }
};
