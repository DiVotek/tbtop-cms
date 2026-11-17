<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\FieldGroupGate;
use Tbtop\Cms\Api\Fields\CommonFields;
use Tbtop\Cms\Api\Fields\PageFields;
use Tbtop\Cms\Page\Models\Page;

/** `PATCH /pages/{page}`: only the keys sent are checked, and only they are written. */
final class UpdatePageRequest extends FormRequest
{
    use RefusesUnknownKeys;

    private ?Page $page = null;

    /** Loading the page is the check: a missing one is a 404, one out of the token's reach a 403. */
    public function authorize(): bool
    {
        $this->page();

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $page = $this->page();
        $rules = PageFields::for($page->type, $page->subtype)->rules(isCreate: false);
        if ($page->is_system) {
            foreach ($rules as $key => $entries) {
                if (str_ends_with($key, '.slug')) {
                    $rules[$key] = [...$entries, $this->refuseSystemSlug(...)];
                }
            }
        }

        return $rules;
    }

    /** A system page keeps its fixed path; the writer would ignore a slug, so a client is told instead. */
    private function refuseSystemSlug(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) !== '') {
            $fail(__('tbtop-cms::api.validation.system_slug'));
        }
    }

    /** Loads the page first (404, 403) so the field gate never answers for a page the token cannot reach. */
    protected function prepareForValidation(): void
    {
        $page = $this->page();
        FieldGroupGate::enforce(ApiAccess::scope($this), $this->all(), PageFields::for($page->type, $page->subtype));
    }

    /** @return list<string> */
    protected function acceptedKeys(): array
    {
        return CommonFields::UPDATE_KEYS;
    }

    public function page(): Page
    {
        return $this->page ??= ApiAccess::page($this, $this->route('page'), isWrite: true);
    }
}
