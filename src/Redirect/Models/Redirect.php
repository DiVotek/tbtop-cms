<?php

declare(strict_types=1);

namespace Tbtop\Cms\Redirect\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $locale
 * @property string $from_path
 * @property string $to_path
 * @property int $status_code
 * @property bool $is_auto
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Redirect extends Model
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'status_code' => 'integer',
        'is_auto' => 'boolean',
    ];
}
