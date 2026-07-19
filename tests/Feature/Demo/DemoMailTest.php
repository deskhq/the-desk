<?php

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;

test('demo mode forces the array mail transport regardless of the configured mailer', function (): void {
    $this->reloadWithEnv(['MAIL_MAILER' => 'smtp', 'DEMO_MODE' => true]);

    expect(config('mail.default'))->toBe('array')
        ->and(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(ArrayTransport::class);
});

test('the configured mail transport is left alone when demo mode is off', function (): void {
    $this->reloadWithEnv(['MAIL_MAILER' => 'smtp', 'DEMO_MODE' => false]);

    expect(config('mail.default'))->toBe('smtp');
});
