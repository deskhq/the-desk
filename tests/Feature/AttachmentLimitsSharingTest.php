<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the attachment limits are shared to the frontend so the composer can pre-check size and count', function (): void {
    config([
        'attachments.max_size_mb' => 25,
        'attachments.max_per_message' => 10,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('attachments.maxSizeMb', 25)
            ->where('attachments.maxPerMessage', 10)
        );
});

test('the shared attachment limits track the operator-configured values', function (): void {
    config([
        'attachments.max_size_mb' => 5,
        'attachments.max_per_message' => 3,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('attachments.maxSizeMb', 5)
            ->where('attachments.maxPerMessage', 3)
        );
});
