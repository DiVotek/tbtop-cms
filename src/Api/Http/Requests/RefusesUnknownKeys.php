<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * A body key the API does not write is a 422, not silently dropped: a client
 * sending `status` or `content` must learn that v1 ignores it.
 */
trait RefusesUnknownKeys
{
    /** @return list<string> */
    abstract protected function acceptedKeys(): array;

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_map('strval', array_keys($this->all())), $this->acceptedKeys()) as $key) {
                $validator->errors()->add($key, __('tbtop-cms::api.validation.unknown_key', ['key' => $key]));
            }
        }];
    }
}
