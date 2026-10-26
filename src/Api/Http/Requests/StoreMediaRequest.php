<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Tbtop\Cms\Api\ApiAccess;

/** `POST /media`: a public `url` to import. The import's size cap is enforced by the admin's UrlFetcher. */
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
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:255'],
        ];
    }
}
