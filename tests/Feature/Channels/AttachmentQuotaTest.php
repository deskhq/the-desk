<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A team with its owner in #general, with the attachment disk faked.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function quotaTeam(): array
{
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * The upload route for a team + channel.
 */
function quotaRoute(Team $team, Channel $channel): string
{
    return route('channels.attachments.store', ['team' => $team->slug, 'channel' => $channel->slug]);
}

test('an upload that would exceed the workspace quota is rejected before any blob is written', function (): void {
    config()->set('attachments.storage_quota_mb', 1);
    [$owner, $team, $general] = quotaTeam();

    Attachment::factory()->for($general)->create(['size_bytes' => 1024 * 1024]);

    $this->actingAs($owner)
        ->post(quotaRoute($team, $general), ['file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')])
        ->assertSessionHasErrors('file');

    // No row was registered and nothing landed on the disk.
    expect(Attachment::where('status', AttachmentStatus::Pending)->whereNull('message_id')->count())->toBe(1);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('an upload that fits inside the remaining quota is accepted', function (): void {
    config()->set('attachments.storage_quota_mb', 1);
    [$owner, $team, $general] = quotaTeam();

    Attachment::factory()->for($general)->create(['size_bytes' => 512 * 1024]);

    $this->actingAs($owner)
        ->post(quotaRoute($team, $general), ['file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')])
        ->assertCreated();
});

test('a pending upload counts against the quota, so staging unsent files cannot evade it', function (): void {
    config()->set('attachments.storage_quota_mb', 1);
    [$owner, $team, $general] = quotaTeam();

    Attachment::factory()->for($general)->create([
        'size_bytes' => 1024 * 1024,
        'status' => AttachmentStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->post(quotaRoute($team, $general), ['file' => UploadedFile::fake()->create('note.txt', 1, 'text/plain')])
        ->assertSessionHasErrors('file');
});

test('uploads are unlimited while no quota is configured', function (): void {
    config()->set('attachments.storage_quota_mb', 0);
    [$owner, $team, $general] = quotaTeam();

    Attachment::factory()->for($general)->create(['size_bytes' => 500 * 1024 * 1024]);

    $this->actingAs($owner)
        ->post(quotaRoute($team, $general), ['file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')])
        ->assertCreated();
});

test('a payload that is not a file is left to the plain file rule', function (): void {
    config()->set('attachments.storage_quota_mb', 1);
    [$owner, $team, $general] = quotaTeam();

    $this->actingAs($owner)
        ->post(quotaRoute($team, $general), ['file' => 'not-a-file'])
        ->assertSessionHasErrors('file');
});

test('another workspace\'s usage does not consume this one\'s quota', function (): void {
    config()->set('attachments.storage_quota_mb', 1);
    [$owner, $team, $general] = quotaTeam();
    [, , $foreign] = quotaTeam();

    Attachment::factory()->for($foreign)->create(['size_bytes' => 5 * 1024 * 1024]);

    $this->actingAs($owner)
        ->post(quotaRoute($team, $general), ['file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')])
        ->assertCreated();
});
