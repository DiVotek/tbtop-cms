<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_shared_blocks')) {
            return;
        }

        Schema::create('tbtop_shared_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('kind');
            // The same shape a page's envelope stores under `data`, so a shared block
            // flattens and resolves through the very same code path.
            $table->json('data');
            $table->timestamps();

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_shared_blocks');
    }
};
