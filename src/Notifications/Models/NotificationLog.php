<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Tbtop\Cms\Notifications\Audience;
use Tbtop\Cms\Notifications\NotificationLogStatus;

/**
 * @property int $id
 * @property string $type
 * @property string $channel
 * @property Audience $audience
 * @property string $recipient
 * @property string $locale
 * @property NotificationLogStatus $status
 * @property string|null $error
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationLog extends Model
{
    protected $table = 'tbtop_notification_log';

    /** @var list<string> */
    protected $fillable = [
        'type', 'channel', 'audience', 'recipient', 'locale', 'status', 'error', 'sent_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'audience' => Audience::class,
        'status' => NotificationLogStatus::class,
        'sent_at' => 'datetime',
    ];
}
