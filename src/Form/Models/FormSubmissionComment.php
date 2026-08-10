<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $submission_id
 * @property int $user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormSubmissionComment extends Model
{
    protected $table = 'tbtop_form_submission_comments';

    /** @var list<string> */
    protected $fillable = ['submission_id', 'user_id', 'body'];

    /** @return BelongsTo<FormSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }
}
