<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the menus table: the feature was removed entirely (the frontend
     * host site never consumed props.cms.menus). The legacy pre-prefix name
     * is dropped too — its rename entry was removed from the 000000 prefix
     * migration along with the feature. Guarded so it is a no-op on both
     * fresh installs (table never created) and installs that already ran
     * this migration.
     */
    public function up(): void
    {
        Schema::dropIfExists('tbtop_menus');
        Schema::dropIfExists('cms_menus');
    }

    /**
     * Intentionally irreversible: the menus feature and its schema (Menu
     * model, MenuForm, MenuResolver) no longer exist in the codebase, so
     * there is nothing left to recreate the table's shape from.
     */
    public function down(): void {}
};
