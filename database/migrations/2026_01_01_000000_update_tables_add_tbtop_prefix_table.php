<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * old table name => new tbtop_-prefixed name.
     *
     * @var array<string, string>
     */
    private const RENAMES = [
        'pages' => 'tbtop_pages',
        'page_slugs' => 'tbtop_page_slugs',
        'page_revisions' => 'tbtop_page_revisions',
        'languages' => 'tbtop_languages',
        'redirects' => 'tbtop_redirects',
        'cms_user_profiles' => 'tbtop_user_profiles',
        'cms_settings' => 'tbtop_settings',
        'cms_templates' => 'tbtop_templates',
        'cms_form_submissions' => 'tbtop_form_submissions',
        'cms_layouts' => 'tbtop_layouts',
        'cms_menus' => 'tbtop_menus',
    ];

    /**
     * Renames existing installs' tables to the tbtop_ prefix. On a fresh
     * install the create-* migrations create the new names directly, so
     * hasTable($old) is false and this is a no-op — the guards exist to keep
     * fresh and pre-existing databases both correct.
     *
     * This file sorts FIRST (000000): a legacy database upgrading across
     * several pending migrations must be renamed before the guarded
     * create/update migrations run, since those reference the new names
     * only. The ordering also makes rollback safe — on migrate:rollback this
     * down() runs last, after the create migrations have dropped the tbtop_*
     * tables, so a fresh install is not left with stray legacy-named tables.
     *
     * Auto-generated index/constraint names (e.g. `pages_type_index`) are NOT
     * renamed by Schema::rename() and are left as-is on upgraded databases;
     * this is intentional since nothing in the package references index
     * names by string.
     */
    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            if (Schema::hasTable($old) && ! Schema::hasTable($new)) {
                Schema::rename($old, $new);
            }
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $old => $new) {
            if (Schema::hasTable($new) && ! Schema::hasTable($old)) {
                Schema::rename($new, $old);
            }
        }
    }
};
