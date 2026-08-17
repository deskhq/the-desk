<?php

declare(strict_types=1);

use App\Enums\AuditExportFormat;
use Tests\TestCase;

// Labels resolve through __(), so these tests boot the application container.
uses(TestCase::class);

test('every format has a non-empty label', function (AuditExportFormat $format): void {
    expect($format->label())->toBeString()->not->toBe('');
})->with(AuditExportFormat::cases());

test('the extension matches the format value', function (): void {
    expect(AuditExportFormat::Csv->extension())->toBe('csv');
    expect(AuditExportFormat::Json->extension())->toBe('json');
});

test('the format options expose a value and label for every case', function (): void {
    $options = AuditExportFormat::options();

    expect($options)->toHaveCount(count(AuditExportFormat::cases()));
    expect($options[0])->toHaveKeys(['value', 'label']);
});
