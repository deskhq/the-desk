<?php

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('the url accessor resolves the authorized download route', function (): void {
    $channel = Channel::factory()->create();
    $attachment = Attachment::factory()->for($channel)->create();

    expect($attachment->url)->toBe(route('channels.attachments.download', [
        'team' => $channel->team->slug,
        'channel' => $channel->slug,
        'attachment' => $attachment->id,
    ]));
});

test('the thumb url accessor resolves the authorized thumbnail route when a thumbnail exists', function (): void {
    $channel = Channel::factory()->create();
    $attachment = Attachment::factory()->for($channel)->withThumbnail()->create();

    expect($attachment->thumb_url)->toBe(route('channels.attachments.thumbnail', [
        'team' => $channel->team->slug,
        'channel' => $channel->slug,
        'attachment' => $attachment->id,
    ]));
});

test('the thumb url accessor is null when no thumbnail was generated', function (): void {
    expect(Attachment::factory()->create()->thumbUrl)->toBeNull();
});

test('an attachment resolves its uploader, channel, and claiming message', function (): void {
    $message = Message::factory()->create();
    $attachment = Attachment::factory()->for($message->user)->attachedTo($message)->create();

    expect($attachment->user->id)->toBe($message->user_id)
        ->and($attachment->channel->id)->toBe($message->channel_id)
        ->and($attachment->message->id)->toBe($message->id);
});

test('a claimed attachment still resolves its message once that message is soft-deleted', function (): void {
    $message = Message::factory()->create();
    $attachment = Attachment::factory()->attachedTo($message)->create();
    $message->delete();

    expect($attachment->fresh()->message)->not->toBeNull()
        ->and($attachment->fresh()->message->trashed())->toBeTrue();
});

test('an image mime is inline-renderable', function (): void {
    $attachment = Attachment::factory()->make(['mime_type' => 'image/png']);

    expect($attachment->isImage())->toBeTrue();
});

test('an svg is not inline-renderable even though its mime is image-shaped', function (): void {
    $attachment = Attachment::factory()->svg()->make();

    expect($attachment->isImage())->toBeFalse();
});

test('a non-image file is not inline-renderable', function (): void {
    $attachment = Attachment::factory()->document()->make();

    expect($attachment->isImage())->toBeFalse();
});

test('force-deleting an attachment removes its blob from disk', function (): void {
    Storage::fake('local');
    $path = UploadedFile::fake()->image('photo.png')->store('attachments/x', 'local');

    $attachment = Attachment::factory()->create(['disk' => 'local', 'path' => $path]);
    Storage::disk('local')->assertExists($path);

    $attachment->forceDelete();

    Storage::disk('local')->assertMissing($path);
});

test('force-deleting an attachment removes its thumbnail blob too', function (): void {
    Storage::fake('local');
    $path = UploadedFile::fake()->image('photo.png')->store('attachments/x', 'local');
    $thumb = UploadedFile::fake()->image('thumb.png')->store('attachments/x/thumbnails', 'local');

    $attachment = Attachment::factory()->create(['disk' => 'local', 'path' => $path, 'thumb_path' => $thumb]);
    Storage::disk('local')->assertExists($thumb);

    $attachment->forceDelete();

    Storage::disk('local')->assertMissing($path);
    Storage::disk('local')->assertMissing($thumb);
});

test('soft-deleting an attachment keeps its blob on disk', function (): void {
    Storage::fake('local');
    $path = UploadedFile::fake()->image('photo.png')->store('attachments/x', 'local');

    $attachment = Attachment::factory()->create(['disk' => 'local', 'path' => $path]);

    $attachment->delete();

    expect($attachment->trashed())->toBeTrue();
    Storage::disk('local')->assertExists($path);
});
