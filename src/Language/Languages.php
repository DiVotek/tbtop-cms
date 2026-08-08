<?php

declare(strict_types=1);

namespace Tbtop\Cms\Language;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Language\Models\Language;

/**
 * @phpstan-type LanguageRow array{id: int, name: string, locale: string, is_default: bool, is_admin_active: bool, is_frontend_active: bool, sort_order: int}
 */
class Languages
{
    private const string CACHE_KEY = 'tbtop-cms.languages';

    /** @var list<LanguageRow>|null */
    private ?array $cache = null;

    private ?string $currentLocale = null;

    public function __construct(private readonly CmsCache $contentCache = new CmsCache) {}

    /** @return list<LanguageRow> */
    public function all(): array
    {
        return $this->load();
    }

    /** @return LanguageRow|null */
    public function default(): ?array
    {
        foreach ($this->load() as $row) {
            if ($row['is_default']) {
                return $row;
            }
        }

        return null;
    }

    public function defaultLocale(): string
    {
        $default = $this->default();

        return $default === null ? '' : $default['locale'];
    }

    /** @return LanguageRow|null */
    public function current(): ?array
    {
        if ($this->currentLocale !== null) {
            $row = $this->find($this->currentLocale);

            if ($row !== null) {
                return $row;
            }
        }

        return $this->default();
    }

    public function setCurrent(string $locale): void
    {
        $row = $this->find($locale);

        if ($row === null || ! $row['is_frontend_active']) {
            throw new InvalidArgumentException("Language '{$locale}' is not frontend-active.");
        }

        $this->currentLocale = $locale;
        app()->setLocale($locale);
    }

    /** @return list<LanguageRow> */
    public function frontendActive(): array
    {
        return array_values(array_filter(
            $this->load(),
            static fn (array $row): bool => $row['is_frontend_active'],
        ));
    }

    /** @return list<LanguageRow> */
    public function adminActive(): array
    {
        return array_values(array_filter(
            $this->load(),
            static fn (array $row): bool => $row['is_admin_active'],
        ));
    }

    public function setDefault(string $locale): void
    {
        DB::transaction(function () use ($locale): void {
            $target = Language::query()->where('locale', $locale)->lockForUpdate()->first();

            if ($target === null) {
                throw new InvalidArgumentException("Language '{$locale}' does not exist.");
            }

            Language::query()->where('is_default', true)->lockForUpdate()->update(['is_default' => false]);
            Language::query()->whereKey($target->getKey())->update([
                'is_default' => true,
                'is_admin_active' => true,
                'is_frontend_active' => true,
            ]);
        });

        $this->refresh();
    }

    /**
     * Forces this instance to reflect a write it (or a caller in the same
     * request) just made — bypasses the cross-request cache on purpose:
     * LanguageObserver calls this before LanguagesChanged is dispatched, so
     * reading through the cache here would read/write against a version
     * that's about to be bumped anyway, for no benefit.
     */
    public function refresh(): void
    {
        $this->cache = $this->queryFromDatabase();
        $this->syncFallbackLocale();
    }

    /** @return LanguageRow|null */
    private function find(string $locale): ?array
    {
        foreach ($this->load() as $row) {
            if ($row['locale'] === $locale) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<LanguageRow> */
    private function load(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->contentCache->remember(self::CACHE_KEY, fn (): array => $this->queryFromDatabase());
            $this->syncFallbackLocale();
        }

        return $this->cache;
    }

    /** @return list<LanguageRow> */
    private function queryFromDatabase(): array
    {
        if (! Schema::hasTable((new Language)->getTable())) {
            return [];
        }

        return Language::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Language $language): array => [
                'id' => $language->id,
                'name' => $language->name,
                'locale' => $language->locale,
                'is_default' => $language->is_default,
                'is_admin_active' => $language->is_admin_active,
                'is_frontend_active' => $language->is_frontend_active,
                'sort_order' => $language->sort_order,
            ])
            ->values()
            ->all();
    }

    private function syncFallbackLocale(): void
    {
        $default = $this->default();

        if ($default !== null) {
            app()->setFallbackLocale($default['locale']);
        }
    }
}
