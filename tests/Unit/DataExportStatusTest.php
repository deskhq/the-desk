<?php

use App\Enums\DataExportStatus;

test('every status has a non-empty label', function (DataExportStatus $status) {
    expect($status->label())->toBeString()->not->toBeEmpty();
})->with(DataExportStatus::cases());

test('labels describe the status', function () {
    expect(DataExportStatus::Pending->label())->toBe('Preparing');
    expect(DataExportStatus::Ready->label())->toBe('Ready to download');
    expect(DataExportStatus::Failed->label())->toBe('Failed');
});
