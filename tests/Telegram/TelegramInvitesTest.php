<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tbtop\Cms\Telegram\TelegramInvites;

it('keeps getUpdates offsets separate per bot token', function (): void {
    Http::fake([
        'api.telegram.org/*getUpdates*' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 41, 'message' => ['text' => 'unrelated', 'chat' => ['id' => 1]]],
        ]]),
    ]);
    $invites = new TelegramInvites;

    // check() needs only the invite-code cache entries, so create() (and its
    // getMe call) is bypassed by seeding them directly.
    Cache::put('tbtop-cms:telegram:invite:CODEAAAA', true, 600);
    Cache::put('tbtop-cms:telegram:invite:CODEBBBB', true, 600);

    $invites->check('token-a', 'CODEAAAA');

    // Offsets are per-bot and opaque: after rotating the token, the old
    // bot's offset (42) must NOT be replayed against the new bot — that
    // could permanently skip its pending updates.
    Cache::forget('tbtop-cms:telegram:updates-cooldown');
    $invites->check('token-b', 'CODEBBBB');

    // The first bot's own next check DOES resume from its stored offset.
    Cache::forget('tbtop-cms:telegram:updates-cooldown');
    Cache::put('tbtop-cms:telegram:invite:CODEAAAA', true, 600);
    $invites->check('token-a', 'CODEAAAA');

    $requests = Http::recorded(fn ($request): bool => str_contains($request->url(), 'getUpdates'));
    expect($requests)->toHaveCount(3)
        ->and($requests[0][0]->url())->toContain('bottoken-a')
        ->and($requests[0][0]['offset'] ?? null)->toBeNull()
        ->and($requests[1][0]->url())->toContain('bottoken-b')
        ->and($requests[1][0]['offset'] ?? null)->toBeNull()
        ->and($requests[2][0]->url())->toContain('bottoken-a')
        ->and($requests[2][0]['offset'] ?? null)->toBe(42);
});
