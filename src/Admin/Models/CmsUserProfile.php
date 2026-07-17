<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tbtop\Cms\Admin\Enums\CmsRole;

/**
 * @property int $id
 * @property int $user_id
 * @property CmsRole $role
 * @property string|null $locale
 * @property string|null $avatar_media_id
 * @property string|null $display_name
 */
class CmsUserProfile extends Model
{
    /**
     * locale/display_name/avatar_media_id are written only via forceFill()
     * (ProfileActions::personal()/preferences()), which bypasses this list
     * entirely — user_id is the only column ever set through mass assignment.
     * role is written explicitly by UsersPage's grant/change-role actions
     * (also via forceFill, mirroring the other admin-only columns).
     *
     * @var list<string>
     */
    protected $fillable = ['user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => CmsRole::class];
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $this->belongsTo($model, 'user_id');
    }
}
