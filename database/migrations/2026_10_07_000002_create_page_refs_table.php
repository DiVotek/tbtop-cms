<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_page_refs')) {
            return;
        }

        Schema::create('tbtop_page_refs', function (Blueprint $table): void {
            $table->id();
            // The referencing page; its rows go with it on a force delete.
            $table->foreignId('page_id')->constrained('tbtop_pages')->cascadeOnDelete();
            // The pages-kind field on the source page's type.
            $table->string('field');
            // No foreign key: a stored id may outlive its page, and readers filter on live pages anyway.
            $table->unsignedBigInteger('target_id');
            $table->unsignedInteger('position');

            $table->index(['target_id', 'field']);
            $table->index('page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_page_refs');
    }
};
