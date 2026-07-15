<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block\Blocks;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\SubmitContext;
use Tbtop\Cms\Mail\ContactFormMail;

/**
 * Reference form block: an anonymous contact form that mails its submission to
 * a recipient the admin configures. The visitor-facing fields are authored in
 * code (SPEC_V2 §3); the admin only edits copy and the recipient. Real projects
 * swap {@see handleSubmit()} to forward to a CRM, queue a job, store a lead, etc.
 */
class ContactForm extends Block implements HandlesFormSubmission
{
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
        return 'Anonymous contact form. You edit the copy and recipient; the name, email, and message fields are built in.';
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
            $s->text('recipient')->label('Recipient email')->required()->rules('email'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $blockData
     */
    public function handleSubmit(array $payload, array $blockData, SubmitContext $ctx): void
    {
        $recipient = trim((string) ($blockData['recipient'] ?? ''));

        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('form.contact.invalid_recipient', ['page' => $ctx->page?->getKey()]);

            return;
        }

        Mail::to($recipient)->send(new ContactFormMail($payload));
    }
}
