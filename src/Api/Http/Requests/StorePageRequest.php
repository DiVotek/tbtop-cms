<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\Fields\CommonFields;
use Tbtop\Cms\Api\Fields\PageFields;
use Tbtop\Cms\Api\PageApiWriter;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * `POST /pages`: a draft under one of the token's parents, of the type the
 * parent gives it. A parent that is missing or takes no such child is a 422
 * on `parent_id`; a valid parent outside the token's reach is a 403, checked
 * after validation so the two never blur.
 */
final class StorePageRequest extends FormRequest
{
    use RefusesUnknownKeys;

    /** @var array{type: string, subtype: ?string}|null */
    private ?array $resolved = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        ['type' => $type, 'subtype' => $subtype] = $this->resolved();

        return [
            'parent_id' => ['bail', 'required', 'integer', $this->parentTakesChild(...)],
            ...PageFields::for($type, $subtype)->rules(isCreate: true),
        ];
    }

    /** @return array{type: string, subtype: ?string} */
    public function resolved(): array
    {
        return $this->resolved ??= PageApiWriter::resolveType($this->parentId());
    }

    protected function passedValidation(): void
    {
        abort_unless(
            ApiAccess::scope($this)->allowsChild($this->parentId(), $this->resolved()['type']),
            403,
            __('tbtop-cms::api.errors.out_of_scope'),
        );
    }

    /** @return list<string> */
    protected function acceptedKeys(): array
    {
        return CommonFields::CREATE_KEYS;
    }

    /** The hierarchy rule the save would enforce (PageHierarchy), answered as a field error before anything is written. */
    private function parentTakesChild(string $attribute, mixed $value, Closure $fail): void
    {
        $parent = Page::query()->find($this->parentId());
        if ($parent === null) {
            $fail(__('tbtop-cms::pages.validation.parent_missing', ['id' => (string) $value]));

            return;
        }

        $type = $this->resolved()['type'];
        $registry = app(PageTypeRegistry::class);
        if (! $registry->has($parent->type) || ! in_array($type, $registry->get($parent->type)->allowedChildren(), true)) {
            $fail(__('tbtop-cms::pages.validation.parent_disallows_child', ['parent' => $parent->type, 'type' => $type]));
        }
    }

    private function parentId(): int
    {
        $id = $this->input('parent_id');

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
    }
}
