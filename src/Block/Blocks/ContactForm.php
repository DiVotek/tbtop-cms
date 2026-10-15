<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block\Blocks;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\SubmitContext;
use Tbtop\Cms\Form\HasForm;

/**
 * Reference form block: an anonymous contact form. Notifying the team about
 * a submission (mail/bell/Telegram, per NotificationSettingsPage) is a
 * cms-owned concern handled by SubmissionNotifier off the SubmissionReceived
 * event, not by this block — handleSubmit() here is a no-op. The
 * visitor-facing fields are authored in code (SPEC_V2 §3); the admin only
 * edits copy. Real projects swap {@see handleSubmit()} to forward to a CRM,
 * queue a job, store a lead, etc.; it still runs (and is still retried by
 * SubmissionRetrier) for that purpose, independent of the notification
 * fan-out. HasForm makes it reachable by name from any page as well:
 * `Cms::forms()->props('ContactForm', $page)` for a template-rendered form.
 */
class ContactForm extends Block implements HandlesFormSubmission
{
    use HasForm;

    /** @var list<array{name: string, label: string, type: string, required: bool}> */
    private const array FIELDS = [
        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
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
            'heading' => (string) ($input['heading'] ?? 'Contact us'),
            'submitLabel' => (string) ($input['submitLabel'] ?? 'Send'),
            'successMessage' => (string) ($input['successMessage'] ?? 'Thanks, we will be in touch.'),
            'fields' => self::FIELDS,
        ];
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
