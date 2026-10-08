<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * A page subtype becomes a key in `subtype` next to its base in `type`, and a
 * type's own values move to `fields`.
 *
 * Data, after the columns exist (both steps are no-ops on an empty database
 * and on a rerun):
 * - a page whose `type` is a registered subtype (base() !== key()) gets
 *   `subtype` = that key and `type` = its base;
 * - every content row loses its `owner` key and stays an ordinary block.
 *
 * What this cannot do: move the data of a former "general" type block into
 * `fields`. The package no longer knows which kinds were general on which
 * type; those rows stay in `content` as normal blocks, and the host copies
 * their `data` into `fields` (and drops the rows) in its own migration.
 */
return new class extends Migration
{
    private const int CHUNK = 200;

    public function up(): void
    {
        // Each column on its own: SQLite adds them in separate statements, so a failed run can leave one behind.
        if (! Schema::hasColumn('tbtop_pages', 'subtype')) {
            Schema::table('tbtop_pages', function (Blueprint $table): void {
                $table->string('subtype')->nullable()->after('type')->index();
            });
        }
        if (! Schema::hasColumn('tbtop_pages', 'fields')) {
            Schema::table('tbtop_pages', function (Blueprint $table): void {
                $table->json('fields')->nullable()->after('content');
            });
        }

        $this->moveSubtypesOutOfType();
        $this->dropOwners();
    }

    /** `fields` has no column to return to: its values are dropped. */
    public function down(): void
    {
        DB::table('tbtop_pages')->whereNotNull('subtype')->update(['type' => DB::raw('subtype')]);

        Schema::table('tbtop_pages', function (Blueprint $table): void {
            $table->dropIndex(['subtype']);
            $table->dropColumn(['subtype', 'fields']);
        });
    }

    private function moveSubtypesOutOfType(): void
    {
        // A fresh registry, not the container singleton: resolving the shared
        // one here would latch it before the host's extend() calls ran.
        foreach ((new PageTypeRegistry)->all() as $type) {
            if ($type->base() === $type->key()) {
                continue;
            }

            DB::table('tbtop_pages')->where('type', $type->key())
                ->update(['subtype' => $type->key(), 'type' => $type->base()]);
        }
    }

    /**
     * Raw rows, not the Page model: a historical migration must not change
     * with later model scopes, casts or hooks. Each chunk is read in full
     * with get() before any row is written: an open cursor on MySQL does not
     * survive a write on the same connection. Soft-deleted rows are included.
     */
    private function dropOwners(): void
    {
        $lastId = 0;

        do {
            $rows = DB::table('tbtop_pages')->select(['id', 'content'])
                ->where('id', '>', $lastId)->orderBy('id')->limit(self::CHUNK)->get();

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $content = is_string($row->content) ? json_decode($row->content, true) : null;
                if (! is_array($content)) {
                    continue;
                }

                $isChanged = false;
                foreach ($content as $index => $block) {
                    if (is_array($block) && array_key_exists('owner', $block)) {
                        unset($block['owner']);
                        $content[$index] = $block;
                        $isChanged = true;
                    }
                }

                if ($isChanged) {
                    DB::table('tbtop_pages')->where('id', $lastId)->update(['content' => json_encode($content)]);
                }
            }
        } while ($rows->count() === self::CHUNK);
    }
};
