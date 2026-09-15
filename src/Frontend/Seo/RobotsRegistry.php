<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

/**
 * The paths robots.txt closes to crawlers: the ones the CMS owns, plus the
 * host's config and other packages.
 *
 * Exists because `Disallow` is an owned directive (see RobotsTxt), so the
 * editor's free-text field cannot carry these lines.
 */
class RobotsRegistry
{
    /** @var list<callable(): list<string>> */
    private static array $extensions = [];

    /**
     * @param  callable(): list<string>  $extension  invoked on every read
     *
     * Register from boot()/packageBooted(), not register().
     */
    public static function extend(callable $extension): void
    {
        self::$extensions[] = $extension;
    }

    /** Test isolation; the cms TestCase calls it between cases. */
    public static function flushExtensions(): void
    {
        self::$extensions = [];
    }

    /**
     * Deliberately not latched like BlockRegistry: recomputing a handful of
     * strings is free, and latching would silently drop a late extend().
     *
     * @return list<string>
     */
    public function all(): array
    {
        /** @var array<array-key, string> $configured */
        $configured = (array) config('tbtop-cms.seo.robots.disallow', []);

        $paths = [...$this->owned(), ...array_values($configured)];

        foreach (self::$extensions as $extension) {
            $paths = [...$paths, ...$extension()];
        }

        $normalized = [];

        foreach ($paths as $path) {
            $normalized[] = self::normalize($path);
        }

        return array_values(array_unique(array_filter($normalized, fn (string $p): bool => $p !== '')));
    }

    /**
     * The prefix is read per call: a host renaming its panel would otherwise
     * get robots.txt closing a path that no longer exists.
     *
     * `/login` is the redirect CmsServiceProvider registers when the host has
     * no login route of its own.
     *
     * @return list<string>
     */
    private function owned(): array
    {
        return [
            (string) config('tbtop-cms.admin.prefix', 'admin'),
            'login',
        ];
    }

    /** A crawler path is rooted and carries no trailing slash. */
    private static function normalize(string $path): string
    {
        $trimmed = trim(trim($path), '/');

        return $trimmed === '' ? '' : '/' . $trimmed;
    }
}
