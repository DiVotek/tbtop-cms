<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block\Blocks;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\HasFieldLabels;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\SubmitContext;

/**
 * Reference form block: an anonymous contact form. Notifying the team about
 * a submission (mail/bell/Telegram, per NotificationSettingsPage) is a
 * cms-owned concern handled by SubmissionNotifier off the SubmissionReceived
 * event, not by this block — handleSubmit() here is a no-op. The
 * visitor-facing fields are authored in code (SPEC_V2 §3); the admin only
 * edits copy. Real projects swap {@see handleSubmit()} to forward to a CRM,
 * queue a job, store a lead, etc.; it still runs (and is still retried by
 * SubmissionRetrier) for that purpose, independent of the notification
 * fan-out.
 */
class ContactForm extends Block implements HandlesFormSubmission, HasFieldLabels
{
    /** @var list<array{name: string, type: string, required: bool}> Labels come from tbtop-cms::forms.contact.fields.<name>. */
    private const array FIELDS = [
        ['name' => 'name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'type' => 'email', 'required' => true],
        ['name' => 'message', 'type' => 'textarea', 'required' => true],
    ];

    public function kind(): string
    {
        return 'ContactForm';
    }

    #[\Override]
    public function label(): string
    {
        return 'Contact form';
    }

    #[\Override]
    public function description(): ?string
    {
        return 'Anonymous contact form. You edit the copy; the name, email, and message fields are built in. Submissions mail the global notification recipients.';
    }

    #[\Override]
    public function category(): ?string
    {
        return 'forms';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [
            $s->text('heading')->label('Heading')->translatable(),
            $s->text('submitLabel')->label('Submit button label')->translatable(),
            $s->textarea('successMessage')->label('Success message')->translatable(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{heading: string, submitLabel: string, successMessage: string, fields: list<array{name: string, label: string, type: string, required: bool}>}
     */
    #[\Override]
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return [
            'heading' => self::copy($input['heading'] ?? null, 'heading', $ctx),
            'submitLabel' => self::copy($input['submitLabel'] ?? null, 'submit', $ctx),
            'successMessage' => self::copy($input['successMessage'] ?? null, 'success', $ctx),
            'fields' => array_map(static fn (array $field): array => [
                'name' => $field['name'],
                'label' => self::lang('fields.' . $field['name'], $ctx->locale),
                'type' => $field['type'],
                'required' => $field['required'],
            ], self::FIELDS),
        ];
    }

    /** The editor's copy, or the default in the page locale when it is blank. */
    private static function copy(mixed $value, string $key, ResolveContext $ctx): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : self::lang($key, $ctx->locale);
    }

    private static function lang(string $key, string $locale): string
    {
        $text = __('tbtop-cms::forms.contact.' . $key, [], $locale);

        return is_string($text) ? $text : '';
    }

    /**
     * The built-in labels; the editor cannot change them, so `$input` is unused.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function fieldLabels(string $locale, array $input): array
    {
        $labels = [];
        foreach (self::FIELDS as $field) {
            $labels[$field['name']] = self::lang('fields.' . $field['name'], $locale);
        }

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $blockData
     * @return array<string, mixed>
     */
    public function submitRules(array $blockData, SubmitContext $ctx): array
    {
        return [
            'name' => 'required|string|max:200',
            'email' => 'required|email|max:200',
            'message' => 'required|string|max:5000',
        ];
    }

    /**
     * No-op: see the class docblock. Kept because HandlesFormSubmission
     * requires it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $blockData
     */
    public function handleSubmit(array $payload, array $blockData, SubmitContext $ctx): void {}
}
