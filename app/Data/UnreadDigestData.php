<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\WorkspaceUnread;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Everything unread the shell draws, in one prop.
 *
 * The shell's rosters say what a workspace *is* — which channels, which people,
 * in what order — and change when someone renames or joins something. What is
 * unread changes on every message anyone sends. Welding the second onto the
 * first is why the whole channel roster used to be recomputed on every
 * navigation to keep four integers current, so the two are separated here and
 * this is the volatile half: the one shared prop that rides every visit.
 *
 * Both maps are sparse — a channel or workspace with nothing waiting is simply
 * absent, and the client reads a missing key as zero — so the ordinary case,
 * where almost everything is read, costs almost nothing on the wire.
 *
 * Both readings come out of a single grouped query ({@see WorkspaceUnread}), so
 * the per-workspace dot and the per-channel badges inside it cannot disagree.
 */
#[TypeScript]
class UnreadDigestData extends Data
{
    public function __construct(
        /**
         * What is waiting in each channel of the workspace being viewed, keyed
         * by channel id. Empty off a workspace route, where no sidebar renders.
         *
         * @var array<string, UnreadCountsData>
         */
        public array $channels,
        /**
         * What is waiting in each of the viewer's workspaces, keyed by team id,
         * driving the rail's dots and the workspace sheet's rows. Present on
         * every page the dock renders on, workspace route or not.
         *
         * @var array<string, UnreadCountsData>
         */
        public array $teams,
        /** Whether any followed thread in the current workspace is unread. */
        public bool $threads,
    ) {}

    /**
     * The digest for someone with nothing to report — a guest, or a viewer off
     * any workspace at all.
     *
     * Named `none` rather than `empty`, which {@see Data} already declares with
     * an incompatible signature.
     */
    public static function none(): self
    {
        return new self(channels: [], teams: [], threads: false);
    }
}
