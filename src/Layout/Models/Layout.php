<?php

declare(strict_types=1);

namespace Tbtop\Cms\Layout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Tbtop\Cms\Audit\Concerns\LogsCmsActivity;

/**
 * @property int $id
 * @property string $slot
 * @property array<int, array<string, mixed>>|null $content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Layout extends Model
{
    use LogsCmsActivity;

    protected $table = 'tbtop_layouts';

    /** @var list<string> */
    protected $fillable = ['slot', 'content'];

    /** @var array<string, string> */
    protected $casts = [
        'content' => 'array',
    ];

    /** @return list<string> */
    protected function auditFields(): array
    {
        return ['slot', 'content'];
    }

    protected function auditLabel(): string
    {
        return $this->slot;
    }

    /** @return list<string> */
    protected function auditBlockFields(): array
    {
        return ['content'];
    }
}
