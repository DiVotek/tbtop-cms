<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Tbtop\Cms\Notifications\Format;

/**
 * @property int $id
 * @property string $type
 * @property Format $format
 * @property string $locale
 * @property string $subject
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationTemplate extends Model
{
    protected $table = 'tbtop_notification_templates';

    /** @var list<string> */
    protected $fillable = [
        'type', 'format', 'locale', 'subject', 'body',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'format' => Format::class,
    ];
}
