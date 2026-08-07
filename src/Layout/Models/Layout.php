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
    protected $table = 'tbtop_layouts';

    /** @var list<string> */
    protected $fillable = ['slot', 'content'];

    /** @var array<string, string> */
    protected $casts = [
        'content' => 'array',
    ];
}
