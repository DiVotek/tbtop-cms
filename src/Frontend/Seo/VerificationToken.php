<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Settings\Settings;

/**
 * A search-engine site-ownership verification token, extracted from whatever
 * the admin pasted — the bare token, or the full `<meta …>` tag the search
 * console shows to copy-paste. Mirrors GtmContainerId's shape: one class
 * per verification method (Google, Bing) because each owns a different meta
 * tag name, but both parse the same way.
 */
class VerificationToken
{
    private function __construct(private readonly string $metaName) {}

    public static function google(): self
    {
        return new self('google-site-verification');
    }

    public static function bing(): self
    {
        return new self('msvalidate.01');
    }

    /**
     * The one read path for rendering: extracts at read time too — not only
     * on save in SeoSettingsPage — so a value written programmatically or as
     * the full pasted tag still resolves; anything that doesn't normalize to
     * a valid token is dropped rather than interpolated into the markup.
     */
    public function fromSettings(Settings $settings, string $key): ?string
    {
        $raw = $settings->get($key, '');

        if (! is_string($raw)) {
            return null;
        }

        $token = $this->extract($raw);

        return $this->valid($token) ? $token : null;
    }

    /**
     * Accepts either a bare token or a pasted `<meta name="{$this->metaName}"
     * content="…">` tag, recognized by its own meta name — the content
     * attribute value is mined out regardless of attribute order or quote
     * style. Anything else (including a meta tag for the OTHER verification
     * method) comes back trimmed as-is: the admin sees their input back
     * instead of it silently vanishing, and valid() rejects it so no tag is
     * rendered.
     */
    public function extract(string $raw): string
    {
        $trimmed = trim($raw);

        if (preg_match('/<meta\b[^>]*>/i', $trimmed, $tagMatch) === 1) {
            $tag = $tagMatch[0];

            if (preg_match('/\bname\s*=\s*(["\'])' . preg_quote($this->metaName, '/') . '\1/i', $tag) !== 1) {
                return $trimmed;
            }

            if (preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/i', $tag, $contentMatch) === 1) {
                return trim($contentMatch[2]);
            }

            return $trimmed;
        }

        return $trimmed;
    }

    /**
     * Only a well-formed token may reach the rendered tag: interpolated
     * verbatim into a meta `content` attribute, so anything else is dropped
     * at render time regardless of what the setting stores. Search consoles
     * issue tokens as letters/digits/-/_ or base64 (which adds +/=), so the
     * pattern accepts that charset; length is capped well above any token a
     * search console actually issues, as a sanity bound against garbage.
     */
    public function valid(string $token): bool
    {
        return $token !== '' && preg_match('/^[A-Za-z0-9_\-+\/=]{1,512}$/', $token) === 1;
    }
}
