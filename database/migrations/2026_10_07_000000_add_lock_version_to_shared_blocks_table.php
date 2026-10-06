<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tbtop_shared_blocks', 'lock_version')) {
            return;
        }

        Schema::table('tbtop_shared_blocks', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(0)->after('data');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tbtop_shared_blocks', 'lock_version')) {
            return;
        }

        Schema::table('tbtop_shared_blocks', function (Blueprint $table): void {
            $table->dropColumn('lock_version');
        });
    }
};
