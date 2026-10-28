<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_entries')) {
            return;
        }

        Schema::create('tbtop_entries', function (Blueprint $table): void {
            $table->id();
            // The Collection::key() the entry belongs to; the schema lives in code.
            $table->string('collection', 64);
            // Field values by name, translatable ones as per-locale maps, the
            // same shape a page's type fields store.
            $table->json('data');
            // The title field's value in the default locale, kept by the model on
            // save so the admin list can search and sort it with plain SQL.
            $table->string('title')->default('');
            $table->string('status', 16)->default('draft');
            $table->integer('sort_order')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['collection', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_entries');
    }
};
