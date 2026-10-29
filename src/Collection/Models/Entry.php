<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Audit\Concerns\LogsCmsActivity;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Collection\Events\EntryDeleted;
use Tbtop\Cms\Collection\Events\EntrySaved;
use Tbtop\Cms\Collection\Exceptions\EntryConflictException;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Support\LockVersion;

/**
 * One record of a code-defined collection (CollectionRegistry): its field
 * values in `data`, translatable ones as per-locale maps. Only `published`
 * entries reach the site. History is the audit log; there are no revisions.
 *
 * @property int $id
 * @property string $collection
 * @property array<string, mixed> $data
 * @property string $title
 * @property string $status
 * @property int $sort_order
 * @property int $lock_version
 */
class Entry extends Model
{
    use LogsCmsActivity;

    public const string DRAFT = 'draft';

    public const string PUBLISHED = 'published';

    protected $table = 'tbtop_entries';

    /** lock_version is bumped on every update below, never mass-assigned. */
    protected $fillable = ['collection', 'data', 'status', 'sort_order'];

    protected $casts = [
        'data' => 'array',
        'sort_order' => 'integer',
        'lock_version' => 'integer',
    ];

    protected $attributes = [
        'data' => '[]',
        'status' => self::DRAFT,
        'sort_order' => 0,
    ];

    /** Transient: the lock_version the edit form was loaded at, set by EntryForm::update(). */
    public ?int $expectedLockVersion = null;

    /**
     * The title column first, then the same optimistic lock as SharedBlock
     * (any write invalidates an open edit form), then the events every write
     * reports, so the site's cached collection follows any writer.
     */
    protected static function booted(): void
    {
        static::saving(static function (Entry $entry): void {
            $default = app(Languages::class)->defaultLocale();
            $title = $entry->titleIn($default, $default);
            $entry->title = mb_substr($title === '#' . $entry->getKey() ? '' : $title, 0, 255);
        });

        static::saving(static function (Entry $entry): void {
            if ($entry->exists && ! $entry->isDirty() && $entry->expectedLockVersion !== null) {
                self::takeLock($entry, write: false);
            }
        });

        static::updating(static function (Entry $entry): void {
            $entry->expectedLockVersion === null
                ? LockVersion::bump($entry)
                : self::takeLock($entry, write: true);
        });

        // After the commit, as PageSaved: the cache bump must not precede it.
        static::saved(static fn (Entry $entry) => DB::afterCommit(static fn () => event(new EntrySaved($entry))));
        static::deleted(static fn (Entry $entry) => DB::afterCommit(static fn () => event(new EntryDeleted($entry))));
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return EntryBuilder<Entry>
     */
    #[\Override]
    public function newEloquentBuilder($query): EntryBuilder
    {
        return new EntryBuilder($query);
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    public function scopeOf(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /** The entry's name in a locale: its collection's title field, else `#id`. */
    public function titleIn(string $locale, ?string $fallback = null): string
    {
        $registry = app(CollectionRegistry::class);
        $field = $registry->has($this->collection) ? $registry->get($this->collection)->titleField() : null;
        $value = $field === null ? null : ($this->data[$field] ?? null);

        if (is_array($value)) {
            $value = $value[$locale] ?? ($fallback === null ? null : $value[$fallback] ?? null) ?? array_values(array_filter($value, is_string(...)))[0] ?? null;
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : '#' . $this->getKey();
    }

    private static function takeLock(Entry $entry, bool $write): void
    {
        LockVersion::take(
            $entry,
            (int) $entry->expectedLockVersion,
            $write,
            static fn (): EntryConflictException => new EntryConflictException("Entry #{$entry->getKey()} was changed since this form was loaded."),
        );
    }

    protected function auditFields(): array
    {
        return ['data', 'status'];
    }

    protected function auditLabel(): string
    {
        return $this->titleIn((string) app()->getLocale());
    }
}
