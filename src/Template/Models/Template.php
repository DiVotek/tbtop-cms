<?php

declare(strict_types=1);

namespace Tbtop\Cms\Template\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property array<int, array<string, mixed>>|null $content
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Template extends Model
{
    protected $table = 'cms_templates';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'content' => 'array',
    ];
}
