<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

use Closure;
use Illuminate\Support\Facades\App;
use Tbtop\Cms\Language\Languages;

/** The locales an API request may write (admin- or frontend-active) and the ones publish requires (frontend-active). */
final readonly class ApiLocales
{
    public function __construct(private Languages $languages) {}

    public function default(): string
    {
        return $this->languages->defaultLocale();
    }

    /** @return list<string> */
    public function writable(): array
    {
        $locales = [];
        foreach ($this->languages->all() as $row) {
            if ($row['is_admin_active'] || $row['is_frontend_active'] || $row['locale'] === $this->default()) {
                $locales[] = $row['locale'];
            }
        }

        return $locales;
    }

    /** @return list<string> */
    public function active(): array
    {
        return array_column($this->languages->frontendActive(), 'locale');
    }

    /**
     * The type fields' rules (RuleWalker) are keyed per content locale; an API
     * request has no panel middleware to set them, and writes every writable locale.
     */
    public function syncContentLocales(): void
    {
        $locales = $this->writable();
        if ($locales === []) {
            return;
        }

        config([
            'tbtop-admin.content_locales' => $locales,
            'tbtop-admin.default_content_locale' => in_array($this->default(), $locales, true) ? $this->default() : $locales[0],
        ]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withDefaultLocale(Closure $callback): mixed
    {
        $default = $this->default();
        if ($default === '') {
            return $callback();
        }

        $previous = App::getLocale();
        App::setLocale($default);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
