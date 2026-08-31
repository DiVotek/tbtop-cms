<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbtop_seo_audit_snapshots')) {
            return;
        }

        Schema::create('tbtop_seo_audit_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('captured_on')->unique();
            $table->unsignedInteger('pages_scanned');
            $table->unsignedInteger('pages_with_issues');
            $table->unsignedInteger('fail_count');
            $table->unsignedInteger('warn_count');
            $table->json('issue_counts');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbtop_seo_audit_snapshots');
    }
};
