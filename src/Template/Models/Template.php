<?php

declare(strict_types=1);

namespace Tbtop\Cms\Template\Models;

use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Support\Concerns\TracksAuthors;

/**
 * @property int $id
 * @property string $name
 * @property array<int, array<string, mixed>>|null $content
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Template extends Model
{
    use TracksAuthors;

    protected $table = 'tbtop_templates';

    /**
     * created_by/updated_by are stamped by TracksAuthors, not mass-assigned —
     * see the trait's docblock for why they stay out of this list.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'content'];

    /** @var array<string, string> */
    protected $casts = [
        'content' => 'array',
    ];
}
