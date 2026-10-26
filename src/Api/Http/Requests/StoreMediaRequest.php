<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Tbtop\Admin\Media\MediaUploadLimit;
use Tbtop\Cms\Api\ApiAccess;

/** `POST /media`: exactly one of a public `url` to import or an uploaded `file`. MediaIngest does not check the size, so this does. */
final class StoreMediaRequest extends FormRequest
{
    /** A token that reaches no page (a host's `*` token) may not fill the library either. */
    public function authorize(): bool
    {
        return ApiAccess::scope($this)->reachesAnything();
    }

    protected function failedAuthorization(): never
    {
        abort(403, __('tbtop-cms::api.errors.out_of_scope'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'url' => ['required_without:file', 'prohibits:file', 'string', 'url:http,https', 'max:2048'],
            'file' => ['required_without:url', 'file', 'max:' . MediaUploadLimit::kilobytes()],
            'name' => ['nullable', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:255'],
        ];
    }
}
