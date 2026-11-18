<?php

declare(strict_types=1);

namespace Tbtop\Cms\Redirect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Tbtop\Cms\Audit\Concerns\LogsCmsActivity;
use Tbtop\Cms\Support\Concerns\TracksAuthors;

/**
 * @property int $id
 * @property string|null $locale
 * @property string $from_path
 * @property string $to_path
 * @property int $status_code
 * @property bool $is_auto
 * @property int|null $created_via_token_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 */
class Redirect extends Model
{
    use LogsCmsActivity;
    use TracksAuthors;

    protected $table = 'tbtop_redirects';

    /**
     * created_by/updated_by are stamped by TracksAuthors, not mass-assigned —
     * see the trait's docblock for why they stay out of this list.
     *
     * @var list<string>
     */
    protected $fillable = ['locale', 'from_path', 'to_path', 'status_code', 'is_auto'];

    /** @var array<string, string> */
    protected $casts = [
        'status_code' => 'integer',
        'is_auto' => 'boolean',
        'created_via_token_id' => 'integer',
    ];

    /** @return list<string> */
    protected function auditFields(): array
    {
        return ['locale', 'from_path', 'to_path', 'status_code', 'is_auto'];
    }

    protected function auditLabel(): string
    {
        return $this->from_path !== '' ? $this->from_path : 'Redirect #' . $this->getKey();
    }
}
