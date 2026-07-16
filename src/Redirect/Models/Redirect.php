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
    /**
     * created_by/updated_by exist in the schema and docblock but nothing in
     * the codebase writes them yet — left out until something actually
     * sets them.
     *
     * @var list<string>
     */
    protected $fillable = ['locale', 'from_path', 'to_path', 'status_code', 'is_auto'];

    /** @var array<string, string> */
    protected $casts = [
        'status_code' => 'integer',
        'is_auto' => 'boolean',
    ];
}
