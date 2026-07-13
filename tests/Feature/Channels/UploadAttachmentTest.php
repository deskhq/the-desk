<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\AttachmentStatus;
use App\Enums\TeamRole;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Create a team with its owner in #general, with the attachment disk faked.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function uploadTeam(): array
{
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * The upload route for a team + channel.
 */
function uploadRoute(Team $team, Channel $channel): string
{
    return route('channels.attachments.store', ['team' => $team->slug, 'channel' => $channel->slug]);
}

test('a channel member can pre-upload a file, storing a pending attachment', function (): void {
    [$owner, $team, $general] = uploadTeam();

    $response = $this->actingAs($owner)
        ->post(uploadRoute($team, $general), ['file' => UploadedFile::fake()->image('photo.png', 640, 480)]);

    $response->assertCreated();

    $attachment = Attachment::sole();
    expect($attachment->user_id)->toBe($owner->id)
        ->and($attachment->channel_id)->toBe($general->id)
        ->and($attachment->message_id)->toBeNull()
        ->and($attachment->status)->toBe(AttachmentStatus::Pending)
        ->and($attachment->width)->toBe(640)
        ->and($attachment->height)->toBe(480)
        ->and($attachment->original_filename)->toBe('photo.png');

    Storage::disk('local')->assertExists($attachment->path);
    $response->assertJsonPath('id', $attachment->id)
        ->assertJsonPath('isImage', true)
        ->assertJsonPath('thumbUrl', null);
});

test('a non-image upload stores no dimensions', function (): void {
    [$owner, $team, $general] = uploadTeam();

    $this->actingAs($owner)
        ->post(uploadRoute($team, $general), ['file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')])
        ->assertCreated();

    $attachment = Attachment::sole();
    expect($attachment->width)->toBeNull()->and($attachment->height)->toBeNull();
});

test('an svg is accepted but flagged non-inline', function (): void {
    [$owner, $team, $general] = uploadTeam();

    $this->actingAs($owner)
        ->post(uploadRoute($team, $general), ['file' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')])
        ->assertCreated()
        ->assertJsonPath('isImage', false);
});

test('a file over the configured size limit is rejected', function (): void {
    [$owner, $team, $general] = uploadTeam();
    config()->set('attachments.max_size_mb', 1);

    $this->actingAs($owner)
        ->post(uploadRoute($team, $general), ['file' => UploadedFile::fake()->create('big.bin', 2 * 1024)])
        ->assertInvalid(['file']);

    expect(Attachment::count())->toBe(0);
});

test('an executable file is rejected by its extension', function (): void {
    [$owner, $team, $general] = uploadTeam();

    $this->actingAs($owner)
        ->post(uploadRoute($team, $general), ['file' => UploadedFile::fake()->create('shell.php', 2)])
        ->assertInvalid(['file']);

    expect(Attachment::count())->toBe(0);
});

test('an executable disguised with a safe name is rejected by its content type', function (): void {
    [$owner, $team, $general] = uploadTeam();

    $this->actingAs($owner)
        ->post(uploadRoute($team, $general), ['file' => UploadedFile::fake()->create('safe.txt', 2, 'application/x-php')])
        ->assertInvalid(['file']);

    expect(Attachment::count())->toBe(0);
});

test('a non-file value is rejected by the file rule', function (): void {
    [$owner, $team, $general] = uploadTeam();

    $this->actingAs($owner)
        ->post(uploadRoute($team, $general), ['file' => 'not-a-file'])
        ->assertInvalid(['file']);

    expect(Attachment::count())->toBe(0);
});

test('a team member who cannot post to the channel cannot upload', function (): void {
    [$owner, $team] = uploadTeam();
    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);

    $stranger = User::factory()->create();
    $team->members()->attach($stranger, ['role' => TeamRole::Member->value]);

    $this->actingAs($stranger)
        ->post(uploadRoute($team, $private), ['file' => UploadedFile::fake()->image('photo.png')])
        ->assertForbidden();

    expect(Attachment::count())->toBe(0);
});
