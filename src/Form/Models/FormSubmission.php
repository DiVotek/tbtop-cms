<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Page\Models\Page;

/**
 * @property int $id
 * @property int|null $page_id
 * @property string $block_id
 * @property string $kind
 * @property string $locale
 * @property array<string, mixed> $payload
 * @property SubmissionStatus $status
 * @property LeadStatus $lead_status
 * @property string|null $error
 * @property Carbon|null $handled_at
 * @property Carbon|null $handler_handled_at
 * @property string|null $handler_error
 * @property Carbon|null $notification_handled_at
 * @property string|null $notification_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormSubmission extends Model
{
    /**
     * handled_at is written only via forceFill() (SubmissionRetrier,
     * FormSubmitController) and stays out of this list on purpose; every
     * other column here is mass-assigned somewhere (created_at is set
     * explicitly in dashboard/list tests to control ordering).
     *
     * @var list<string>
     */
    protected $fillable = [
        'page_id',
        'block_id',
        'kind',
        'locale',
        'payload',
        'status',
        'lead_status',
        'error',
        'created_at',
    ];

    protected $table = 'tbtop_form_submissions';

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'status' => SubmissionStatus::class,
        'lead_status' => LeadStatus::class,
        'handled_at' => 'datetime',
        'handler_handled_at' => 'datetime',
        'notification_handled_at' => 'datetime',
    ];

    public function markHandlerHandled(): void
    {
        $this->forceFill(['handler_handled_at' => now(), 'handler_error' => null])->save();
        $this->syncDeliveryStatus();
    }

    public function markHandlerFailed(string $error): void
    {
        $this->forceFill(['handler_error' => $error])->save();
        $this->syncDeliveryStatus();
    }

    public function markNotificationHandled(): void
    {
        $this->forceFill(['notification_handled_at' => now(), 'notification_error' => null])->save();
        $this->syncDeliveryStatus();
    }

    public function markNotificationFailed(string $error): void
    {
        $this->forceFill(['notification_error' => $error])->save();
        $this->syncDeliveryStatus();
    }

    public function syncDeliveryStatus(): void
    {
        $errors = array_values(array_filter([$this->handler_error, $this->notification_error]));
        $handled = $this->handler_handled_at !== null && $this->notification_handled_at !== null;

        $this->forceFill([
            'status' => $handled ? SubmissionStatus::Handled : ($errors === [] ? SubmissionStatus::Pending : SubmissionStatus::Failed),
            'handled_at' => $handled ? ($this->handled_at ?? now()) : null,
            'error' => $errors === [] ? null : implode(' | ', $errors),
        ])->save();
    }

    /** @param  Builder<self>  $query */
    public function scopeUnread(Builder $query): void
    {
        $query->where('lead_status', LeadStatus::New);
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Oldest first — the detail page renders comments as a chronological thread.
     *
     * @return HasMany<FormSubmissionComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(FormSubmissionComment::class, 'submission_id')->oldest();
    }
}
