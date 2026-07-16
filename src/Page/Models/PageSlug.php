<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property string $locale
 * @property string $slug
 * @property string $path
 */
class PageSlug extends Model
{
    /** @var list<string> */
    protected $fillable = ['page_id', 'locale', 'slug', 'path'];

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
