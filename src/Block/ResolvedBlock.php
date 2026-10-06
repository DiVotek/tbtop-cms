<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ResolvedBlock implements Arrayable
{
    /** @param  array<string, mixed>  $props */
    public function __construct(
        public string $id,
        public string $kind,
        public array $props,
    ) {}

    /** @param  array<string, mixed>  $data  the shape toArray() produces */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['id'], (string) $data['kind'], (array) $data['props']);
    }

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
