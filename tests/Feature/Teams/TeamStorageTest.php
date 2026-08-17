<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamStorage;

/**
 * A team owned by a fresh user, with its #general channel.
 *
 * @return array{0: Team, 1: Channel}
 */
function storageTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    return [$team, $general];
}

/**
 * Register an upload of the given size in a channel, without touching any disk.
 */
function storageUpload(Channel $channel, int $bytes, array $attributes = []): Attachment
{
    return Attachment::factory()->for($channel)->create(['size_bytes' => $bytes, ...$attributes]);
}

test('usage sums the uploaded bytes across the team\'s channels', function (): void {
    [$team, $general] = storageTeam();
    $other = Channel::factory()->for($team)->create();

    storageUpload($general, 1_000);
    storageUpload($other, 2_500);

    expect(app(TeamStorage::class)->usedBytes($team))->toBe(3_500);
});

test('usage counts pending and claimed uploads alike', function (): void {
    [$team, $general] = storageTeam();

    storageUpload($general, 400, ['status' => AttachmentStatus::Pending]);
    storageUpload($general, 600, ['status' => AttachmentStatus::Attached]);

    expect(app(TeamStorage::class)->usedBytes($team))->toBe(1_000);
});

test('usage keeps counting a soft-deleted upload, whose blob is retained', function (): void {
    [$team, $general] = storageTeam();

    storageUpload($general, 900)->delete();

    expect(app(TeamStorage::class)->usedBytes($team))->toBe(900);
});

test('usage drops a force-deleted upload, whose blob is reclaimed', function (): void {
    [$team, $general] = storageTeam();

    storageUpload($general, 900)->forceDelete();

    expect(app(TeamStorage::class)->usedBytes($team))->toBe(0);
});

test('usage excludes hotlinked giphy attachments, which occupy no disk', function (): void {
    [$team, $general] = storageTeam();

    storageUpload($general, 1_200);
    Attachment::factory()->for($general)->giphy()->create(['size_bytes' => 5_000]);

    expect(app(TeamStorage::class)->usedBytes($team))->toBe(1_200);
});

test('usage is scoped to the team, ignoring another workspace\'s uploads', function (): void {
    [$team, $general] = storageTeam();
    [, $foreign] = storageTeam();

    storageUpload($general, 700);
    storageUpload($foreign, 9_000);

    expect(app(TeamStorage::class)->usedBytes($team))->toBe(700);
});

test('the quota is read from config in megabytes and converted to bytes', function (): void {
    config()->set('attachments.storage_quota_mb', 5);

    expect(app(TeamStorage::class)->quotaBytes())->toBe(5 * 1024 * 1024)
        ->and(app(TeamStorage::class)->enabled())->toBeTrue();
});

test('a zero or negative quota turns the feature off', function (int $quota): void {
    config()->set('attachments.storage_quota_mb', $quota);

    expect(app(TeamStorage::class)->quotaBytes())->toBe(0)
        ->and(app(TeamStorage::class)->enabled())->toBeFalse();
})->with([0, -1]);

test('an upload that would cross the quota is reported as exceeding it', function (): void {
    config()->set('attachments.storage_quota_mb', 1);
    [$team, $general] = storageTeam();

    storageUpload($general, 1024 * 1024 - 100);

    $storage = app(TeamStorage::class);
    expect($storage->wouldExceedQuota($team, 100))->toBeFalse()
        ->and($storage->wouldExceedQuota($team, 101))->toBeTrue();
});

test('nothing exceeds the quota while the feature is off', function (): void {
    config()->set('attachments.storage_quota_mb', 0);
    [$team, $general] = storageTeam();

    storageUpload($general, 50_000_000);

    expect(app(TeamStorage::class)->wouldExceedQuota($team, 50_000_000))->toBeFalse();
});

test('the usage read-out reports used bytes, the quota and the percent consumed', function (): void {
    config()->set('attachments.storage_quota_mb', 4);
    [$team, $general] = storageTeam();

    storageUpload($general, 1024 * 1024);

    $usage = app(TeamStorage::class)->usage($team);

    expect($usage)->not->toBeNull()
        ->and($usage->usedBytes)->toBe(1024 * 1024)
        ->and($usage->quotaBytes)->toBe(4 * 1024 * 1024)
        ->and($usage->percent)->toBe(25);
});

test('the usage read-out reports the real percent once the quota is overshot', function (): void {
    config()->set('attachments.storage_quota_mb', 1);
    [$team, $general] = storageTeam();

    storageUpload($general, 2 * 1024 * 1024);

    expect(app(TeamStorage::class)->usage($team)->percent)->toBe(200);
});

test('there is no usage read-out while the feature is off', function (): void {
    config()->set('attachments.storage_quota_mb', 0);
    [$team] = storageTeam();

    expect(app(TeamStorage::class)->usage($team))->toBeNull();
});
