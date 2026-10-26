<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Notifications;

use Tbtop\Cms\Block\HasFieldLabels;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Support\FormBlockLocator;
use Tbtop\Cms\Page\Models\Page;

/**
 * The display label of every submitted field, per admin UI locale: the
 * block's own {@see HasFieldLabels} entry, else the raw payload key. The
 * block is looked up once; its stored data is flattened to each locale, so
 * a label held in translatable block data reads in the reader's language.
 */
final readonly class SubmissionFieldLabels
{
    public function __construct(private FormBlockLocator $locator) {}

    /**
     * @param  list<string>  $locales
     * @return array<string, array<string, string>> labels keyed by locale, then payload key
     */
    public function forLocales(FormSubmission $submission, Page $page, array $locales): array
    {
        $stored = $this->locator->stored($page, $submission->block_id);
        $block = $stored['block'] ?? null;

        $byLocale = [];
        foreach ($locales as $locale) {
            $labels = $block instanceof HasFieldLabels
                ? $block->fieldLabels($locale, $this->locator->flatten($stored['data'] ?? [], $block, $locale))
                : [];
            $byLocale[$locale] = $this->withFallback($submission->payload, $labels);
        }

        return $byLocale;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private function withFallback(array $payload, array $labels): array
    {
        $resolved = [];
        foreach (array_keys($payload) as $key) {
            $label = $labels[$key] ?? '';
            $resolved[$key] = trim($label) !== '' ? $label : (string) $key;
        }

        return $resolved;
    }
}
