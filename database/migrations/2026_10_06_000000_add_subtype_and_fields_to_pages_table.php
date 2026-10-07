<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Page\Models\Page;
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
        if (! Schema::hasColumn('tbtop_pages', 'subtype')) {
            Schema::table('tbtop_pages', function (Blueprint $table): void {
                $table->string('subtype')->nullable()->after('type')->index();
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
     * Each chunk is read in full with get() before any row is written: an
     * open cursor on MySQL does not survive a write on the same connection.
     */
    private function dropOwners(): void
    {
        $lastId = 0;

        do {
            $pages = Page::withTrashed()->select(['id', 'content'])
                ->where('id', '>', $lastId)->orderBy('id')->limit(self::CHUNK)->get();

            foreach ($pages as $page) {
                $lastId = (int) $page->getKey();
                // getAttribute(), not ->content: the JSON may hold anything written outside Eloquent.
                $content = $page->getAttribute('content');
                if (! is_array($content)) {
                    continue;
                }

                $isChanged = false;
                foreach ($content as $index => $row) {
                    if (is_array($row) && array_key_exists('owner', $row)) {
                        unset($row['owner']);
                        $content[$index] = $row;
                        $isChanged = true;
                    }
                }

                if ($isChanged) {
                    $page->content = $content;
                    $page->timestamps = false;
                    $page->saveQuietly();
                }
            }
        } while ($pages->count() === self::CHUNK);
    }
};
