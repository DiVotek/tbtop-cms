<?php

declare(strict_types=1);

namespace Tbtop\Cms\NotFound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $locale
 * @property string $path
 * @property string $path_hash
 * @property int $hits
 * @property string|null $last_referer
 * @property string|null $last_user_agent
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property bool $is_ignored
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotFoundHit extends Model
{
    protected $table = 'tbtop_not_found_hits';

    /**
     * Rows are written exclusively through RecordsNotFoundHit's upsert() —
     * this fillable list only backs the admin page's ignore/unignore toggle
     * and tests, never the write path itself.
     *
     * @var list<string>
     */
    protected $fillable = [
        'locale', 'path', 'path_hash', 'hits', 'last_referer', 'last_user_agent',
        'first_seen_at', 'last_seen_at', 'is_ignored',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'hits' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_ignored' => 'boolean',
    ];
}
