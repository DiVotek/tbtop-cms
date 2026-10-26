<?php

declare(strict_types=1);

namespace Tbtop\Cms\Richtext;

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

    /** Also the API's check on a link it is sent (Api/Fields/RestrictedHtml), so both refuse the same URLs. */
    public function isAllowedUrl(string $url): bool
    {
        // Browsers delete tab/CR/LF anywhere in a URL and trim C0 controls
        // and spaces from its ends before parsing, so " javascript:alert(1)"
        // and "java\tscript:..." execute as javascript: — validate what the
        // browser will parse, not the raw string.
        $url = str_replace(["\t", "\r", "\n"], '', $url);
        $url = trim($url, "\x00..\x20");

        if ($url === '') {
            return false;
        }

        if ($url[0] === '/' || $url[0] === '#' || $url[0] === '?') {
            return true;
        }

        // Read the scheme the way the URL spec does, not via parse_url(): it
        // takes "javascript:1/alert(1)" for host:port and reports no scheme.
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $match) !== 1) {
            return true;
        }

        return in_array(strtolower($match[1]), self::ALLOWED_SCHEMES, true);
    }
}
