<?php

declare(strict_types=1);

namespace Tbtop\Cms\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the three Bot API methods this package needs. Every
 * call is short-timeout and exception-safe (network failures, non-2xx, and
 * a Bot API-level {"ok": false} all collapse to a TelegramApiException
 * rather than a raw Http\Client exception or a silently-wrong response) —
 * callers (SubmissionNotifier, the settings page invite actions) never see
 * a 500 out of a Telegram outage.
 */
final class TelegramClient
{
    private const int TIMEOUT_SECONDS = 5;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the API response's "result"
     *
     * @throws TelegramApiException
     */
    public function call(string $token, string $method, array $params = []): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        } catch (ConnectionException $e) {
            throw new TelegramApiException('Could not reach Telegram.', previous: $e);
        }

        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            $description = is_array($body) && is_string($body['description'] ?? null)
                ? $body['description']
                : 'HTTP ' . $response->status();

            throw new TelegramApiException("Telegram API error ({$method}): {$description}");
        }

        /** @var array<string, mixed> $result */
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];

        return $result;
    }

    /**
     * @throws TelegramApiException
     */
    public function sendMessage(string $token, string $chatId, string $html): void
    {
        try {
            $this->call($token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (TelegramApiException $e) {
            throw new TelegramApiException("chat {$chatId}: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @throws TelegramApiException
     */
    public function botUsername(string $token): string
    {
        $result = $this->call($token, 'getMe');
        $username = $result['username'] ?? null;

        if (! is_string($username) || $username === '') {
            throw new TelegramApiException('Telegram did not return a bot username.');
        }

        return $username;
    }

    /**
     * @return array{updates: list<array<string, mixed>>, nextOffset: int|null}
     *
     * @throws TelegramApiException
     */
    public function getUpdates(string $token, ?int $offset): array
    {
        $params = array_filter(['offset' => $offset, 'timeout' => 0], static fn (mixed $v): bool => $v !== null);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/getUpdates", $params);
        } catch (ConnectionException $e) {
            throw new TelegramApiException('Could not reach Telegram.', previous: $e);
        }

        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw new TelegramApiException('Telegram API error (getUpdates).');
        }

        /** @var list<array<string, mixed>> $updates */
        $updates = is_array($body['result'] ?? null) ? array_values($body['result']) : [];

        $lastId = null;
        foreach ($updates as $update) {
            if (is_int($update['update_id'] ?? null)) {
                $lastId = $update['update_id'];
            }
        }

        return ['updates' => $updates, 'nextOffset' => $lastId !== null ? $lastId + 1 : null];
    }
}
