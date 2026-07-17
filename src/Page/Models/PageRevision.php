<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of a Page's editorial fields, written by
 * PageRevisionWriter (via the PageRevisions observer) on create/save/restore.
 * Nothing ever updates a row after insert — $timestamps is off and created_at
 * is set explicitly by the writer, rather than relying on Eloquent's
 * automatic timestamp columns (which would also expect an updated_at column
 * this table intentionally doesn't have).
 *
 * @property int $id
 * @property int $page_id
 * @property int|null $user_id
 * @property string $kind
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 */
class PageRevision extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['page_id', 'user_id', 'kind', 'payload', 'created_at'];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
