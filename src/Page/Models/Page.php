<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;
use Tbtop\Cms\Audit\Concerns\LogsCmsActivity;
use Tbtop\Cms\Database\Factories\PageFactory;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Enums\PageDisplayState;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Observers\PageObserver;
use Tbtop\Cms\Support\Concerns\TracksAuthors;

/**
 * @property int $id
 * @property string $type
 * @property string|null $subtype
 * @property string|null $source_id
 * @property int|null $parent_id
 * @property PageStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $unpublish_at
 * @property string|null $name
 * @property string|null $title
 * @property string|null $heading
 * @property string|null $summary
 * @property array<int, array<string, mixed>>|null $content
 * @property array<string, mixed>|null $fields
 * @property array{article?: array<int, array<string, mixed>>, category?: array<int, array<string, mixed>>}|null $starter_blocks
 * @property string|null $image
 * @property array<string, mixed>|null $seo
 * @property bool $is_indexable
 * @property bool $is_system
 * @property ErrorClass|null $error_class
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
    use LogsCmsActivity;
    use SoftDeletes;
    use TracksAuthors;

    protected $table = 'tbtop_pages';

    /**
     * created_by/updated_by are stamped by TracksAuthors, not mass-assigned —
     * they stay out of $fillable on purpose (see the trait's docblock).
     * lock_version is bumped by PageObserver::saving(), also never mass-
     * assigned. created_at is mass-assigned explicitly in list-ordering
     * tests.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'subtype',
        'source_id',
        'parent_id',
        'status',
        'published_at',
        'unpublish_at',
        'name',
        'title',
        'heading',
        'summary',
        'body',
        'image',
        'content',
        'fields',
        'starter_blocks',
        'seo',
        'is_indexable',
        'is_system',
        'error_class',
        'sort_order',
        'created_at',
    ];

    /** @var list<string> */
    public array $translatable = ['name', 'title', 'heading', 'summary', 'body'];

    /**
     * Transient (not persisted, not an Eloquent attribute) per-locale slug
     * bases set by the create form before save(); PageObserver::saved()
     * reads and clears this to seed PageSlugWriter::sync() on first write.
     *
     * @var array<string, string>
     */
    public array $pendingSlugOverrides = [];

    /**
     * Transient marker set by PageRevisionWriter::restore() immediately
     * before save() (and cleared by it in a finally), read by the
     * PageRevisions observer's saved() hook to label the resulting snapshot
     * kind='restore' instead of 'save'. Lives on the model instance rather
     * than on the writer service:
     * the writer has no container binding forcing it to be shared, so the
     * instance resolved for the observer's saved() dispatch (a fresh
     * "Class@method" string-listener resolution) is not guaranteed to be the
     * same writer instance restore() called — the Page instance flowing
     * through the event IS guaranteed to be the same object, exactly like
     * $pendingSlugOverrides above.
     */
    public bool $pendingRestore = false;

    /** @var array<string, string> */
    protected $casts = [
        'status' => PageStatus::class,
        'published_at' => 'datetime',
        'unpublish_at' => 'datetime',
        'content' => 'array',
        'fields' => 'array',
        'starter_blocks' => 'array',
        'seo' => 'array',
        'is_indexable' => 'boolean',
        'is_system' => 'boolean',
        'error_class' => ErrorClass::class,
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

    /**
     * Single source of truth for frontend visibility: published, and (if
     * scheduled) currently within its go-live/go-dark window. Every
     * frontend-facing query that used to filter on status alone should use
     * this instead — see PageResolver/SitemapEntryBuilder/FormSubmitController.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query->where('status', PageStatus::Published->value)
            ->where(static fn (Builder $q): Builder => $q->whereNull('published_at')->orWhere('published_at', '<=', $now))
            ->where(static fn (Builder $q): Builder => $q->whereNull('unpublish_at')->orWhere('unpublish_at', '>', $now));
    }

    public function isLive(): bool
    {
        return $this->displayState() === PageDisplayState::Published;
    }

    public function isErrorPage(): bool
    {
        return $this->error_class !== null;
    }

    /** The computed state PageEditStatusBar/PagesPage/DashboardRecent display, folding the schedule into PageStatus's plain draft/published. */
    public function displayState(): PageDisplayState
    {
        if ($this->status !== PageStatus::Published) {
            return PageDisplayState::Draft;
        }

        $now = Carbon::now();

        if ($this->published_at !== null && $this->published_at->greaterThan($now)) {
            return PageDisplayState::Scheduled;
        }

        if ($this->unpublish_at !== null && ! $this->unpublish_at->greaterThan($now)) {
            return PageDisplayState::Expired;
        }

        return PageDisplayState::Published;
    }

    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }

    /** @return list<string> */
    protected function auditFields(): array
    {
        return [
            'type', 'subtype', 'parent_id', 'status', 'published_at', 'unpublish_at',
            'name', 'title', 'heading', 'summary', 'body', 'image', 'content',
            'fields', 'seo', 'is_indexable', 'is_system', 'error_class', 'sort_order',
        ];
    }

    protected function auditLabel(): string
    {
        return is_string($this->name) && $this->name !== '' ? $this->name : 'Page #' . $this->getKey();
    }

    /** @return list<string> */
    protected function auditMarkerFields(): array
    {
        return ['body', 'content', 'fields', 'seo'];
    }
}
