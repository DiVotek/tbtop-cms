<?php

declare(strict_types=1);

namespace Tbtop\Cms\Telegram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Create invite" / "Check invite" pair behind the telegram settings
 * section: an admin has no way to hand cms a chat id directly (Telegram
 * assigns it, and it isn't visible anywhere in the client UI), so this
 * bridges the gap the same way most bot integrations do — a short-lived
 * code the admin sends the bot, which getUpdates then correlates back to a
 * chat id.
 *
 * State lives in the default cache store under plain (non-CmsCache) keys:
 * this is ephemeral coordination state, not content that a version bump
 * should ever invalidate.
 */
final class TelegramInvites
{
    private const int CODE_TTL_SECONDS = 600;

    private const int BOT_USERNAME_TTL_SECONDS = 3600;

    // The getUpdates offset only needs to survive between two admin clicks
    // of "Check invite", not indefinitely.
    private const int OFFSET_TTL_SECONDS = 3600;

    // Cache::put()'s TTL is whole seconds, so the ~1.5s cooldown the brief
    // calls for rounds up to 2 — still well under the rate a human clicking
    // "Check invite" could trigger, which is all this guards against.
    private const int UPDATES_COOLDOWN_SECONDS = 2;

    // Digits and uppercase letters minus 0/O/1/I/L — easy to type back into
    // Telegram without misreading a character.
    private const string CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const int CODE_LENGTH = 8;

    public function __construct(private readonly TelegramClient $client = new TelegramClient) {}

    /**
     * @return array{code: string, deepLink: string}
     *
     * @throws TelegramApiException
     */
    public function create(string $token): array
    {
        $code = $this->generateCode();
        Cache::put($this->codeKey($code), true, self::CODE_TTL_SECONDS);
        // "Check invite" is a separate, argument-less admin click — there's
        // nowhere on this settings page to carry the code from one action's
        // result into the next one's request, so the most recently created
        // code is remembered server-side instead.
        Cache::put($this->currentCodeKey(), $code, self::CODE_TTL_SECONDS);

        $username = $this->botUsername($token);

        return ['code' => $code, 'deepLink' => "https://t.me/{$username}?start={$code}"];
    }

    /**
     * Checks the most recently created() invite code, if any is still
     * outstanding.
     *
     * @return array{status: 'found', chatId: string, chatTitle: string}|array{status: 'expired'|'pending'|'error', error?: string}
     */
    public function checkCurrent(string $token): array
    {
        $code = Cache::get($this->currentCodeKey());

        if (! is_string($code) || $code === '') {
            return ['status' => 'expired'];
        }

        return $this->check($token, $code);
    }

    /**
     * Polls getUpdates once (rate-limited by a short cooldown shared across
     * all invites, since Telegram's own long-polling offset is global per
     * bot token) looking for a /start {code} or bare {code} message.
     *
     * @return array{status: 'found', chatId: string, chatTitle: string}|array{status: 'expired'|'pending'|'error', error?: string}
     */
    public function check(string $token, string $code): array
    {
        if (! Cache::has($this->codeKey($code))) {
            return ['status' => 'expired'];
        }

        if (! $this->passesCooldown()) {
            return ['status' => 'pending'];
        }

        try {
            $offset = Cache::get($this->offsetKey());
            $result = $this->client->getUpdates($token, is_int($offset) ? $offset : null);
        } catch (TelegramApiException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        if ($result['nextOffset'] !== null) {
            Cache::put($this->offsetKey(), $result['nextOffset'], self::OFFSET_TTL_SECONDS);
        }

        $match = $this->findCodeMatch($result['updates'], $code);

        if ($match === null) {
            return ['status' => 'pending'];
        }

        Cache::forget($this->codeKey($code));
        Cache::forget($this->currentCodeKey());

        return ['status' => 'found', 'chatId' => $match['chatId'], 'chatTitle' => $match['chatTitle']];
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @return array{chatId: string, chatTitle: string}|null
     */
    private function findCodeMatch(array $updates, string $code): ?array
    {
        foreach ($updates as $update) {
            $message = $update['message'] ?? null;
            if (! is_array($message)) {
                continue;
            }

            $text = trim((string) ($message['text'] ?? ''));
            if ($text !== "/start {$code}" && $text !== $code && $text !== "/start{$code}") {
                continue;
            }

            $chat = $message['chat'] ?? null;
            if (! is_array($chat) || ! isset($chat['id'])) {
                continue;
            }

            $title = $chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? (string) $chat['id'];

            return ['chatId' => (string) $chat['id'], 'chatTitle' => (string) $title];
        }

        return null;
    }

    private function passesCooldown(): bool
    {
        if (Cache::has($this->cooldownKey())) {
            return false;
        }

        Cache::put($this->cooldownKey(), true, self::UPDATES_COOLDOWN_SECONDS);

        return true;
    }

    /** @throws TelegramApiException */
    private function botUsername(string $token): string
    {
        $key = $this->botUsernameKey($token);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $username = $this->client->botUsername($token);
        Cache::put($key, $username, self::BOT_USERNAME_TTL_SECONDS);

        return $username;
    }

    private function generateCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;

        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private function codeKey(string $code): string
    {
        return "tbtop-cms:telegram:invite:{$code}";
    }

    private function currentCodeKey(): string
    {
        return 'tbtop-cms:telegram:current-invite-code';
    }

    private function botUsernameKey(string $token): string
    {
        return 'tbtop-cms:telegram:bot-username:' . Str::substr(hash('sha256', $token), 0, 16);
    }

    private function offsetKey(): string
    {
        return 'tbtop-cms:telegram:updates-offset';
    }

    private function cooldownKey(): string
    {
        return 'tbtop-cms:telegram:updates-cooldown';
    }
}
