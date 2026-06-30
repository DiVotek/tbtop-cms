<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ResolvedBlock implements Arrayable
{
    /** @param array<string, mixed> $props */
    public function __construct(
        public string $id,
        public string $kind,
        public array $props,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'props' => $this->props,
        ];
    }
}
