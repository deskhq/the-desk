<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\TeamStorageData;
use App\Enums\AttachmentSource;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Team;

/**
 * A workspace's storage accounting: how many bytes its uploads occupy on the
 * operator's disk, and whether the configured quota still has room.
 *
 * Usage is computed live rather than tracked in a counter column, so there is no
 * dual-write across the create/force-delete/purge paths and no drift to repair.
 * It equals the real disk footprint by construction: a force-deleted attachment
 * has no row (its blob is gone), while a soft-deleted one keeps its row (its blob
 * is retained), so reading trashed rows too counts exactly what is on disk.
 */
class TeamStorage
{
    /**
     * Bytes in a megabyte, the unit the quota is configured in.
     */
    private const int BYTES_PER_MEGABYTE = 1024 * 1024;

    /**
     * The configured quota in bytes, or `0` when the feature is off.
     */
    public function quotaBytes(): int
    {
        return max(0, (int) config('attachments.storage_quota_mb')) * self::BYTES_PER_MEGABYTE;
    }

    /**
     * Whether a quota is configured at all. Unset (or `0`) leaves every workspace
     * unlimited, which is the default for a self-hosted instance.
     */
    public function enabled(): bool
    {
        return $this->quotaBytes() > 0;
    }

    /**
     * The bytes the team's uploads occupy on the configured disk.
     *
     * Counts pending and claimed attachments alike — a staged file already sits on
     * disk, so leaving unsent uploads out would let anyone stage their way past
     * the quota. Giphy attachments are excluded: they are hotlinked CDN URLs with
     * no blob of the operator's to reclaim.
     */
    public function usedBytes(Team $team): int
    {
        return (int) Attachment::withTrashed()
            ->where('source', AttachmentSource::Upload)
            ->whereIn('channel_id', Channel::query()->where('team_id', $team->id)->select('id'))
            ->sum('size_bytes');
    }

    /**
     * Whether storing another `$bytes` for this team would cross its quota. Always
     * false while the feature is off.
     */
    public function wouldExceedQuota(Team $team, int $bytes): bool
    {
        $quota = $this->quotaBytes();

        if ($quota === 0) {
            return false;
        }

        return $this->usedBytes($team) + $bytes > $quota;
    }

    /**
     * The team's usage read-out for the analytics dashboard, or null while the
     * feature is off — there is no quota to measure against, so nothing is shown.
     */
    public function usage(Team $team): ?TeamStorageData
    {
        $quota = $this->quotaBytes();

        if ($quota === 0) {
            return null;
        }

        $used = $this->usedBytes($team);

        return new TeamStorageData(
            usedBytes: $used,
            quotaBytes: $quota,
            percent: (int) round($used / $quota * 100),
        );
    }
}
