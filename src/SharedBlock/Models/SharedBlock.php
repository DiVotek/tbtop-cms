<?php

declare(strict_types=1);

namespace Tbtop\Cms\SharedBlock\Models;

use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Audit\Concerns\LogsCmsActivity;

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

    /**
     * At the model rather than in the form, so any write invalidates an open
     * edit form. updating() only fires for a dirty model, so a no-op save
     * keeps the version.
     */
    protected static function booted(): void
    {
        static::updating(static function (SharedBlock $block): void {
            $block->lock_version = (int) $block->getOriginal('lock_version') + 1;
        });
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
