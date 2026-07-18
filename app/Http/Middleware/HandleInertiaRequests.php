<?php

namespace App\Http\Middleware;

use App\Data\ChannelData;
use App\Data\ChannelSectionData;
use App\Data\CustomEmojiData;
use App\Data\MessageReminderData;
use App\Data\SlashCommandData;
use App\Data\UpdateStatusData;
use App\Data\UserData;
use App\Enums\MessageReminderStatus;
use App\Enums\MessageType;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\SlashCommands\SlashCommandRegistry;
use App\Support\ReverbConfig;
use App\Support\TranslationCatalog;
use App\Support\UpdateChecker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Middleware;
use Laravel\Fortify\Features;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    #[\Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Browser-facing Reverb connection details, resolved at runtime so a
            // single built image works for any operator without baking VITE_*
            // values into the bundle. Read by app.ts to configure Echo at boot.
            'reverb' => ReverbConfig::forFrontend(),
            'locale' => app()->getLocale(),
            // The active locale's catalog rides the initial document as a "once"
            // prop: it reaches the SSR render and first hydration (so the first
            // paint is already translated) but is excluded from every subsequent
            // SPA visit, keeping navigation payloads free of the catalog.
            'translations' => Inertia::once(fn () => app(TranslationCatalog::class)->messages(app()->getLocale())),
            // A single deploy-time flag lets self-hosters lock down public
            // registration; when off, Fortify never registers the register
            // routes, so the frontend hides its "sign up" affordances to match.
            'registrationEnabled' => Features::enabled(Features::registration()),
            // Whether new accounts must confirm their email before using the app.
            // Off by default; the frontend reads it only to hide copy that would
            // imply a verification step that can't happen (e.g. the profile
            // "resend verification email" affordance).
            'emailVerificationEnabled' => (bool) config('fortify.email_verification_enabled'),
            // Single sign-on state for the login page: whether to show the
            // "Sign in with SSO" entry point (an OIDC provider is configured),
            // and whether the password form still applies (off only when SSO
            // enforcement is active — AUTH_SSO_ONLY with a configured provider —
            // where the password login POST is blocked too).
            'sso' => [
                'oidcEnabled' => (bool) config('sso.oidc.enabled'),
                'passwordLoginEnabled' => ! config('sso.enforced'),
            ],
            // The per-file and per-message attachment caps, so the composer can
            // reject an oversized or over-count drop client-side for instant
            // feedback. The upload and send endpoints re-enforce them as the
            // source of truth (see config/attachments.php).
            'attachments' => [
                'maxSizeMb' => (int) config('attachments.max_size_mb'),
                'maxPerMessage' => (int) config('attachments.max_per_message'),
            ],
            // Whether the Giphy `/gif` picker is available, derived from the API
            // key being set. False fully hides the picker client-side (and the
            // `/gif` command is absent from autocomplete), matching the 404 the
            // search/attach endpoints return when unconfigured.
            'gifPickerEnabled' => filled(config('services.giphy.key')),
            // The instance's version standing, so authenticated users see a
            // low-key "update available" indicator when the self-hosted release
            // is behind. `current` is always present; `latest`/`notesUrl` fill in
            // once a scheduled check has cached a result (never when disabled).
            'update' => fn (): ?UpdateStatusData => $user ? app(UpdateChecker::class)->status() : null,
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            // The dock header's "invite" affordance reuses the member-invite modal,
            // so the current team's invite permission and the assignable roles ride
            // along with every workspace request.
            'canInviteToCurrentTeam' => fn () => $user?->currentTeam
                ? $user->toTeamPermissions($user->currentTeam)->canCreateInvitation
                : false,
            // The settings sidebar surfaces a team-admin "evidence" group (Audit
            // log, Security log, Exports) gated by the same permissions as the
            // Team-settings cards, so an admin can jump straight to those surfaces
            // from any settings page. Both default to false off a team / for guests.
            'canViewCurrentTeamAudit' => fn (): bool => $user?->currentTeam
                ? $user->toTeamPermissions($user->currentTeam)->canViewAudit
                : false,
            'canViewCurrentTeamSecurityLog' => fn (): bool => $user?->currentTeam
                ? $user->toTeamPermissions($user->currentTeam)->canViewSecurityLog
                : false,
            'invitableRoles' => TeamRole::assignable(),
            'channels' => fn (): array => $this->channelsForSidebar($request, $user),
            // The current team's members feed the DM entry points (the sidebar
            // people picker and the ⌘K "People" group); empty off the workspace.
            'teamMembers' => fn (): array => $this->teamMembersForSidebar($request, $user),
            'channelSections' => fn (): array => $this->channelSectionsForSidebar($request, $user),
            // The current team's custom emoji as a flat name->url map, so message
            // bodies and reaction pills can resolve `:name:` shortcodes to images.
            // A revoked emoji is simply absent, so its token falls back to text.
            'customEmojis' => fn (): array => $this->customEmojisForWorkspace($request, $user),
            // The composer's slash-command autocomplete manifest, built from the
            // registry with copy already translated under the active locale.
            // Server-authoritative: a newly registered command appears in
            // autocomplete with no frontend change. Empty off the workspace,
            // where the composer isn't rendered.
            'slashCommands' => fn (): array => $this->slashCommandsForWorkspace($request),
            'collapsedChannelSections' => fn () => $user->collapsed_channel_sections ?? [],
            'hasUnreadThreads' => fn (): bool => $this->hasUnreadThreads($request, $user),
            'pendingInvitations' => Inertia::optional(fn (): array => $user ? $this->pendingInvitationsFor($user) : []),
            // The viewer's still-pending reminders in this team, soonest first,
            // feeding the "Reminders" list and its sidebar count.
            'reminders' => fn (): array => $this->remindersForSidebar($request, $user, MessageReminderStatus::Pending),
            // Reminders that have come due and await acknowledgement, driving the
            // in-app nudges; reloaded live when a MessageReminderDue signal lands.
            'firedReminders' => fn (): array => $this->remindersForSidebar($request, $user, MessageReminderStatus::Fired),
        ];
    }

    /**
     * Whether the current request targets an in-workspace page that renders the
     * shared sidebar.
     *
     * Every such route binds a `{team}`, but not all live under the `channels.`
     * name prefix — the message search page is named `search`/`search.suggest`,
     * so it is matched explicitly. This is the single source of truth for the
     * sidebar-feeding shared props below; broaden it here to add a new workspace
     * surface rather than duplicating the pattern per prop.
     */
    protected function isWorkspaceRoute(Request $request): bool
    {
        return $request->routeIs('channels.*', 'search', 'search.suggest');
    }

    /**
     * The current user's channels for the workspace sidebar, scoped to the team in the URL.
     *
     * @return array<int, ChannelData>
     */
    protected function channelsForSidebar(Request $request, ?User $user): array
    {
        $team = $request->route('team');

        if (! $user || ! $team instanceof Team || ! $this->isWorkspaceRoute($request)) {
            return [];
        }

        $channels = $user->channels()
            ->where('channels.team_id', $team->id)
            ->whereNull('channels.archived_at')
            ->select('channels.*')
            ->addSelect(['channel_members.muted', 'channel_members.notification_level', 'channel_members.starred', 'channel_members.section_id', 'channel_members.position', 'channel_members.hidden_at'])
            // The channel's latest message time drives the "Direct messages" group
            // ordering (recent activity first) and, being null when a DM has no
            // messages yet, the listing predicate that hides an empty DM from its
            // recipient below.
            ->selectSub(
                Message::query()->selectRaw('max(messages.created_at)')->whereColumn('messages.channel_id', 'channels.id'),
                'last_message_at'
            )
            // Only the presence of a draft drives the sidebar cue; the draft text
            // itself is shipped solely to the open channel, so keep it out of the
            // sidebar payload and expose a 1/0 flag instead (an integer, not a
            // driver-specific boolean, so the DTO's cast reads it reliably).
            ->selectRaw("case when channel_members.draft is not null and channel_members.draft != '' then 1 else 0 end as has_draft")
            // Thread-only replies stay out of the plain unread badge (they live
            // in the thread view), but a mention anywhere — including inside a
            // thread — still badges the channel.
            ->selectSub($this->unreadMessages($user)
                ->where(fn (Builder $query) => $query->whereNull('messages.thread_root_id')->orWhere('messages.sent_to_channel', true)), 'unread_count')
            ->selectSub($this->unreadMessages($user)->whereHas('mentionedUsers', fn ($query) => $query->whereKey($user->id)), 'mention_count')
            // Manual order within each sidebar group first, then alphabetical as a
            // stable tiebreak for channels the user has never reordered.
            ->orderBy('channel_members.position')
            ->orderBy('channels.name')
            ->get();

        // A DM is listed once it has real activity: it has at least one message,
        // or the viewer created it (the initiator navigated straight into it), or
        // the viewer is currently viewing it. An empty DM the recipient was never
        // messaged in therefore stays hidden for them. A DM the viewer closed
        // stays out until a message arrives after the close instant — that check
        // wins even over the created/active overrides, so closing removes the row
        // immediately. Standard channels always list. `directParticipantFor`/
        // ordering are then applied client-side.
        $activeChannel = $request->route('channel');
        $activeChannelId = $activeChannel instanceof Channel ? $activeChannel->getKey() : null;

        $channels = $channels->filter(function (Channel $channel) use ($user, $activeChannelId): bool {
            if (! $channel->isDirectMessage()) {
                return true;
            }

            if ($this->directMessageHidden($channel)) {
                return false;
            }
            if ($channel->getAttribute('last_message_at') !== null) {
                return true;
            }
            if ($channel->created_by === $user->id) {
                return true;
            }

            return $channel->getKey() === $activeChannelId;
        })->values();

        return ChannelData::collect($channels, 'array');
    }

    /**
     * Whether the viewer has closed (hidden) the direct message and no message has
     * arrived since. A reply after the close instant re-surfaces the DM without
     * any write on the message path, so the check compares the two timestamps.
     */
    protected function directMessageHidden(Channel $channel): bool
    {
        $hiddenAt = $channel->getAttribute('hidden_at');

        if ($hiddenAt === null) {
            return false;
        }

        $lastMessageAt = $channel->getAttribute('last_message_at');

        return $lastMessageAt === null
            || Carbon::parse($lastMessageAt)->lessThanOrEqualTo(Carbon::parse($hiddenAt));
    }

    /**
     * The current team's members, feeding the DM entry points (the sidebar
     * people picker and the quick-switcher "People" group). Ordered by name and
     * including the viewer themselves (a self-DM renders as "You"). Empty off the
     * channel workspace, where the entry points are absent.
     *
     * @return array<int, UserData>
     */
    protected function teamMembersForSidebar(Request $request, ?User $user): array
    {
        $team = $request->route('team');

        if (! $user || ! $team instanceof Team || ! $this->isWorkspaceRoute($request)) {
            return [];
        }

        return UserData::collect($team->members()->orderBy('name')->get(), 'array');
    }

    /**
     * The current user's reminders in the team in the URL, filtered by status.
     *
     * Pending reminders feed the "Reminders" list (and its sidebar count); fired
     * ones drive the in-app nudges. Both are scoped to messages in the current
     * team and eager-load the message, its author, and its channel + team so each
     * row renders a quote and a working link back. Ordered by due time. Empty off
     * the channel workspace, where the surfaces are absent.
     *
     * @return array<int, MessageReminderData>
     */
    protected function remindersForSidebar(Request $request, ?User $user, MessageReminderStatus $status): array
    {
        $team = $request->route('team');

        if (! $user || ! $team instanceof Team || ! $this->isWorkspaceRoute($request)) {
            return [];
        }

        $reminders = MessageReminder::query()
            ->where('user_id', $user->id)
            ->where('status', $status)
            ->whereHas('message.channel', fn (Builder $query) => $query->where('team_id', $team->id))
            ->with(['message.user', 'message.channel.team'])
            ->oldest('remind_at')
            ->get();

        return MessageReminderData::collect($reminders, 'array');
    }

    /**
     * The team-in-the-URL's custom emoji as a flat `name => url` map, feeding
     * the shortcode rendering in message bodies, reaction pills, and the picker's
     * "Custom" section. Empty off the channel workspace, where messages (and thus
     * the shortcode surfaces) aren't rendered.
     *
     * @return array<string, string>
     */
    protected function customEmojisForWorkspace(Request $request, ?User $user): array
    {
        $team = $request->route('team');

        if (! $user || ! $team instanceof Team || ! $this->isWorkspaceRoute($request)) {
            return [];
        }

        return CustomEmojiData::mapForTeam($team);
    }

    /**
     * The composer's slash-command autocomplete manifest, or an empty list off
     * the workspace (where no composer renders). The manifest is global and
     * unscoped to a team — the v1 commands are unrestricted — but built per
     * request so its copy reflects the active locale.
     *
     * @return array<int, SlashCommandData>
     */
    protected function slashCommandsForWorkspace(Request $request): array
    {
        if (! $this->isWorkspaceRoute($request)) {
            return [];
        }

        return app(SlashCommandRegistry::class)->manifest();
    }

    /**
     * The current user's custom sidebar sections for the team in the URL.
     *
     * Ordered by the user's manual section order, so the sidebar can render the
     * custom groups between "Starred" and the default "Channels" list. Empty off
     * the channel workspace, where the sidebar is absent.
     *
     * @return array<int, ChannelSectionData>
     */
    protected function channelSectionsForSidebar(Request $request, ?User $user): array
    {
        $team = $request->route('team');

        if (! $user || ! $team instanceof Team || ! $this->isWorkspaceRoute($request)) {
            return [];
        }

        $sections = $user->channelSections()
            ->where('team_id', $team->id)
            ->orderBy('position')->oldest()
            ->get();

        return ChannelSectionData::collect($sections, 'array');
    }

    /**
     * Whether the user has any unread followed thread in the team, driving the
     * sidebar's "Threads" unread dot.
     *
     * Scoped to the user's channels in the team (the same ACL as the inbox), and
     * muted per channel, so it agrees with the dots the inbox and channel views
     * show. Returns false off the channel workspace, where the sidebar is absent.
     */
    protected function hasUnreadThreads(Request $request, ?User $user): bool
    {
        $team = $request->route('team');

        if (! $user || ! $team instanceof Team || ! $this->isWorkspaceRoute($request)) {
            return false;
        }

        return Message::query()
            ->whereIn('channel_id', $user->visibleChannelIds($team))
            ->whereNull('thread_root_id')
            ->where('reply_count', '>', 0)
            ->followedBy($user)
            ->whereThreadUnreadFor($user)
            ->exists();
    }

    /**
     * A correlated sub-query counting a channel's messages the user has not yet read.
     *
     * "Unread" is every non-deleted message authored by someone else that lands
     * after the user's `last_read_message_id` (a null pointer means the channel
     * was never opened, so everything counts). It is correlated against the outer
     * sidebar query's `channels` and `channel_members` rows, so a single query
     * fills every channel's badge without an N+1.
     *
     * @return Builder<Message>
     */
    protected function unreadMessages(User $user): Builder
    {
        return Message::query()
            ->selectRaw('count(*)')
            ->whereColumn('messages.channel_id', 'channels.id')
            ->where('messages.user_id', '!=', $user->id)
            // System notices (member joined / left) are ambient: they never
            // advance the unread badge or raise a mention, so they are excluded
            // from both the unread and mention counts.
            ->where('messages.type', MessageType::Standard->value)
            ->where(fn (Builder $query) => $query
                ->whereNull('channel_members.last_read_message_id')
                ->orWhereColumn('messages.id', '>', 'channel_members.last_read_message_id'));
    }

    /**
     * The user's pending (unaccepted, unexpired) team invitations.
     *
     * @return array<int, array{code: string, inviterName: string, team: array{name: string, slug: string}}>
     */
    protected function pendingInvitationsFor(User $user): array
    {
        $email = strtolower($user->email);

        return TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation): array => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ])
            ->all();
    }
}
