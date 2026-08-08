<?php

declare(strict_types=1);

namespace Tbtop\Cms\Settings;

use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Block\MediaUrlResolver;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Settings\Models\Setting;
use Tbtop\Cms\Settings\Support\SettingCodec;
use Throwable;

class Settings
{
    private const string CACHE_KEY = 'tbtop-cms.settings';

    // Settings keys whose stored value is a media id (from an $s->media()
    // field) rather than the value itself — forFrontend() resolves these to
    // public URLs so consumers never see a raw id.
    private const array MEDIA_KEYS = ['site.logo', 'site.favicon', 'seo.default_og_image'];

    /** @var array<string, string|null>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly MediaUrlResolver $mediaUrlResolver = new MediaUrlResolver,
        private readonly CmsCache $cache = new CmsCache,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->rawAll();

        if (! array_key_exists($key, $raw)) {
            return $default;
        }

        try {
            return SettingCodec::decode($raw[$key], $this->isSecret($key));
        } catch (Throwable $e) {
            Log::warning('settings.decode_failed', ['key' => $key, 'error' => $e->getMessage()]);

            return $default;
        }
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => SettingCodec::encode($value, $this->isSecret($key))],
        );

        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        $this->flush();
    }

    /** @return array<string, mixed> */
    public function forFrontend(): array
    {
        $result = [];

        foreach ($this->rawAll() as $key => $raw) {
            if ($this->isSecret($key)) {
                continue;
            }

            try {
                $value = SettingCodec::decode($raw, false);
                $result[$key] = in_array($key, self::MEDIA_KEYS, true)
                    ? $this->resolveMediaValue($value)
                    : $value;
            } catch (Throwable $e) {
                Log::warning('settings.decode_failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    private function resolveMediaValue(mixed $id): ?string
    {
        return is_string($id) || is_int($id) ? $this->mediaUrlResolver->resolve($id) : null;
    }

    public function isSecret(string $key): bool
    {
        /** @var list<string> $secrets */
        $secrets = config('tbtop-cms.settings.secret', []);

        return in_array($key, $secrets, true);
    }

    /**
     * Cached under CmsCache's versioned key rather than kept only in $memo:
     * this map is read on every admin and frontend request alike, and
     * SettingsSaved already bumps the version on every write path
     * (SettingsForm::apply), so a shared cross-request cache is safe for
     * both kinds of readers. Only the raw (still-encrypted-if-secret) column
     * values are cached — decryption happens lazily per key in get(), so a
     * cache dump never exposes a decrypted secret.
     *
     * @return array<string, string|null>
     */
    private function rawAll(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $raw = $this->cache->remember(
            self::CACHE_KEY,
            static fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );

        return $this->memo = $raw;
    }

    /**
     * set()/forget() can be called directly (tests, block code) without
     * going through SettingsForm::apply()/SettingsSaved, so this bumps the
     * shared version itself rather than relying on that event to arrive.
     */
    private function flush(): void
    {
        $this->cache->bump();
        $this->memo = null;
    }
}
