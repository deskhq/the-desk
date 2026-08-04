<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\TeamPermissions;
use App\Enums\ChannelVisibility;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * What the viewer may do in the workspace they are currently in, derived once
 * per request and read by every shared prop that gates an affordance on it.
 *
 * The six props exist because six surfaces are raised from the shell rather
 * than from a page of their own — the invite modal, the workspace sheet, the
 * settings sidebar's evidence group, the integrations entry and the create-
 * channel modal — so each needs its answer everywhere rather than on one page.
 * What they do not need is six answers: they all describe the same viewer in
 * the same workspace, and asking six times ran the membership lookup five times
 * over and evaluated eighty-odd abilities to read six of them.
 *
 * So this collapses rather than caches. It is derived fresh on every request —
 * these flags gate affordances only, every one has server-side enforcement
 * behind it, and at this size cheap freshness beats a staleness debt (#1250).
 * What it does not do is derive twice: the whole derivation runs inside one
 * {@see User::holdingTeamRole()} window, so the fifteen team abilities and the
 * channel-creation ones share the single role lookup they all depend on.
 *
 * The props themselves stay named in {@see HandleInertiaRequests::share()},
 * which is glue; whether there is a channel-creation affordance to describe at
 * all is still {@see WorkspaceShell}'s question, since that one is drawn only
 * inside the workspace.
 */
final class WorkspacePermissions
{
    private bool $derived = false;

    private ?TeamPermissions $teamPermissions = null;

    /** @var array<int, string> */
    private array $creatableChannelVisibilities = [];

    public function __construct(private readonly ?User $viewer) {}

    /**
     * The permissions of whoever made this request, in whichever workspace they
     * are currently in. Both may be absent — a guest, or an account with no
     * current team — and the derivation then answers for nobody.
     */
    public static function forRequest(Request $request): self
    {
        $user = $request->user();

        return new self($user instanceof User ? $user : null);
    }

    /**
     * The viewer's abilities in their current workspace, or null when there is
     * no viewer or no workspace to describe.
     */
    public function forCurrentTeam(): ?TeamPermissions
    {
        $this->derive();

        return $this->teamPermissions;
    }

    /**
     * The channel visibilities the viewer may create in their current workspace.
     *
     * Asked of the policy rather than derived from the role, so the affordance
     * and the create endpoint answer the same question the same way. An empty
     * list withdraws the affordance entirely, matching the 403 the create
     * endpoint would answer with.
     *
     * @return array<int, string>
     */
    public function creatableChannelVisibilities(): array
    {
        $this->derive();

        return $this->creatableChannelVisibilities;
    }

    /**
     * Answer every question this holds, once, off one role lookup.
     *
     * Lazy rather than eager because `share()` runs on requests that read none
     * of it — a partial reload naming other props, an error page — and the
     * viewer's current team is a relation this would otherwise load for nothing.
     */
    private function derive(): void
    {
        if ($this->derived) {
            return;
        }

        $this->derived = true;

        $viewer = $this->viewer;
        $team = $viewer?->currentTeam;

        if (! $viewer instanceof User || ! $team instanceof Team) {
            return;
        }

        $viewer->holdingTeamRole($team, function () use ($viewer, $team): void {
            $this->teamPermissions = $viewer->toTeamPermissions($team);
            $this->creatableChannelVisibilities = array_values(array_map(
                fn (ChannelVisibility $visibility): string => $visibility->value,
                array_filter(
                    ChannelVisibility::cases(),
                    fn (ChannelVisibility $visibility): bool => $viewer->can('create', [Channel::class, $team, $visibility]),
                ),
            ));
        });
    }
}
