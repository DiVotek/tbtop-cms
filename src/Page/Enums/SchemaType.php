<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Enums;

/**
 * The schema.org type a static page is published as. Only the WebPage
 * subtypes are listed: category and article pages derive their type from the
 * page type itself (CollectionPage / Article — see PageSchema) and are never
 * chosen by hand, so they have no place in this picker.
 *
 * The list is deliberately short. Every entry either earns a rich result
 * (ProfilePage) or is a plain, honest label for what the page is; subtypes
 * that would need data the CMS has no place to store were left out rather
 * than shipped as a select option that quietly emits nothing.
 */
enum SchemaType: string
{
    case WebPage = 'WebPage';
    case AboutPage = 'AboutPage';
    case ContactPage = 'ContactPage';
    case ProfilePage = 'ProfilePage';
    case ItemPage = 'ItemPage';

    public static function fromValue(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::WebPage : self::WebPage;
    }

    public function label(): string
    {
        return __('tbtop-cms::pages.schema_type.' . $this->value);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
