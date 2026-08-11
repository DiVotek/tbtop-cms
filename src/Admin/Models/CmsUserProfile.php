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
    protected $table = 'tbtop_user_profiles';

    /**
     * locale/display_name/avatar_media_id are written only via forceFill()
     * (ProfileActions::personal()/preferences()), which bypasses this list
     * entirely — user_id is the only mass-assignable column.
     *
     * @var list<string>
     */
    protected $fillable = ['user_id'];

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $this->belongsTo($model, 'user_id');
    }
}
