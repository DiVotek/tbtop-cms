<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_page_slugs')) {
            return;
        }

        Schema::create('tbtop_page_slugs', function (Blueprint $table): void {
            $table->id();
            // No FK: package migrations load alphabetically, so page_slugs precedes pages.
            $table->unsignedBigInteger('page_id');
            $table->string('locale', 5);
            $table->string('slug', 160);
            $table->string('path', 600);

            $table->unique(['page_id', 'locale']);
            $table->unique(['locale', 'path']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_page_slugs');
    }
};
