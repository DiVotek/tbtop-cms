<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Render;

class RichtextLinkRenderer
{
    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** @var list<string> */
    private const ALLOWED_TARGETS = ['_blank', '_self'];

    /** @param array<string, mixed> $node */
    public function render(array $node, string $children): string
    {
        $url = $node['url'] ?? null;
        $url = is_string($url) ? $url : '';

        if (! $this->isAllowedUrl($url)) {
            return $children;
        }

        $attrs = 'href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $this->extraAttributes($node);

        return "<a {$attrs}>{$children}</a>";
    }

    /** @param array<string, mixed> $node */
    private function extraAttributes(array $node): string
    {
        $target = $node['target'] ?? null;
        $title = $node['title'] ?? null;

        $attrs = '';

        if (is_string($title) && $title !== '') {
            $attrs .= ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"';
        }

        if (is_string($target) && in_array($target, self::ALLOWED_TARGETS, true)) {
            $attrs .= ' target="' . $target . '"';

            if ($target === '_blank') {
                $attrs .= ' rel="noopener noreferrer"';
            }
        }

        return $attrs;
    }

    private function isAllowedUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if ($url[0] === '/' || $url[0] === '#' || $url[0] === '?') {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === null) {
            return true;
        }

        return in_array(strtolower((string) $scheme), self::ALLOWED_SCHEMES, true);
    }
}
