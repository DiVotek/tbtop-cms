<?php

declare(strict_types=1);

namespace Tbtop\Cms\Menu\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named site-navigation menu. Items are stored as a nested tree in the
 * `items` JSON column (two levels deep: item -> children). The tree is not
 * rendered on the frontend yet — this is the admin/authoring layer.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property array<int, array<string, mixed>>|null $items
 */
class Menu extends Model
{
    protected $table = 'cms_menus';

    /** @var list<string> */
    protected $fillable = ['key', 'name', 'items'];

    /** @var array<string, string> */
    protected $casts = [
        'items' => 'array',
    ];
}
