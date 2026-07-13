<?php

use App\Actions\Channels\PurgeExpiredAttachments;
use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Create a pending attachment with a real stored blob, created at the given age.
 */
function agedPending(int $hoursAgo): Attachment
{
    $path = UploadedFile::fake()->image('photo.png')->store('attachments/x', 'local');

    return Attachment::factory()->create([
        'disk' => 'local',
        'path' => $path,
        'created_at' => now()->subHours($hoursAgo),
    ]);
}

test('a pending attachment older than the ttl is purged, row and blob', function (): void {
    Storage::fake('local');
    config()->set('attachments.pending_ttl_hours', 24);
    $stale = agedPending(25);

    $purged = app(PurgeExpiredAttachments::class)->handle();

    expect($purged)->toBe(1);
    $this->assertDatabaseMissing('attachments', ['id' => $stale->id]);
    Storage::disk('local')->assertMissing($stale->path);
});

test('a pending attachment within the ttl survives', function (): void {
    Storage::fake('local');
    config()->set('attachments.pending_ttl_hours', 24);
    $fresh = agedPending(1);

    $purged = app(PurgeExpiredAttachments::class)->handle();

    expect($purged)->toBe(0);
    $this->assertDatabaseHas('attachments', ['id' => $fresh->id]);
    Storage::disk('local')->assertExists($fresh->path);
});

test('a claimed attachment is never purged however old it is', function (): void {
    Storage::fake('local');
    config()->set('attachments.pending_ttl_hours', 24);
    $path = UploadedFile::fake()->image('photo.png')->store('attachments/x', 'local');
    $message = Message::factory()->create();
    $claimed = Attachment::factory()->attachedTo($message)->create([
        'disk' => 'local',
        'path' => $path,
        'created_at' => now()->subYear(),
    ]);

    $purged = app(PurgeExpiredAttachments::class)->handle();

    expect($purged)->toBe(0);
    $this->assertDatabaseHas('attachments', ['id' => $claimed->id]);
    Storage::disk('local')->assertExists($path);
});
