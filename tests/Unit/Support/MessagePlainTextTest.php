<?php

declare(strict_types=1);

use App\Support\MessagePlainText;

test('unwraps a mention token to its display name', function (): void {
    expect(MessagePlainText::from('hey @[Ada Lovelace](3f5b1c2d-1111-2222-3333-444455556666) look'))
        ->toBe('hey @Ada Lovelace look');
});

test('unwraps several mentions in one body', function (): void {
    $body = '@[Ada](3f5b1c2d-1111-2222-3333-444455556666) and @[Bob](aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee)';

    expect(MessagePlainText::from($body))->toBe('@Ada and @Bob');
});

test('leaves a body without mentions untouched', function (): void {
    expect(MessagePlainText::from('the quokka danced at dawn'))
        ->toBe('the quokka danced at dawn');
});
