<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Settings\Settings;

/**
 * The `Content-Signal` line robots.txt carries when an editor opts in
 * (contentsignals.org): whether the site's content may be used for search,
 * as AI input (answers, grounding) and for AI training.
 *
 * Off by default, because the line is a statement of the site owner's
 * terms and an upgrade must not make it for them. Once on, an unset signal
 * takes the documented default: search and AI input yes, training no.
 */
final readonly class ContentSignals
{
    public const string ENABLED = 'seo.content_signals.enabled';

    /** Signal name => [settings key, default]. In the order the line lists them. */
    public const array SIGNALS = [
        'search' => ['seo.content_signals.search', true],
        'ai-input' => ['seo.content_signals.ai_input', true],
        'ai-train' => ['seo.content_signals.ai_train', false],
    ];

    public function __construct(private Settings $settings) {}

    /** `Content-Signal: search=yes, ai-input=yes, ai-train=no`, or null when off. */
    public function line(): ?string
    {
        if (! (bool) $this->settings->get(self::ENABLED)) {
            return null;
        }

        $parts = [];

        foreach (self::SIGNALS as $name => [$key, $default]) {
            $value = $this->settings->get($key);
            $parts[] = $name . '=' . ((($value === null) ? $default : (bool) $value) ? 'yes' : 'no');
        }

        return 'Content-Signal: ' . implode(', ', $parts);
    }
}
