<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\Fields\PageFields;
use Tbtop\Cms\Api\PageApiWriter;

/** `POST /pages`: a draft under one of the token's parents, of the type the parent gives it. */
final class StorePageRequest extends FormRequest
{
    /** @var array{type: string, subtype: ?string}|null */
    private ?array $resolved = null;

    public function authorize(): bool
    {
        $parentId = $this->input('parent_id');
        if (! is_numeric($parentId)) {
            return false;
        }

        try {
            $resolved = $this->resolved();
        } catch (ValidationException) {
            return false;
        }

        return ApiAccess::scope($this)->allowsChild((int) $parentId, $resolved['type']);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        ['type' => $type, 'subtype' => $subtype] = $this->resolved();

        return [
            'parent_id' => ['required', 'integer'],
            'type' => ['sometimes', 'string'],
            ...PageFields::for($type, $subtype)->rules($this->all(), isCreate: true),
        ];
    }

    /** @return array{type: string, subtype: ?string} */
    private function resolved(): array
    {
        $type = $this->input('type');

        return $this->resolved ??= PageApiWriter::resolveType((int) $this->input('parent_id'), is_string($type) && $type !== '' ? $type : null);
    }
}
