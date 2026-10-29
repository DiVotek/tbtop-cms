<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/** `POST /pages/{page}/publish`: now, or at `published_at`. */
final class PublishPageRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['published_at' => ['nullable', 'date']];
    }

    public function publishedAt(): ?Carbon
    {
        $value = $this->validated('published_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
