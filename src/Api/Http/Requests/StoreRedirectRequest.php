<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Tbtop\Cms\Api\ApiLocales;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Redirect\Support\RedirectPath;

/**
 * `POST /redirects`: the admin's own redirect rules (RedirectValidator, run
 * by RedirectForm::save()) plus one the API adds: the target is a published
 * page of this site with a slug in the redirect's locale, the home page
 * included, an error page not. An external host, a draft or a dead path is a
 * 422 on `to_path`, so a token cannot send visitors off the site.
 */
final class StoreRedirectRequest extends FormRequest
{
    use RefusesUnknownKeys;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'from_path' => ['required', 'string', 'max:600'],
            'to_path' => ['required', 'string', 'max:2048', $this->isPublishedPage(...)],
            'locale' => ['required', 'string', Rule::in(app(ApiLocales::class)->writable())],
            'status_code' => ['sometimes', 'integer'],
        ];
    }

    /** @return list<string> */
    protected function acceptedKeys(): array
    {
        return ['from_path', 'to_path', 'locale', 'status_code'];
    }

    /** @return array{from_path: string, to_path: string, locale: string, status_code: int} */
    public function redirect(): array
    {
        return [
            'from_path' => (string) $this->validated('from_path'),
            'to_path' => (string) $this->validated('to_path'),
            'locale' => (string) $this->validated('locale'),
            'status_code' => (int) ($this->validated('status_code') ?? 301),
        ];
    }

    /** @param  Closure(string): mixed  $fail */
    private function isPublishedPage(string $attribute, mixed $value, Closure $fail): void
    {
        $locale = $this->input('locale');
        if (! is_string($value) || ! is_string($locale)) {
            return;
        }

        $target = trim($value);
        $isPage = ! RedirectPath::isExternal($target) && PageSlug::query()
            ->where('locale', $locale)
            ->where('path', RedirectPath::normalize($target))
            ->whereHas('page', static fn ($page) => $page->live()->whereNull('error_class'))
            ->exists();

        if (! $isPage) {
            $fail(__('tbtop-cms::api.validation.redirect_target'));
        }
    }
}
