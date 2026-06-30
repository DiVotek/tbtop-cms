<?php

declare(strict_types=1);

namespace Tbtop\Cms\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    protected $guarded = [];

    protected $table = 'cms_settings';
}
