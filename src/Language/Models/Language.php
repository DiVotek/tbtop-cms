<?php

declare(strict_types=1);

namespace Tbtop\Cms\Language\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tbtop\Cms\Database\Factories\LanguageFactory;
use Tbtop\Cms\Language\Observers\LanguageObserver;

/**
 * @property int $id
 * @property string $name
 * @property string $locale
 * @property bool $is_default
 * @property bool $is_admin_active
 * @property bool $is_frontend_active
 * @property int $sort_order
 */
#[ObservedBy(LanguageObserver::class)]
class Language extends Model
{
    /** @use HasFactory<LanguageFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'tbtop_languages';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'locale',
        'is_default',
        'is_admin_active',
        'is_frontend_active',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_default' => 'boolean',
        'is_admin_active' => 'boolean',
        'is_frontend_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function newFactory(): LanguageFactory
    {
        return LanguageFactory::new();
    }
}
