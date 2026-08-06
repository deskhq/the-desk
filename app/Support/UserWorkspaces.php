<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\UserTeam;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The workspaces the viewer belongs to, and which of them they are standing in
 * — the pair the rail's tiles, the workspace sheet and every settings page that
 * names the current workspace are drawn from.
 *
 * It exists because they are one answer, not two. `currentTeam` used to be
 * derived on its own, which meant the workspace the viewer is in had its role
 * looked up and its members counted a second time, for a row already sitting in
 * `teams`. Invisible at one workspace and an N+1 across ten, so the list is
 * derived once here and the current workspace is read *out of* it.
 *
 * Derived lazily and at most once per instance, the shape
 * {@see WorkspacePermissions} uses: both props are once props keyed on the same
 * fingerprint, so on an ordinary navigation neither closure runs at all and the
 * derivation below is never reached.
 *
 * The props themselves stay named in {@see HandleInertiaRequests}, which is
 * glue; what the fingerprint is taken over is {@see RosterFingerprint}'s.
 */
final class UserWorkspaces
{
    private bool $derived = false;

    /** @var array<int, UserTeam> */
    private array $workspaces = [];

    public function __construct(private readonly ?User $viewer) {}

    /**
     * The workspaces of whoever made this request — none at all for a guest.
     */
    public static function forRequest(Request $request): self
    {
        $user = $request->user();

        return new self($user instanceof User ? $user : null);
    }

    /**
     * Every workspace the viewer belongs to, the current one included.
     *
     * @return array<int, UserTeam>
     */
    public function all(): array
    {
        $this->derive();

        return $this->workspaces;
    }

    /**
     * The workspace the viewer is standing in, or null when they are in none.
     *
     * Read out of the list rather than derived beside it, which is the whole
     * point of this class: the current workspace is one of the viewer's, so the
     * list already holds the answer and asking the database again for it is the
     * duplicate query this removes.
     */
    public function current(): ?UserTeam
    {
        return collect($this->all())->first(fn (UserTeam $workspace): bool => $workspace->isCurrent === true);
    }

    /**
     * The invalidation key the pair is shared under: what the list holds, plus
     * which of them is current.
     */
    public function fingerprint(): string
    {
        return $this->viewer instanceof User ? RosterFingerprint::forUserTeams($this->viewer) : 'guest';
    }

    /**
     * Build the list, once.
     */
    private function derive(): void
    {
        if ($this->derived) {
            return;
        }

        $this->derived = true;
        $this->workspaces = $this->viewer?->toUserTeams(includeCurrent: true)->all() ?? [];
    }
}
