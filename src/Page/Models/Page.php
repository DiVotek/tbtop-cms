<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;
use Tbtop\Cms\Database\Factories\PageFactory;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Observers\PageObserver;

/**
 * @property int $id
 * @property string $type
 * @property int|null $parent_id
 * @property PageStatus $status
 * @property string|null $name
 * @property string|null $title
 * @property string|null $heading
 * @property string|null $summary
 * @property array<int, array<string, mixed>>|null $content
 * @property array<string, mixed>|null $seo
 * @property bool $is_indexable
 * @property bool $is_system
 * @property int $sort_order
 * @property int $lock_version
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ObservedBy(PageObserver::class)]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    use HasTranslations;
    use SoftDeletes;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name', 'title', 'heading', 'summary'];

    /**
     * Transient (not persisted, not an Eloquent attribute) per-locale slug
     * bases set by the create form before save(); PageObserver::saved()
     * reads and clears this to seed PageSlugWriter::sync() on first write.
     *
     * @var array<string, string>
     */
    public array $pendingSlugOverrides = [];

    /** @var array<string, string> */
    protected $casts = [
        'status' => PageStatus::class,
        'content' => 'array',
        'seo' => 'array',
        'is_indexable' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
        'lock_version' => 'integer',
    ];

    /** @return BelongsTo<Page, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Page, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<PageSlug, $this> */
    public function slugs(): HasMany
    {
        return $this->hasMany(PageSlug::class);
    }

    public function pathFor(string $locale): ?string
    {
        return $this->slugs->firstWhere('locale', $locale)?->path;
    }

    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }
}
