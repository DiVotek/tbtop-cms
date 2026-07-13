<?php

declare(strict_types=1);

namespace Tbtop\Cms\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Block\MediaUrlResolver;
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

    /** @return array<string, string|null> */
    private function rawAll(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        /** @var array<string, string|null> $raw */
        $raw = Cache::rememberForever(
            self::CACHE_KEY,
            static fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );

        return $this->memo = $raw;
    }

    private function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->memo = null;
    }
}
