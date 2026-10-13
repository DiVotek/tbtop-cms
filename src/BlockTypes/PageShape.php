<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

/** One `page` variant for the emitter: a base type (subtype null) or a subtype over its base. */
final readonly class PageShape
{
    /**
     * @param  array<string, string>  $fields  `page.fields` key => zod fragment, in the order the resolver merges them
     * @param  bool  $isLoose  a part's keys are unknown, so extra keys pass as unknown
     * @param  array<string, true>  $usedAliases
     */
    public function __construct(
        public string $key,
        public string $type,
        public ?string $subtype,
        public array $fields,
        public bool $isLoose,
        public array $usedAliases,
    ) {}
}
