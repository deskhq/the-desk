<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\ChannelData;
use App\Data\ChannelSectionData;
use App\Data\CustomEmojiData;
use App\Data\MessageReminderData;
use App\Data\MessageSearchCriteria;
use App\Data\SlashCommandData;
use App\Data\UserData;
use App\Data\UserGroupData;
use App\Enums\ChannelVisibility;
use App\Enums\MessageReminderStatus;
use App\Enums\NavDestination;
use App\Enums\ThreadInboxFilter;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\SlashCommands\SlashCommandRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\ProvidesScrollMetadata;

/**
 * Everything an in-workspace page needs to draw its shell — the sidebar's
 * channels, sections, members and reminders, the emoji and group vocabularies
 * message bodies resolve against, the composer's command manifest, and the dock
 * panels' payloads.
 *
 * It exists so that "the shell" is an addressable thing rather than 40-odd
 * unrelated closures in a middleware. Two properties matter:
 *
 * 1. **One precondition.** Every one of these read-models is meaningful only for
 *    a signed-in viewer, on a workspace route, with a team bound to it. That is
 *    resolved once by {@see self::forRequest()}, which answers null when it does
 *    not hold; every prop below then simply does not exist rather than each
 *    re-deriving "am I on a workspace page?" for itself.
 * 2. **No request.** The constructor takes a viewer and a team, so every
 *    read-model is reachable from a test without an HTTP round-trip. What is
 *    genuinely request state — which dock destination is pinned, the search
 *    facets on the URL — is passed in as a resolved value.
 *
 * The shell does not name the props it feeds. That list is the Inertia contract
 * and it stays in {@see HandleInertiaRequests::share()}, which is glue: it names
 * the props and computes none of them.
 */
