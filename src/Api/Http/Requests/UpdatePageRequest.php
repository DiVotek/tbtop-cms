<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\Fields\PageFields;
use Tbtop\Cms\Page\Models\Page;

/** `PATCH /pages/{page}`: only the keys sent are checked, and only they are written. */
final class UpdatePageRequest extends FormRequest
{
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

        return PageFields::for($page->type, $page->subtype)->rules($this->all(), isCreate: false);
    }

    public function page(): Page
    {
        return $this->page ??= ApiAccess::page($this, $this->route('page'), isWrite: true);
    }
}
