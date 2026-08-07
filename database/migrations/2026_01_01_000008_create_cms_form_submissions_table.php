<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_form_submissions')) {
            return;
        }

        Schema::create('tbtop_form_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->nullable()->constrained('tbtop_pages')->nullOnDelete();
            $table->string('block_id');
            $table->string('kind');
            $table->string('locale');
            $table->json('payload');
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_form_submissions');
    }
};
