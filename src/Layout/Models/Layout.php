<?php

declare(strict_types=1);

namespace Tbtop\Cms\Layout\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slot
 * @property array<int, array<string, mixed>>|null $content
 */
class Layout extends Model
{
    protected $table = 'cms_layouts';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'content' => 'array',
    ];
}
