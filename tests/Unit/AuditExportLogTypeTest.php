<?php

declare(strict_types=1);

use App\Enums\AuditExportLogType;
use Tests\TestCase;

// Labels resolve through __(), so these tests boot the application container.
uses(TestCase::class);

test('every log type has a non-empty label', function (AuditExportLogType $type): void {
    expect($type->label())->toBeString()->not->toBe('');
})->with(AuditExportLogType::cases());

test('the log-type options expose a value and label for every case', function (): void {
    $options = AuditExportLogType::options();

    expect($options)->toHaveCount(count(AuditExportLogType::cases()));
    expect($options[0])->toHaveKeys(['value', 'label']);
});
