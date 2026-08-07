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
    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    protected $table = 'tbtop_settings';
}
