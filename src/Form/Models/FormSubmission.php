<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormSubmission extends Model
{
    protected $guarded = [];

    protected $table = 'cms_form_submissions';

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'status' => SubmissionStatus::class,
        'lead_status' => LeadStatus::class,
        'handled_at' => 'datetime',
    ];

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
}
