<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_layouts')) {
            return;
        }

        Schema::create('tbtop_layouts', function (Blueprint $table): void {
            $table->id();
            $table->string('slot')->unique();
            $table->json('content')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_layouts');
    }
};
