<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ResolvedBlock implements Arrayable
{
    /**
     * @param  array<string, mixed>  $props
     * @param  string|null  $error  why resolve() failed, in which case $props is empty and the client renders a
     *                              placeholder instead of the component (see CmsRenderer); null for a healthy block
     */
    public function __construct(
        public string $id,
        public string $kind,
        public array $props,
        public ?string $error = null,
    ) {}

    /** @param  array<string, mixed>  $data  the shape toArray() produces */
    public static function fromArray(array $data): self
    {
        $error = $data['error'] ?? null;

        return new self((string) $data['id'], (string) $data['kind'], (array) $data['props'], is_string($error) ? $error : null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'props' => $this->props,
            'error' => $this->error,
        ];
    }
}
