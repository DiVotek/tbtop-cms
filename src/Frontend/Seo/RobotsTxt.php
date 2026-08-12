<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Illuminate\Support\Facades\URL;
use Tbtop\Cms\Settings\Settings;

/**
 * Builds robots.txt from two halves: the lines the CMS owns, and whatever the
 * editor adds under them.
 *
 * The split exists because the two halves have different failure modes. The
 * owned lines encode decisions made elsewhere in the admin — whether the site
 * is indexable, where the sitemap is — and letting a free-text field contradict
 * them would mean the indexing switch says one thing and robots.txt another.
 * The editor's half exists because no fixed set of toggles covers crawl-delay,
 * a rule for one bot, or a path only this site has.
 *
 * So the owned lines are written first and the custom block is appended after
 * them, with the directives that would fight the owned half filtered out (see
 * OWNED_DIRECTIVES). A rejected line is dropped rather than silently rewritten:
 * the settings form validates the same rule and tells the editor, so nothing
 * disappears without explanation.
 */
final readonly class RobotsTxt
{
    /**
     * Directives the CMS decides. `User-agent` is here because a custom block
     * starting with one would re-scope every owned rule above it: robots.txt
     * groups rules by the agent line that precedes them, so an editor writing
     * `User-agent: Googlebot` would quietly turn the site-wide `Allow: /` into
     * a Googlebot-only rule.
     */
    public const array OWNED_DIRECTIVES = ['user-agent', 'allow', 'disallow', 'sitemap'];

    public function __construct(
        private Settings $settings,
        private IndexingSetting $indexing,
    ) {}

    /**
     * The custom lines go *inside* the `User-agent: *` group, not after it.
     *
     * robots.txt binds a directive to the agent line above it, and a blank
     * line ends that group — so a custom block separated by one would parse as
     * a record with no agent and be discarded. The rules would be present in
     * the file and have no effect, which is the worst of both.
     *
     * Sitemap is the exception and trails the group: it is a top-level
     * directive belonging to no agent, so keeping it last leaves the group
     * contiguous.
     */
    public function render(): string
    {
        $group = ['User-agent: *', $this->indexing->enabled() ? 'Allow: /' : 'Disallow: /'];

        $custom = self::customLines((string) $this->settings->get('seo.robots_extra', ''));

        $lines = [...$group, ...$custom, ...$this->sitemapLines()];

        return implode("\n", [...$lines, '']);
    }

    /**
     * The editor's lines, minus anything claiming an owned directive.
     *
     * Comments and blank lines pass through — they are how an editor explains
     * a rule to the next person, and neither can contradict anything.
     *
     * @return list<string>
     */
    public static function customLines(string $raw): array
    {
        $lines = [];

        foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
            $line = rtrim($line);

            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                $lines[] = $line;

                continue;
            }

            if (! self::claimsOwnedDirective($line)) {
                $lines[] = $line;
            }
        }

        // A block that was nothing but rejected lines leaves trailing blanks
        // behind; an empty tail would print a stray newline for no content.
        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /** True when the line sets a directive the CMS owns. */
    public static function claimsOwnedDirective(string $line): bool
    {
        $directive = mb_strtolower(trim(explode(':', $line, 2)[0]));

        return in_array($directive, self::OWNED_DIRECTIVES, true);
    }

    /**
     * Absent when indexing is off: a site telling crawlers to stay out has no
     * business advertising a sitemap they are not meant to fetch, and the
     * route serving it 404s in that state anyway.
     *
     * @return list<string>
     */
    private function sitemapLines(): array
    {
        if (! $this->indexing->enabled() || ! config('tbtop-cms.seo.sitemap.enabled', true)) {
            return [];
        }

        return ['', 'Sitemap: ' . URL::to('sitemap.xml')];
    }
}
