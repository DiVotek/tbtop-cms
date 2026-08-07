<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Mail\CmsMailer;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
});

it('builds an smtp config with the tls scheme and default port when encryption is tls', function (): void {
    $settings = app(Settings::class);
    $settings->set('mail.host', 'smtp.example.test');
    $settings->set('mail.encryption', 'tls');

    $config = app(CmsMailer::class)->transportConfig();

    expect($config['transport'])->toBe('smtp')
        ->and($config['host'])->toBe('smtp.example.test')
        ->and($config['port'])->toBe(587)
        ->and($config['scheme'])->toBe('smtp')
        ->and($config)->not->toHaveKey('auto_tls');
});

it('builds an smtp config with the smtps scheme when encryption is ssl', function (): void {
    $settings = app(Settings::class);
    $settings->set('mail.host', 'smtp.example.test');
    $settings->set('mail.port', 465);
    $settings->set('mail.encryption', 'ssl');

    $config = app(CmsMailer::class)->transportConfig();

    expect($config['scheme'])->toBe('smtps')
        ->and($config['port'])->toBe(465);
});

it('disables auto_tls when encryption is none', function (): void {
    $settings = app(Settings::class);
    $settings->set('mail.host', 'smtp.example.test');
    $settings->set('mail.encryption', 'none');

    $config = app(CmsMailer::class)->transportConfig();

    expect($config['scheme'])->toBe('smtp')
        ->and($config['auto_tls'])->toBeFalse();
});

it('defaults to opportunistic tls when the encryption setting is unset', function (): void {
    $settings = app(Settings::class);
    $settings->set('mail.host', 'smtp.example.test');

    $config = app(CmsMailer::class)->transportConfig();

    expect($config['scheme'])->toBe('smtp')
        ->and($config)->not->toHaveKey('auto_tls');
});

it('omits username and password when unset', function (): void {
    $settings = app(Settings::class);
    $settings->set('mail.host', 'smtp.example.test');

    $config = app(CmsMailer::class)->transportConfig();

    expect($config)->not->toHaveKey('username')
        ->and($config)->not->toHaveKey('password');
});

it('includes username and password when configured', function (): void {
    $settings = app(Settings::class);
    $settings->set('mail.host', 'smtp.example.test');
    $settings->set('mail.username', 'user@example.test');
    $settings->set('mail.password', 'secret');

    $config = app(CmsMailer::class)->transportConfig();

    expect($config['username'])->toBe('user@example.test')
        ->and($config['password'])->toBe('secret');
});

it('sets the mailable from address and name when configured', function (): void {
    Mail::fake();
    $settings = app(Settings::class);
    $settings->set('mail.from_address', 'noreply@example.test');
    $settings->set('mail.from_name', 'Example Site');

    $mailable = new class extends Mailable {};
    app(CmsMailer::class)->send($mailable, 'to@example.test');

    Mail::assertSent(
        $mailable::class,
        fn (Mailable $mail): bool => $mail->hasFrom('noreply@example.test', 'Example Site'),
    );
});

it('leaves the mailable from address untouched when not configured', function (): void {
    Mail::fake();

    $mailable = new class extends Mailable {};
    app(CmsMailer::class)->send($mailable, 'to@example.test');

    Mail::assertSent($mailable::class, fn (Mailable $mail): bool => $mail->from === null || $mail->from === []);
});
