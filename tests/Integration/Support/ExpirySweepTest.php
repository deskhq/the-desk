<?php

declare(strict_types=1);

use App\Events\UserProfileUpdated;
use App\Models\DataExport;
use App\Models\User;
use App\Support\ExpirySweep;
use App\Support\ExportLifecycle;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * A user whose custom status lapsed an hour ago.
 */
function userWithLapsedStatus(): User
{
    return User::factory()->create([
        'status_emoji' => ':spiral_calendar_pad:',
        'status_text' => 'In a meeting',
        'status_expires_at' => now()->subHour(),
    ]);
}

test('purging expired exports removes the file before the row and leaves live ones alone', function (): void {
    Storage::fake(ExportLifecycle::DISK);

    $expired = DataExport::factory()->expired()->create();
    $live = DataExport::factory()->ready()->create();

    Storage::disk(ExportLifecycle::DISK)->put($expired->path, 'expired archive');
    Storage::disk(ExportLifecycle::DISK)->put($live->path, 'live archive');

    $purged = ExpirySweep::purgeExpiredExports(DataExport::query(), ExportLifecycle::DISK);

    expect($purged)->toBe(1);

    Storage::disk(ExportLifecycle::DISK)->assertMissing($expired->path);
    Storage::disk(ExportLifecycle::DISK)->assertExists($live->path);

    $this->assertDatabaseMissing('data_exports', ['id' => $expired->id]);
    $this->assertDatabaseHas('data_exports', ['id' => $live->id]);
});

test('an export whose window closed before it produced a file is still purged', function (): void {
    Storage::fake(ExportLifecycle::DISK);

    $pathless = DataExport::factory()->create(['expires_at' => now()->subDay()]);

    expect(ExpirySweep::purgeExpiredExports(DataExport::query(), ExportLifecycle::DISK))->toBe(1);

    $this->assertDatabaseMissing('data_exports', ['id' => $pathless->id]);
});

test('a lapsed profile instant is nulled along with the columns riding on it, and broadcast', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $lapsed = userWithLapsedStatus();

    $cleared = ExpirySweep::clearLapsedProfileInstant(
        User::query()->whereNotNull('status_emoji'),
        'status_expires_at',
        ['status_emoji', 'status_text'],
    );

    expect($cleared)->toBe(1);

    $this->assertDatabaseHas('users', [
        'id' => $lapsed->id,
        'status_expires_at' => null,
        'status_emoji' => null,
        'status_text' => null,
    ]);

    Event::assertDispatched(
        UserProfileUpdated::class,
        fn (UserProfileUpdated $event): bool => $event->user->is($lapsed),
    );
});

test('an instant set afresh while the pass was running is left alone, uncounted and unbroadcast', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $racer = userWithLapsedStatus();
    // Whole seconds, because the column stores no finer and the assertion below
    // compares the instant the sweep spared against the one that was written.
    $renewed = now()->addHour()->startOfSecond();

    // The window the compare-and-swap exists for: the cursor hands back the row
    // it read, and by the time this pass acts on it the user has set a fresh
    // status. Writing on `retrieved` is how that window is opened deterministically
    // — it is the moment between the cursor's read and the sweep's write. Once,
    // because ExpirySweep re-reads the user it does clear.
    $raced = false;

    User::retrieved(function (User $user) use (&$raced, $renewed): void {
        if ($raced) {
            return;
        }

        $raced = true;

        User::query()->whereKey($user->getKey())->update([
            'status_emoji' => ':wave:',
            'status_text' => 'Back',
            'status_expires_at' => $renewed,
        ]);
    });

    $cleared = ExpirySweep::clearLapsedProfileInstant(
        User::query()->whereNotNull('status_emoji'),
        'status_expires_at',
        ['status_emoji', 'status_text'],
    );

    expect($cleared)->toBe(0)
        ->and($racer->fresh()->status_emoji)->toBe(':wave:')
        ->and($racer->fresh()->status_expires_at?->equalTo($renewed))->toBeTrue();

    Event::assertNotDispatched(UserProfileUpdated::class);
});
