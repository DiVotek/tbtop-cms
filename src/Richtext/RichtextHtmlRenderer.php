<?php

declare(strict_types=1);

namespace Tbtop\Cms\Richtext;

class RichtextHtmlRenderer
{
    /** The admin editor's own test for "this stored string is HTML" (richtextInitialState.ts). */
    private const HTML_PATTERN = '/^\s*<[a-z][a-z0-9]*[\s\/>]/i';

    public function __construct(
        private readonly RichtextNodeRenderer $nodeRenderer = new RichtextNodeRenderer,
        private readonly RichtextHtmlToNodes $htmlToNodes = new RichtextHtmlToNodes,
    ) {}

    public function render(mixed $value): string
    {
        $state = $this->normalize($value);

        if ($state === null) {
            return '';
        }

        $root = $state['root'] ?? null;

        if (! is_array($root)) {
            return '';
        }

        return $this->nodeRenderer->renderChildren($root);
    }

    /** @return array{root: mixed}|null */
    private function normalize(mixed $value): ?array
    {
        if (is_array($value) && isset($value['root'])) {
            return $value;
        }

        if (is_string($value)) {
            return $this->normalizeString($value);
        }

        return null;
    }

    /** @return array{root: mixed}|null */
    private function normalizeString(string $value): ?array
    {
        $decoded = json_decode($value, true);

        if (is_array($decoded) && isset($decoded['root'])) {
            return $decoded;
        }

        if (trim($value) === '') {
            return null;
        }

        if (preg_match(self::HTML_PATTERN, $value) === 1) {
            return $this->htmlToNodes->convert($value);
        }

        return [
            'root' => [
                'children' => [
                    [
                        'type' => 'paragraph',
                        'children' => [
                            ['type' => 'text', 'text' => $value, 'format' => 0],
                        ],
                    ],
                ],
            ],
        ];
    }
}
