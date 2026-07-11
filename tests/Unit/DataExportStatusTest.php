<?php

use App\Enums\DataExportStatus;
use Tests\TestCase;

// Labels are localized via __(), which resolves against the framework's
// translator, so these tests boot the application container.
uses(TestCase::class);

test('every status has a non-empty label', function (DataExportStatus $status) {
    expect($status->label())->toBeString()->not->toBeEmpty();
})->with(DataExportStatus::cases());

test('labels describe the status', function () {
    expect(DataExportStatus::Pending->label())->toBe('Preparing');
    expect(DataExportStatus::Ready->label())->toBe('Ready to download');
    expect(DataExportStatus::Failed->label())->toBe('Failed');
});
