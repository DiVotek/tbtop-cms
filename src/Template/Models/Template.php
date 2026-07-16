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

    /**
     * created_by/updated_by exist in the schema and docblock but nothing in
     * the codebase writes them yet — left out until something actually
     * sets them.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'content'];

    /** @var array<string, string> */
    protected $casts = [
        'content' => 'array',
    ];
}
