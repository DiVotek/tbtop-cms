<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\IndexNow;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Frontend\Seo\SeoAlternates;
use Tbtop\Cms\Page\Models\Page;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Collects the URLs a request changed and submits them to IndexNow once,
 * after the response is sent (defer()), so an editor's save never waits on
 * a search engine or fails because of one. No queue: the package does not
 * require one (see Notifications).
 *
 * Pages are resolved to URLs at submit time, not when queued: PageSaved for
 * a new page fires before its slugs are written. A deleted page has lost its
 * slugs by then, so its URLs are taken when it is queued.
 *
 * Scoped: one batch per request.
 */
final class IndexNowSubmitter
{
    private const int TIMEOUT_SECONDS = 5;

    /** The protocol's ceiling per request. */
    private const int MAX_URLS = 10_000;

    /** @var array<int, Page> */
    private array $pages = [];

    /** @var array<string, true> */
    private array $urls = [];

    private bool $scheduled = false;

    public function __construct(
        private readonly IndexNowSettings $settings,
        private readonly SeoAlternates $alternates,
    ) {}

    public function queuePage(Page $page): void
    {
        $this->pages[(int) $page->getKey()] = $page;
        $this->schedule();
    }

    /** For a page whose slugs are about to go: its URLs now, while they still resolve. */
    public function queuePageNow(Page $page): void
    {
        foreach ($this->urlsOf($page) as $url) {
            $this->urls[$url] = true;
        }

        $this->schedule();
    }

    public function submit(): void
    {
        $this->scheduled = false;

        // It runs after the response, or at the end of a console command
        // (a seeder): a failure here is logged, never surfaced to the writer.
        try {
            $this->send();
        } catch (Throwable $e) {
            Log::warning('tbtop-cms: IndexNow submission failed', ['error' => $e->getMessage()]);
        }
    }

    private function send(): void
    {
        foreach ($this->pages as $page) {
            $page->unsetRelation('slugs');

            foreach ($this->urlsOf($page) as $url) {
                $this->urls[$url] = true;
            }
        }

        $urls = array_keys($this->urls);
        $this->pages = [];
        $this->urls = [];

        // Re-checked: a settings save later in the same request may have turned it off.
        if ($urls === [] || ! $this->settings->active()) {
            return;
        }

        $key = $this->settings->key();

        foreach ($this->byOrigin($urls) as $origin => $list) {
            foreach (array_chunk($list, self::MAX_URLS) as $chunk) {
                $this->post($origin, $key, $chunk);
            }
        }
    }

    private function schedule(): void
    {
        if (! $this->scheduled) {
            $this->scheduled = true;
            defer(fn () => $this->submit());
        }
    }

    /** @return list<string> */
    private function urlsOf(Page $page): array
    {
        return array_column($this->alternates->forPage($page), 'url');
    }

    /**
     * IndexNow takes URLs of one host per request, with the key file on that
     * host.
     *
     * @param  list<string>  $urls
     * @return array<string, list<string>>
     */
    private function byOrigin(array $urls): array
    {
        $groups = [];

        foreach ($urls as $url) {
            $parts = parse_url($url);

            if (! isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $groups[$origin][] = $url;
        }

        return $groups;
    }

    /** @param  list<string>  $urls */
    private function post(string $origin, string $key, array $urls): void
    {
        $endpoint = (string) config('tbtop-cms.seo.indexnow.endpoint', 'https://api.indexnow.org/indexnow');

        $response = Http::timeout(self::TIMEOUT_SECONDS)->asJson()->post($endpoint, [
            'host' => (string) parse_url($origin, PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => $origin . '/' . $key . '.txt',
            'urlList' => $urls,
        ]);

        if ($response->failed()) {
            Log::warning('tbtop-cms: IndexNow rejected the submission', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500), 'urls' => count($urls)]);
        }
    }
}
