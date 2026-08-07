<?php

declare(strict_types=1);

namespace Tbtop\Cms\Mail;

use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Settings\Settings;

/**
 * Resolves the mailer used for all cms-originated mail: an on-demand SMTP
 * transport built from admin-configured settings when `mail.host` is set,
 * falling back to the host application's default mailer otherwise.
 */
class CmsMailer
{
    public function __construct(private readonly Settings $settings) {}

    /** @param  list<string>|string  $to */
    public function send(Mailable $mailable, array | string $to): void
    {
        $fromAddress = $this->settings->get('mail.from_address');
        $fromName = $this->settings->get('mail.from_name');

        if (is_string($fromAddress) && trim($fromAddress) !== '') {
            $mailable->from($fromAddress, is_string($fromName) && trim($fromName) !== '' ? $fromName : null);
        }

        $this->mailer()->to($to)->send($mailable);
    }

    private function mailer(): MailerContract
    {
        $host = $this->settings->get('mail.host');

        if (! is_string($host) || trim($host) === '') {
            return Mail::mailer();
        }

        return Mail::build($this->transportConfig());
    }

    /** @return array<string, mixed> */
    public function transportConfig(): array
    {
        $host = (string) $this->settings->get('mail.host');
        $port = $this->settings->get('mail.port');
        $encryption = (string) ($this->settings->get('mail.encryption') ?? 'tls');

        return array_filter([
            'transport' => 'smtp',
            'host' => $host,
            'port' => is_numeric($port) ? (int) $port : 587,
            'username' => $this->nullableString($this->settings->get('mail.username')),
            'password' => $this->nullableString($this->settings->get('mail.password')),
            ...$this->encryptionOptions($encryption),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Symfony's EsmtpTransportFactory reads 'scheme' (smtp/smtps) and
     * 'auto_tls', not the legacy SwiftMailer 'encryption' key:
     * - tls  → scheme "smtp", auto_tls left on (opportunistic STARTTLS).
     * - ssl  → scheme "smtps" (implicit TLS, typically port 465).
     * - none → scheme "smtp", auto_tls disabled (no TLS negotiated at all).
     *
     * @return array<string, mixed>
     */
    private function encryptionOptions(string $encryption): array
    {
        return match ($encryption) {
            'ssl' => ['scheme' => 'smtps'],
            'none' => ['scheme' => 'smtp', 'auto_tls' => false],
            default => ['scheme' => 'smtp'],
        };
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
