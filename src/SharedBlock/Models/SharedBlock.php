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
 */
class SharedBlock extends Model
{
    use LogsCmsActivity;

    protected $table = 'tbtop_shared_blocks';

    protected $fillable = ['name', 'kind', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    protected function auditFields(): array
    {
        return ['name', 'kind', 'data'];
    }

    protected function auditLabel(): string
    {
        return $this->name;
    }
}
