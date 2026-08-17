<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the templates table: the feature was removed entirely (replaced
     * by section starter blocks). Guarded so it is a no-op on both fresh
     * installs (table never created) and installs that already ran this
     * migration.
     */
    public function up(): void
    {
        Schema::dropIfExists('tbtop_templates');
    }

    /**
     * Intentionally irreversible: the templates feature and its schema
     * (Template model, TemplateApplier) no longer exist in the codebase, so
     * there is nothing left to recreate the table's shape from.
     */
    public function down(): void {}
};
