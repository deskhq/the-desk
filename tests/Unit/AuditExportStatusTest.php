<?php

declare(strict_types=1);

use App\Enums\AuditExportStatus;
use Tests\TestCase;

// Labels resolve through __(), so these tests boot the application container.
uses(TestCase::class);

test('every status has a non-empty label', function (AuditExportStatus $status): void {
    expect($status->label())->toBeString()->not->toBe('');
})->with(AuditExportStatus::cases());
