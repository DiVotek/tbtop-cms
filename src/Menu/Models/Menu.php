<?php

declare(strict_types=1);

namespace Tbtop\Cms\Menu\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named site-navigation menu. Items are stored as a nested tree in the
 * `items` JSON column (two levels deep: item -> children); label/url/anchor
 * are per-locale maps. Delivered to the frontend as `props.cms.menus` by
 * MenuResolver, keyed by `key`. `name` is an untranslated admin identifier.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property array<int, array<string, mixed>>|null $items
 */
class Menu extends Model
{
    protected $table = 'tbtop_menus';

    /** @var list<string> */
    protected $fillable = ['key', 'name', 'items'];

    /** @var array<string, string> */
    protected $casts = [
        'items' => 'array',
    ];
}
