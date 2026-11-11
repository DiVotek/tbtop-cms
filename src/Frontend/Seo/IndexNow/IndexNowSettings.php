<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\IndexNow;

use Tbtop\Cms\Frontend\Seo\IndexingSetting;
use Tbtop\Cms\Settings\Settings;

/**
 * The single reader of the `seo.indexnow.*` keys: the editor's switch on the
 * SEO settings page's «Indexing» tab and the key the IndexNow protocol uses to
 * prove the site owns the URLs it submits.
 *
 * Off by default: an upgrade must not start telling search engines about the
 * owner's pages for them. The key is the package's, never the editor's: it is
 * generated the first time a ping or the settings page needs it and kept, so
 * the file search engines fetch to check it stays the same.
 */
final readonly class IndexNowSettings
{
    public const string ENABLED = 'seo.indexnow.enabled';

    public const string KEY = 'seo.indexnow.key';

    /** The shape this package generates; also the key file route's constraint. */
    public const string KEY_PATTERN = '[0-9a-f]{32}';

    public function __construct(
        private Settings $settings,
        private IndexingSetting $indexing,
    ) {}

    /** On, and the site is open to search engines: a closed site has nothing to announce. */
    public function active(): bool
    {
        return (bool) $this->settings->get(self::ENABLED) && $this->indexing->enabled();
    }

    /** The stored key, without creating one; null before the first need or when the stored value is not one of ours. */
    public function storedKey(): ?string
    {
        $key = $this->settings->get(self::KEY);

        return is_string($key) && preg_match('/\A' . self::KEY_PATTERN . '\z/', $key) === 1 ? $key : null;
    }

    /** The stored key, generated and stored on first use. */
    public function key(): string
    {
        $key = $this->storedKey();

        if ($key === null) {
            $key = bin2hex(random_bytes(16));
            $this->settings->set(self::KEY, $key);
        }

        return $key;
    }
}
