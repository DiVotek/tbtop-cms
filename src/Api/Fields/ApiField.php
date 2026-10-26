<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Fields;

/** One writable field of a page as the API describes it; PageFields derives its rules from the same instance. */
final readonly class ApiField
{
    /**
     * @param  list<array{value: string, label: string}>|null  $options  A select's fixed options; null when it has none.
     * @param  list<ApiField>  $children  A repeater's row fields.
     */
    public function __construct(
        public string $key,
        public string $label,
        public FieldKind $kind,
        public bool $isRequired,
        public bool $isTranslatable,
        public bool $isMultiple = false,
        public ?array $options = null,
        public array $children = [],
    ) {}

    /** @return array{key: string, label: string, kind: string, required: bool, translatable: bool, multiple?: true, options?: list<array{value: string, label: string}>, children?: list<array<string, mixed>>} */
    public function describe(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'required' => $this->isRequired,
            'translatable' => $this->isTranslatable,
            ...($this->isMultiple ? ['multiple' => true] : []),
            ...($this->options !== null ? ['options' => $this->options] : []),
            ...($this->children !== [] ? ['children' => array_map(static fn (self $child): array => $child->describe(), $this->children)] : []),
        ];
    }
}
