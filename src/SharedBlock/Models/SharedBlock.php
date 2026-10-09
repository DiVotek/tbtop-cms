<?php

declare(strict_types=1);

namespace Tbtop\Cms\SharedBlock\Models;

use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Audit\Concerns\LogsCmsActivity;
use Tbtop\Cms\SharedBlock\Exceptions\SharedBlockConflictException;
use Tbtop\Cms\Support\LockVersion;

/**
 * One block's content edited in one place and placed on any number of pages
 * by reference: a page row `{id, kind, shared: <id>}` carries no data of its
 * own and takes this row's `data` at resolve time.
 *
 * @property int $id
 * @property string $name
 * @property string $kind
 * @property array<string, mixed> $data
 * @property int $lock_version
 */
class SharedBlock extends Model
{
    use LogsCmsActivity;

    protected $table = 'tbtop_shared_blocks';

    /** lock_version is bumped on every update below, never mass-assigned. */
    protected $fillable = ['name', 'kind', 'data'];

    protected $casts = [
        'data' => 'array',
        'lock_version' => 'integer',
    ];

    /** Transient: the lock_version the edit form was loaded at, set by SharedBlockForm::update(). */
    public ?int $expectedLockVersion = null;

    /**
     * At the model rather than in the form, so any write invalidates an open
     * edit form. Each bump is one SQL statement (LockVersion): a form save
     * claims the row against its loaded version, a no-op form save only
     * compares (keeping other forms valid), any other writer bumps relatively.
     */
    protected static function booted(): void
    {
        static::saving(static function (SharedBlock $block): void {
            if ($block->exists && ! $block->isDirty() && $block->expectedLockVersion !== null) {
                self::takeLock($block, write: false);
            }
        });

        static::updating(static function (SharedBlock $block): void {
            $block->expectedLockVersion === null
                ? LockVersion::bump($block)
                : self::takeLock($block, write: true);
        });
    }

    private static function takeLock(SharedBlock $block, bool $write): void
    {
        LockVersion::take(
            $block,
            (int) $block->expectedLockVersion,
            $write,
            static fn (): SharedBlockConflictException => new SharedBlockConflictException("Shared block #{$block->getKey()} was changed since this form was loaded."),
        );
    }

    protected function auditFields(): array
    {
        return ['name', 'kind', 'data'];
    }

    protected function auditLabel(): string
    {
        return $this->name;
    }
}
