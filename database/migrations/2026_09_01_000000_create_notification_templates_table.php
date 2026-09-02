<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_notification_templates')) {
            return;
        }

        Schema::create('tbtop_notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('format');
            $table->string('locale');
            $table->string('subject')->default('');
            $table->text('body');
            $table->timestamps();

            $table->unique(['type', 'format', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_notification_templates');
    }
};
