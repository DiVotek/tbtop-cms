<?php

declare(strict_types=1);

namespace Tbtop\Cms\Telegram;

use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Support\LeadIdentity;

/**
 * Sends one Telegram message per configured chat id for a new submission.
 * Mirrors SubmissionMail's content (lead identity, page, link) in Telegram's
 * HTML subset instead of full HTML — bold title, plain lines, no tables.
 */
final class TelegramSubmissionNotifier
{
    public function __construct(private readonly TelegramClient $client = new TelegramClient) {}

    /**
     * @param  list<string>  $chatIds
     * @param  callable(string): void|null  $delivered
     * @return list<string> per-chat error messages; empty means every send succeeded
     */
    public function notify(string $token, array $chatIds, FormSubmission $submission, string $pageName, ?callable $delivered = null): array
    {
        $message = $this->message($submission, $pageName);
        $errors = [];

        foreach ($chatIds as $chatId) {
            try {
                $this->client->sendMessage($token, $chatId, $message);
                if ($delivered !== null) {
                    $delivered($chatId);
                }
            } catch (TelegramApiException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    private function message(FormSubmission $submission, string $pageName): string
    {
        $title = e(__('tbtop-cms::notifications.telegram.message_title'));
        $identity = e(LeadIdentity::label($submission->payload) ?? __('tbtop-cms::form-submissions.values.lead_unknown'));
        $pageLabel = e(__('tbtop-cms::notifications.mail.fields.page'));
        $url = e($this->detailUrl($submission));

        return "<b>{$title}</b>\n{$identity}\n{$pageLabel}: " . e($pageName) . "\n{$url}";
    }

    private function detailUrl(FormSubmission $submission): string
    {
        return route('tbtop.cms.form-submission-page', ['submission' => $submission->id]);
    }
}
