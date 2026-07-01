<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

/**
 * Opt-in contract for form blocks. A block implementing this exposes
 * server-side validation rules and owns what happens on an anonymous submit
 * (send a mail, store a lead, forward to a CRM, dispatch a job, ...).
 */
interface HandlesFormSubmission
{
    /**
     * Laravel validation rules for the anonymous payload, keyed by field name.
     *
     * @param  array<string, mixed>  $blockData
     * @return array<string, mixed>
     */
    public function submitRules(array $blockData, SubmitContext $ctx): array;

    /**
     * Handle a validated submission. Runs only after {@see submitRules()} pass.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $blockData
     */
    public function handleSubmit(array $payload, array $blockData, SubmitContext $ctx): void;
}
