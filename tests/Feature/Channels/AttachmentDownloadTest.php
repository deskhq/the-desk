<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function downloadTeam(): array
{
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Store a real fake blob and return an attachment claimed by a fresh message in
 * the channel, uploaded by the given user.
 */
function storedAttachment(Channel $channel, User $uploader, UploadedFile $file, bool $attached = true): Attachment
{
    $path = $file->store("attachments/{$channel->id}", 'local');

    $message = $attached
        ? Message::factory()->for($channel)->for($uploader)->create()
        : null;

    return Attachment::factory()
        ->for($uploader)
        ->for($channel)
        ->when($attached, fn ($factory) => $factory->attachedTo($message))
        ->create([
            'disk' => 'local',
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
        ]);
}

test('a channel member can download an attached file, served inline for images with nosniff', function (): void {
    [$owner, $team, $general] = downloadTeam();
    $attachment = storedAttachment($general, $owner, UploadedFile::fake()->image('photo.png'));

    $response = $this->actingAs($owner)->get(route('channels.attachments.download', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'attachment' => $attachment->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toStartWith('inline');
    expect($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

test('a non-image file is served as an attachment download', function (): void {
    [$owner, $team, $general] = downloadTeam();
    $attachment = storedAttachment($general, $owner, UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'));

    $response = $this->actingAs($owner)->get(route('channels.attachments.download', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'attachment' => $attachment->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
});

test('an svg is never served inline', function (): void {
    [$owner, $team, $general] = downloadTeam();
    $attachment = storedAttachment($general, $owner, UploadedFile::fake()->create('logo.svg', 2, 'image/svg+xml'));

    $response = $this->actingAs($owner)->get(route('channels.attachments.download', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'attachment' => $attachment->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
});

test('a download whose blob is missing from disk 404s', function (): void {
    [$owner, $team, $general] = downloadTeam();
    $attachment = storedAttachment($general, $owner, UploadedFile::fake()->image('photo.png'));
    Storage::disk('local')->delete($attachment->path);

    $this->actingAs($owner)->get(route('channels.attachments.download', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'attachment' => $attachment->id,
    ]))->assertNotFound();
});

test('a non-member gets a 404, not a 403, to avoid disclosing the file exists', function (): void {
    [$owner, $team] = downloadTeam();
    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);
    $attachment = storedAttachment($private, $owner, UploadedFile::fake()->image('photo.png'));

    $stranger = User::factory()->create();
    $team->members()->attach($stranger, ['role' => TeamRole::Member->value]);

    $this->actingAs($stranger)->get(route('channels.attachments.download', [
        'team' => $team->slug,
        'channel' => $private->slug,
        'attachment' => $attachment->id,
    ]))->assertNotFound();
});

test('an attachment from another channel 404s under the requested channel', function (): void {
    [$owner, $team, $general] = downloadTeam();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);
    $attachment = storedAttachment($other, $owner, UploadedFile::fake()->image('photo.png'));

    $this->actingAs($owner)->get(route('channels.attachments.download', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'attachment' => $attachment->id,
    ]))->assertNotFound();
});

test('a download for a soft-deleted message is denied', function (): void {
    [$owner, $team, $general] = downloadTeam();
    $attachment = storedAttachment($general, $owner, UploadedFile::fake()->image('photo.png'));
    $attachment->message->delete();

    $this->actingAs($owner)->get(route('channels.attachments.download', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'attachment' => $attachment->id,
    ]))->assertNotFound();
});

test('the uploader can preview their own pending attachment', function (): void {
    [$owner, $team, $general] = downloadTeam();
    $attachment = storedAttachment($general, $owner, UploadedFile::fake()->image('photo.png'), attached: false);

    $this->actingAs($owner)->get(route('channels.attachments.download', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'attachment' => $attachment->id,
    ]))->assertOk();
});

test('another member cannot preview someone else pending attachment', function (): void {
    [$owner, $team, $general] = downloadTeam();
    $attachment = storedAttachment($general, $owner, UploadedFile::fake()->image('photo.png'), attached: false);

    $other = User::factory()->create();
    $team->members()->attach($other, ['role' => TeamRole::Member->value]);

    $this->actingAs($other)->get(route('channels.attachments.download', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'attachment' => $attachment->id,
    ]))->assertNotFound();
});
