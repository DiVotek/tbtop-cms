<?php

declare(strict_types=1);

namespace Tbtop\Cms\Settings\Support;

use Illuminate\Support\Facades\Crypt;

class SettingCodec
{
    public static function encode(mixed $value, bool $secret): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);

        return $secret ? Crypt::encryptString($json) : $json;
    }

    public static function decode(?string $raw, bool $secret): mixed
    {
        if ($raw === null) {
            return null;
        }

        $json = $secret ? Crypt::decryptString($raw) : $raw;

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
