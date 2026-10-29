<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Fields;

use Illuminate\Support\Facades\App;
use Tbtop\Cms\Api\ApiLocales;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Every field a page of one type and subtype takes through the API: the
 * common ones, then the type's own under `fields.`. `describe()` and
 * `rules()` read the same lists, so the description cannot promise a field
 * the request refuses or leave out one it requires.
 */
final readonly class PageFields
{
    private function __construct(public TypeFields $typeFields) {}

    /** Labels are read in the admin's default locale, as the site's editors see them. */
    public static function for(string $type, ?string $subtype): self
    {
        $registry = app(PageTypeRegistry::class);
        $types = array_values(array_filter(
            [$type, $subtype],
            static fn (?string $key): bool => is_string($key) && $key !== '' && $registry->has($key),
        ));

        $typeFields = App::make(ApiLocales::class)->withAdminLocale(static fn (): TypeFields => TypeFields::of(
            array_map(static fn (string $key): PageTypeInterface => $registry->get($key), $types),
        ));

        return new self($typeFields);
    }

    /** A base type, or a subtype together with its base. */
    public static function forTypeKey(string $key): self
    {
        $type = app(PageTypeRegistry::class)->get($key);

        return $type->base() === $key ? self::for($key, null) : self::for($type->base(), $key);
    }

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        $fields = App::make(ApiLocales::class)->withAdminLocale(static fn (): array => CommonFields::all());

        return [
            ...array_map(static fn (ApiField $field): array => $field->describe(), $fields),
            ...array_map(static function (ApiField $field): array {
                $described = $field->describe();
                $described['key'] = 'fields.' . $field->key;

                return $described;
            }, $this->typeFields->all()),
        ];
    }

    /**
     * $isCreate: a create must carry the required fields; an update checks only
     * the keys it sends, so every rule is `sometimes`.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(bool $isCreate): array
    {
        $locales = App::make(ApiLocales::class);
        $rules = [
            ...CommonFields::rules($locales->writable(), $locales->active(), $locales->default(), $isCreate),
            ...$this->typeFields->rules('fields.'),
        ];

        if ($isCreate) {
            return $rules;
        }

        return array_map(static fn (array $entries): array => ['sometimes', ...$entries], $rules);
    }
}
