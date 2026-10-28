<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\URL;
use Tbtop\Cms\Settings\Settings;

/**
 * Builds /llms.txt (llmstxt.org): the site name as the H1, a one-paragraph
 * summary, the editor's free text and the contact details, then one H2 file
 * list per page type, the host's own sections, and an `## Optional` list an
 * agent may skip when its context is short.
 */
final readonly class LlmsTxt
{
    public function __construct(
        private Settings $settings,
        private LlmsTxtSettings $llms,
        private LlmsTxtPages $pages,
        private LlmsTxtContacts $contacts,
        private Container $container,
    ) {}

    public function render(string $locale, string $defaultLocale): string
    {
        $blocks = ['# ' . $this->siteName()];

        $summary = LlmsTxtMarkdown::inline($this->llms->summary($locale, $defaultLocale));
        if ($summary !== '') {
            $blocks[] = '> ' . $summary;
        }

        $intro = LlmsTxtMarkdown::block($this->llms->intro($locale, $defaultLocale));
        if ($intro !== '') {
            $blocks[] = $intro;
        }

        $contacts = $this->contacts->lines($locale, $defaultLocale);
        if ($contacts !== []) {
            $blocks[] = implode("\n", $contacts);
        }

        foreach ([...$this->pages->sections($locale), ...$this->hostSections($locale), ...$this->optional($locale)] as $section) {
            $blocks[] = self::section($section['title'], $section['links']);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /** The H1 is required: the site name, else the app URL's host. */
    private function siteName(): string
    {
        $name = LlmsTxtMarkdown::inline((string) $this->settings->get('site.name', ''));

        return $name !== '' ? $name : (string) parse_url(URL::to('/'), PHP_URL_HOST);
    }

    /** @return list<array{title: string, links: list<LlmsTxtLink>}> */
    private function hostSections(string $locale): array
    {
        $sections = [];

        foreach ((array) config('tbtop-cms.seo.llms_txt.sections', []) as $class) {
            $section = is_string($class) ? $this->container->make($class) : null;

            if (! $section instanceof LlmsTxtSection) {
                continue;
            }

            $links = $section->links($locale);

            if ($links !== []) {
                $sections[] = ['title' => $section->title($locale), 'links' => $links];
            }
        }

        return $sections;
    }

    /** @return list<array{title: string, links: list<LlmsTxtLink>}> */
    private function optional(string $locale): array
    {
        if (! config('tbtop-cms.seo.sitemap.enabled', true)) {
            return [];
        }

        return [[
            'title' => 'Optional',
            'links' => [new LlmsTxtLink(
                __('tbtop-cms::llms.optional.sitemap', [], $locale),
                URL::to('sitemap.xml'),
                __('tbtop-cms::llms.optional.sitemap_notes', [], $locale),
            )],
        ]];
    }

    /** @param  list<LlmsTxtLink>  $links */
    private static function section(string $title, array $links): string
    {
        $lines = ['## ' . LlmsTxtMarkdown::inline($title)];

        foreach ($links as $link) {
            $notes = LlmsTxtMarkdown::inline((string) $link->notes);
            $lines[] = '- [' . LlmsTxtMarkdown::linkText($link->name) . '](' . $link->url . ')' . ($notes !== '' ? ': ' . $notes : '');
        }

        return implode("\n", $lines);
    }
}
