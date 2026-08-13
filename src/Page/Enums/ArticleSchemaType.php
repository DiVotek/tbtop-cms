<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Enums;

/**
 * The schema.org Article flavour an article page is published as. All three
 * carry the same properties and the same rich-result eligibility — the choice
 * only tells a crawler what kind of writing it is, so Article stays the
 * default and the other two are opt-in.
 */
enum ArticleSchemaType: string
{
    case Article = 'Article';
    case NewsArticle = 'NewsArticle';
    case BlogPosting = 'BlogPosting';

    public static function fromValue(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::Article : self::Article;
    }

    public function label(): string
    {
        return __('tbtop-cms::pages.article_schema_type.' . $this->value);
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
