<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support\Concerns;

use Tbtop\Cms\Admin\Support\CurrentAdmin;

/**
 * Stamps created_by/updated_by from the signed-in admin on every write,
 * shared by Page and Redirect (each has its own nullable, FK-less
 * created_by/updated_by pair — see their migrations for why there's no FK:
 * the host user table is configurable, same reasoning as page_revisions'
 * user_id). A model boot-hook trait rather than a shared observer: these
 * models otherwise have nothing else in common (different domains,
 * different existing observers/none), so a trait each one `use`s is a
 * smaller footprint than wiring a generic observer per model in
 * CmsServiceProvider for something this mechanical.
 *
 * Both columns are set directly on the model (`$model->created_by = ...`),
 * NOT added to $fillable — an author column must never be settable by
 * whatever array a form/request happens to mass-assign; only this trait
 * writes it, exactly once per lifecycle event.
 *
 * CurrentAdmin::user() resolves to null outside an authenticated admin
 * request — a CLI seeder, an Artisan command, or any programmatic
 * Model::create() with no signed-in guard user. Both columns are nullable
 * for exactly that case: a system-authored row simply has no author, rather
 * than the trait inventing one or throwing.
 *
 * One case worth calling out rather than treating as a bug: PageSlugRedirects
 * (src/Redirect/Observers/) auto-creates a Redirect row when an admin renames
 * a page's slug inside an admin request. That save happens with a signed-in
 * admin on the guard, so the auto-created redirect's created_by ends up
 * attributed to whichever admin triggered the slug rename — correct and
 * desired, not a leak: they're the one who caused it to exist, even though
 * they never directly filled in a redirect form.
 */
trait TracksAuthors
{
    protected static function bootTracksAuthors(): void
    {
        static::creating(static function ($model): void {
            $id = CurrentAdmin::user()?->getKey();
            $model->created_by = $id;
            $model->updated_by = $id;
        });

        // updating() only fires when the model is actually dirty (Eloquent's
        // own save() gate — see Model::performUpdate()), so this can't mark a
        // clean model dirty just by touching updated_by on every save() call;
        // it only overwrites the column when something else already changed.
        static::updating(static function ($model): void {
            $model->updated_by = CurrentAdmin::user()?->getKey();
        });
    }
}
