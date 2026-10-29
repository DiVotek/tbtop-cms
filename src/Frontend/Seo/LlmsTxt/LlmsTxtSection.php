<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

/**
 * A host's own `## title` section of /llms.txt, listed in the
 * `tbtop-cms.seo.llms_txt.sections` config and resolved from the container.
 * It renders after the page-type sections; a section with no links is left
 * out.
 */
interface LlmsTxtSection
{
    public function title(string $locale): string;

    /** @return list<LlmsTxtLink> */
    public function links(string $locale): array;
}
