<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
 * @property string|null $error
 * @property Carbon|null $handled_at
 */
class FormSubmission extends Model
{
    protected $guarded = [];

    protected $table = 'cms_form_submissions';

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'status' => SubmissionStatus::class,
        'handled_at' => 'datetime',
    ];

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
