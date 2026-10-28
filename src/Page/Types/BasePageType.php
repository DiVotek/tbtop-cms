<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Types;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\Support\FormResolver;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Models\Page;

abstract class BasePageType implements PageTypeInterface
{
    public function label(): string
    {
        return ucfirst($this->key());
    }

    /** @return list<string> */
    public function allowedChildren(): array
    {
        return [];
    }

    public function requiresParent(): bool
    {
        return false;
    }

    public function maxDepth(): int
    {
        return 1;
    }

    /** Content-only by default; a source-backed type overrides this. */
    public function isSourceBacked(): bool
    {
        return false;
    }

    public function source(Page $page): ?object
    {
        return null;
    }

    /** @return array<int, array<string, mixed>>|null */
    public function template(Page $page): ?array
    {
        return null;
    }

    /** @return non-empty-list<string> */
    public function schemaTypes(): array
    {
        return ['WebPage', 'AboutPage', 'ContactPage', 'ItemPage'];
    }

    public function base(): string
    {
        return $this->key();
    }

    public function childSubtype(): ?string
    {
        return null;
    }

    public function listedInAll(): bool
    {
        return true;
    }

    public function archiveOf(): ?string
    {
        return null;
    }

    /** @return list<Field> */
    public function fields(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolveFields(array $input, ResolveContext $ctx): array
    {
        return (new FormResolver)->resolve($this->fields(new S), $input, $ctx);
    }
}
