<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

/**
 * Package-internal: applied only to the built-in leaf classes, never to
 * WebPageFamily/ArticleFamily themselves. A host subclassing one of those
 * families does not get this trait, so it falls back to SchemaType::label()
 * (Str::headline($this->key())) instead of rendering a missing translation
 * key literally in the admin select.
 */
trait TranslatesSchemaLabel
{
    public function label(): string
    {
        return __('tbtop-cms::pages.schema_type.' . $this->key());
    }
}
