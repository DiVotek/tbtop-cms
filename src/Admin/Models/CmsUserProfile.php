<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $locale
 * @property string|null $avatar_media_id
 * @property string|null $display_name
 */
class CmsUserProfile extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $this->belongsTo($model, 'user_id');
    }
}
