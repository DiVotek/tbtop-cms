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
     * install the create-* migrations above already create the new names
     * directly, so hasTable($old) is false and this is a no-op — the guards
     * exist to keep fresh and pre-existing databases both correct.
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
