<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function thumbnailTeam(): array
{
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Register an image attachment with a thumbnail blob on disk, claimed by a fresh
 * message in the channel. Pass storeThumb: false to leave thumb_path set but the
 * blob absent.
 */
function storedThumbnailAttachment(Channel $channel, User $uploader, bool $attached = true, bool $storeThumb = true): Attachment
{
    $thumbPath = "attachments/{$channel->id}/thumbnails/photo.png";

    if ($storeThumb) {
        Storage::disk('local')->put($thumbPath, 'thumbnail-bytes');
    }

    $message = $attached
        ? Message::factory()->for($channel)->for($uploader)->create()
        : null;

    return Attachment::factory()
        ->for($uploader)
        ->for($channel)
        ->when($attached, fn ($factory) => $factory->attachedTo($message))
        ->create([
            'disk' => 'local',
            'path' => "attachments/{$channel->id}/photo.png",
            'thumb_path' => $thumbPath,
            'mime_type' => 'image/png',
        ]);
}

/**
 * The thumbnail route for an attachment.
 */
function thumbnailRoute(Team $team, Channel $channel, Attachment $attachment): string
{
    return route('channels.attachments.thumbnail', [
        'team' => $team->slug,
        'channel' => $channel->slug,
        'attachment' => $attachment->id,
    ]);
}

test('a channel member can fetch a thumbnail, served inline with nosniff', function (): void {
    [$owner, $team, $general] = thumbnailTeam();
    $attachment = storedThumbnailAttachment($general, $owner);

    $response = $this->actingAs($owner)->get(thumbnailRoute($team, $general, $attachment));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toStartWith('inline');
    expect($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

test('an attachment with no thumbnail 404s', function (): void {
    [$owner, $team, $general] = thumbnailTeam();
    $message = Message::factory()->for($general)->for($owner)->create();
    $attachment = Attachment::factory()->for($owner)->for($general)->attachedTo($message)->create(['thumb_path' => null]);

    $this->actingAs($owner)->get(thumbnailRoute($team, $general, $attachment))->assertNotFound();
});

test('a thumbnail whose blob is missing from disk 404s', function (): void {
    [$owner, $team, $general] = thumbnailTeam();
    $attachment = storedThumbnailAttachment($general, $owner, storeThumb: false);

    $this->actingAs($owner)->get(thumbnailRoute($team, $general, $attachment))->assertNotFound();
});

test('a non-member gets a 404 for a thumbnail, not a 403', function (): void {
    [$owner, $team] = thumbnailTeam();
    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);
    $attachment = storedThumbnailAttachment($private, $owner);

    $stranger = User::factory()->create();
    $team->members()->attach($stranger, ['role' => TeamRole::Member->value]);

    $this->actingAs($stranger)->get(thumbnailRoute($team, $private, $attachment))->assertNotFound();
});

test('a thumbnail from another channel 404s under the requested channel', function (): void {
    [$owner, $team, $general] = thumbnailTeam();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);
    $attachment = storedThumbnailAttachment($other, $owner);

    $this->actingAs($owner)->get(thumbnailRoute($team, $general, $attachment))->assertNotFound();
});

test('a thumbnail for a soft-deleted message is denied', function (): void {
    [$owner, $team, $general] = thumbnailTeam();
    $attachment = storedThumbnailAttachment($general, $owner);
    $attachment->message->delete();

    $this->actingAs($owner)->get(thumbnailRoute($team, $general, $attachment))->assertNotFound();
});