final readonly class WorkspaceShell
{
    public function __construct(
        private User $viewer,
        private Team $team,
    ) {}

    /**
     * The shell for the current request, or null when there is none to build: a
     * guest, a route outside the workspace, or one with no team bound.
     *
     * This is the one place that composite precondition is resolved.
     */
    public static function forRequest(Request $request): ?self
    {
        $user = $request->user();
        $team = $request->route('team');

        if (! $user instanceof User || ! $team instanceof Team || ! self::isWorkspaceRoute($request)) {
            return null;
        }

        return new self($user, $team);
    }

    /**
     * Whether the current request targets an in-workspace page that renders the
     * shared sidebar.
     *
     * Every such route binds a `{team}` and lives under the `channels.` name
     * prefix — search used to be the exception, until it became a dock panel on
     * these very routes and its old URL a redirect. This is the single source of
     * truth for whether a shell exists at all; broaden it here to add a new
     * workspace surface rather than duplicating the pattern per prop.
     */
    private static function isWorkspaceRoute(Request $request): bool
    {
        return $request->routeIs('channels.*');
    }

    /**
     * The viewer's channels for the sidebar, with `$activeChannel` — the channel
     * they are currently looking at, if any — always listed.
     *
     * @return array<int, ChannelData>
     */
    public function channels(?Channel $activeChannel): array
    {
        return new SidebarChannels($this->viewer, $this->team)->forSidebar($activeChannel);
    }

    /**
     * The team's members, feeding the DM entry points (the sidebar people picker
     * and the quick-switcher "People" group). Ordered by name and including the
     * viewer themselves — a self-DM renders as "You".
     *
     * @return array<int, UserData>
     */
    public function teamMembers(): array
    {
        return UserData::collect($this->team->members()->orderBy('name')->get(), 'array');
    }

    /**
     * The viewer's custom sidebar sections, in their manual order, so the sidebar
     * can render the custom groups between "Starred" and the default "Channels".
     *
     * @return array<int, ChannelSectionData>
     */
    public function channelSections(): array
    {
        $sections = $this->viewer->channelSections()
            ->where('team_id', $this->team->id)
            ->orderBy('position')->oldest()
            ->get();

        return ChannelSectionData::collect($sections, 'array');
    }

    /**
     * The team's custom emoji as a flat `name => url` map, feeding the shortcode
     * rendering in message bodies, reaction pills, and the picker's "Custom"
     * section. A revoked emoji is simply absent, so its token falls back to text.
     *
     * @return array<string, string>
     */
    public function customEmojis(): array
    {
        return CustomEmojiData::mapForTeam($this->team);
    }

    /**
     * The team's mentionable user groups, feeding the composer's `@` menu and the
     * group-pill resolution in message bodies. A deleted group is simply absent,
     * so its token falls back to text.
     *
     * @return array<int, UserGroupData>
     */
    public function userGroups(): array
    {
        return UserGroupData::mentionableForTeam($this->team);
    }

    /**
     * The channel visibilities the viewer may create in this workspace.
     *
     * Asked of the policy rather than derived from the role here, so the shell's
     * affordance and the create endpoint answer the same question the same way.
     * An empty list withdraws the affordance entirely, matching the 403 the
     * create endpoint would answer with.
     *
     * @return array<int, string>
     */
    public function creatableChannelVisibilities(): array
    {
        return array_values(array_map(
            fn (ChannelVisibility $visibility): string => $visibility->value,
            array_filter(
                ChannelVisibility::cases(),
                fn (ChannelVisibility $visibility): bool => $this->viewer->can('create', [Channel::class, $this->team, $visibility]),
            ),
        ));
    }

    /**
     * The viewer's reminders in this workspace with the given status, soonest
     * first: pending ones feed the "Reminders" list and its sidebar count, fired
     * ones drive the in-app nudges.
     *
     * @return array<int, MessageReminderData>
     */
    public function reminders(MessageReminderStatus $status): array
    {
        return new SidebarReminders($this->viewer, $this->team)->withStatus($status);
    }

    /**
     * Whether the viewer has any unread followed thread here, driving the rail's
     * and the tab bar's "Threads" dot.
     */
    public function hasUnreadThreads(): bool
    {
        return $this->threadInbox()->hasUnread();
    }

    /**
     * The Threads destination's props: one page of the inbox and how many
     * followed threads are unread — present only while that destination is the
     * pinned one.
     *
     * Keyed on the destination being pinned (`?nav=threads`) rather than
     * deferred, exactly as `?thread=` decides whether the thread panel's payload
     * rides along. A deferred prop would be worse than it sounds: the client
     * requests every deferred group straight after each visit, so the inbox — a
     * cursor-paginated query with a 30-card payload — would run on every
     * workspace navigation instead of only while the panel is open.
     *
     * @return array<string, mixed>
     */
    public function threadsPanelProps(?NavDestination $pinned, ThreadInboxFilter $filter): array
    {
        if ($pinned !== NavDestination::Threads) {
            return [];
        }

        $inbox = $this->threadInbox();

        return [
            // The page carries its own scroll metadata, read off the models before
            // they became cards — see {@see ThreadInboxPage} for why the paginator
            // cannot be handed over directly.
            'threads' => Inertia::scroll(
                fn (): ThreadInboxPage => $inbox->page($filter),
                metadata: fn (ThreadInboxPage $page): ProvidesScrollMetadata => $page->metadata(),
            ),
            'unreadThreadCount' => $inbox->unreadCount(...),
        ];
    }

    /**
     * The Search destination's props: the matches the URL's criteria select, the
     * criteria they were selected by, and the cross-workspace channel union the
     * channel facet offers in "All workspaces" mode.
     *
     * The panel's state is the URL, not the echoed criteria — it reads what to
     * search from there ({@see resources/js/lib/searchPanel.ts}) and uses the echo
     * only to tell whether the matches it holds still answer it.
     *
     * Keyed on `?nav=search` for the same reason the Threads inbox above is: a
     * search runs a full-text query, and a deferred prop would run it on every
     * workspace navigation instead of only while the panel is open. Both stay
     * closures so a partial reload of the matches alone does not also rebuild the
     * channel union.
     *
     * @return array<string, mixed>
     */
    public function searchPanelProps(?NavDestination $pinned, MessageSearchCriteria $criteria): array
    {
        if ($pinned !== NavDestination::Search) {
            return [];
        }

        $panel = new MessageSearchPanel($this->viewer, $this->team, $criteria);

        return [
            'searchCriteria' => $panel->criteria(),
            'searchResults' => $panel->results(...),
            'searchWorkspaceChannels' => $panel->workspaceChannels(...),
        ];
    }

    /**
     * The composer's slash-command autocomplete manifest.
     *
     * Global and unscoped to a team — the v1 commands are unrestricted — but
     * built per request so its copy reflects the active locale. Server
     * authoritative: a newly registered command appears in autocomplete with no
     * frontend change.
     *
     * @return array<int, SlashCommandData>
     */
    public function slashCommands(): array
    {
        return app(SlashCommandRegistry::class)->manifest();
    }

    /**
     * The viewer's Threads read model for this workspace.
     */
    private function threadInbox(): ThreadInbox
    {
        return new ThreadInbox($this->viewer, $this->team);
    }
}
