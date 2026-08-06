<?php

namespace App\Http\Middleware;

use App\Data\UnreadDigestData;
use App\Data\UpdateStatusData;
use App\Enums\MessageReminderStatus;
use App\Enums\NavDestination;
use App\Enums\PostRegistrationPrompt;
use App\Enums\SidebarPosition;
use App\Enums\TeamRole;
use App\Enums\ThreadInboxFilter;
use App\Models\Channel;
use App\SlashCommands\SlashCommandRegistry;
use App\Support\Branding\BrandingAssets;
use App\Support\FrequentEmoji;
use App\Support\InstanceFingerprint;
use App\Support\MessageSearchPanel;
use App\Support\PendingInvitations;
use App\Support\ReverbConfig;
use App\Support\RosterFingerprint;
use App\Support\TranslationCatalog;
use App\Support\UpdateChecker;
use App\Support\UserAgentParser;
use App\Support\UserWorkspaces;
use App\Support\WebPushConfig;
use App\Support\WorkspacePermissions;
use App\Support\WorkspaceShell;
use App\Support\WorkspaceUnread;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Inertia\OnceProp;
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

    public function __construct(private readonly BrandingAssets $brandingAssets) {}

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
     * This list *is* the Inertia contract with the frontend, so it stays spelled
     * out here — one line per prop, naming it and saying where it comes from.
     * What it deliberately does not do is compute any of them: everything an
     * in-workspace page needs comes off {@see WorkspaceShell}, which resolves the
     * (signed in, on a workspace route, with a bound team) precondition once.
     * A null shell means there is no workspace to describe, and each of its props
     * falls back to the empty value the frontend already renders for "not here".
     * The affordance flags are the one thing that outlives the shell — the
     * settings pages draw them too — so they come off
     * {@see WorkspacePermissions}, which answers all six from one derivation.
     *
     * What is *not* here is the instance's own description — its name, branding,
     * connection details and feature toggles — along with the session facts that
     * settle at sign-in, and every roster: the ones that move when the viewer
     * writes, and the ones a third party moves behind their back. All of those
     * have an honest trigger for when they change, so they are declared next
     * door in {@see self::shareOnce()} and reach the client once per trigger.
     *
     * What is left is the residue that genuinely moves under the viewer while
     * they read: `unread`, the affordance flags, and the dock panels' payloads.
     * Those are recomputed on every navigation, and are the reason this list is
     * not simply {@see self::shareOnce()}.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function share(Request $request): array
    {
        $user = $request->user();
        $shell = WorkspaceShell::forRequest($request);
        $permissions = WorkspacePermissions::forRequest($request);
        $pinned = NavDestination::fromQuery($request->query(NavDestination::QUERY_PARAM));

        return [
            ...parent::share($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // The dock header's "invite" affordance reuses the member-invite modal,
            // so the current team's invite permission and the assignable roles ride
            // along with every workspace request.
            'canInviteToCurrentTeam' => fn (): bool => $permissions->forCurrentTeam()->canCreateInvitation ?? false,
            // Which kinds of channel the viewer may open here, per the
            // workspace's channel-creation policy. The create modal is raised
            // from the sidebar and the New menu rather than from a page of its
            // own, so this rides along instead of being threaded through them —
            // but only inside the workspace, which is what the shell answers.
            'creatableChannelVisibilities' => fn (): array => $shell instanceof WorkspaceShell ? $permissions->creatableChannelVisibilities() : [],
            // The workspace sheet offers "Workspace settings" only to someone who
            // can actually change the workspace. The page-scoped permission set
            // is not in reach from the shell, so the one flag the sheet needs
            // rides along like its siblings below.
            'canUpdateCurrentTeam' => fn (): bool => $permissions->forCurrentTeam()->canUpdateTeam ?? false,
            // The settings sidebar surfaces a team-admin "evidence" group (Audit
            // log, Security log, Exports) gated by the same permissions as the
            // Team-settings cards, so an admin can jump straight to those surfaces
            // from any settings page. Both default to false off a team / for guests.
            'canViewCurrentTeamAudit' => fn (): bool => $permissions->forCurrentTeam()->canViewAudit ?? false,
            'canViewCurrentTeamSecurityLog' => fn (): bool => $permissions->forCurrentTeam()->canViewSecurityLog ?? false,
            // The integrations settings surface (bots, tokens, webhooks) hides
            // entirely unless the viewer can manage it and the platform is on, so
            // the permission and the master toggle ride along with every request
            // to gate the nav entry and the Team-settings card.
            'canManageCurrentTeamIntegrations' => fn (): bool => $permissions->forCurrentTeam()->canManageIntegrations ?? false,
            // What the viewer has not read: the sidebar's per-channel badges,
            // every workspace's dot, and the Threads dot, in one prop. The
            // rosters above carry none of it, so they can be cached while this
            // one — the single genuinely volatile fact on a navigation — is
            // recomputed every visit. Off a workspace route there is no sidebar
            // to badge, but the rail still draws its cross-workspace dots, so
            // the per-workspace half is answered there too.
            'unread' => fn (): UnreadDigestData => $shell?->unreadDigest() ?? WorkspaceUnread::digest($user),
            // The Threads panel's inbox and its "Unread" tally, present only while
            // the dock actually has that destination pinned.
            ...$shell?->threadsPanelProps($pinned, ThreadInboxFilter::fromQuery($request->query('filter'))) ?? [],
            // The Search panel's echoed criteria and matches, on the same terms.
            ...$shell?->searchPanelProps($pinned, MessageSearchPanel::criteriaFromRequest($request)) ?? [],
            'pendingInvitations' => Inertia::optional(fn (): array => $user ? PendingInvitations::forUser($user) : []),
        ];
    }

    /**
     * Define the props that are shared once and remembered across navigations.
     *
     * The counterpart to {@see self::share()}: everything there is recomputed
     * and reserialised on every click, and everything here is not. A prop
     * belongs in this list when it **cannot change between two clicks** and
     * there is an honest trigger for when it can — an instance whose operator
     * changed something, a clock reaching the top of the hour, a session whose
     * locale or device is different. Inertia excludes a once prop *before*
     * resolving it, so a prop moved here costs zero bytes and zero closure time
     * on an ordinary navigation; that is why the two that read the filesystem
     * and parse a user agent are closures rather than eager values.
     *
     * Six groups, distinguished only by what their key is made of:
     *
     * 1. **Instance config**, behind one shared {@see InstanceFingerprint}. The
     *    key is the same digest for all of them because they change together.
     * 2. **TTL'd** — `demoResetsAt` and `update`, where the trigger is a clock
     *    rather than an event.
     * 3. **Session-, locale- or version-keyed** — what the viewer's own session
     *    settles: the language they read in, the device they arrived on, the
     *    prompt their registration queued.
     * 4. **Viewer-keyed** — `auth`, `collapsedChannelSections` and
     *    `frequentEmojis`, which change when the viewer themselves writes.
     * 5. **Workspace-keyed** — the rosters whose answer is one team's. Absent
     *    off a workspace route rather than empty, for the reason given on the
     *    group itself.
     * 6. **Fingerprinted** — the rosters a *third party* moves: the workspace
     *    list here, and `teamMembers` / `customEmojis` / `userGroups` in group
     *    5. Their key carries an aggregate over their own source rows, so the
     *    trigger is the change itself rather than anyone remembering to name
     *    them (#1253); see {@see RosterFingerprint}.
     *
     * Groups 4 and 5 are the ones with no key-shaped trigger at all: nothing
     * about a clock, a config value or a session says when a channel was
     * starred. Their trigger is the partial reload the client *already* fires
     * after that write, since the exclusion below is bypassed on partial
     * requests — the sets in `resources/js/lib/reloadProps.ts` are the
     * invalidation, and adding a prop here means checking it has one (#1252).
     * Group 6 is what is left once that runs out: a roster nobody in this
     * browser wrote, so nothing in this browser reloads it.
     *
     * Every key carries its own discriminator because the **client stores once
     * props by key and restores them by prop path**: two props sharing one key
     * collapse into a single entry client-side, and the other would come back
     * absent on the second visit rather than cached. One key per prop, however
     * many of them share a digest.
     *
     * That same property is why a key may never come back round to a value the
     * prop path no longer holds — it would restore the other one. Which is what
     * the viewer and workspace discriminators are for, and why the workspace
     * rosters are absent off a workspace route rather than shipped empty. A
     * fingerprinted key is the one exception, and safely so: it is a digest of
     * the answer, so coming back round to it is coming back round to the value
     * the client is holding under it.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function shareOnce(Request $request): array
    {
        $instance = 'instance:'.InstanceFingerprint::current();
        $locale = app()->getLocale();
        $user = $request->user();
        $prompt = $this->postRegistrationPrompt($request);
        $shell = WorkspaceShell::forRequest($request);
        $boundChannel = $request->route('channel');
        $activeChannel = $boundChannel instanceof Channel ? $boundChannel : null;
        $viewer = $this->viewerFingerprint($request);
        $sessionless = ! $request->hasSession();
        $workspaces = UserWorkspaces::forRequest($request);
        // One digest for the pair: they are two readings of one list, so a
        // change to it has to move both keys or the rail and the sheet would
        // disagree about the same workspace. The locale rides along because
        // `roleLabel` is translated server-side, exactly as `invitableRoles` is.
        $workspaceList = $viewer.':workspaces:'.$locale.':'.$workspaces->fingerprint();

        return [
            'name' => Inertia::once(fn (): string => (string) config('app.name'))->as($instance.':name'),
            // Instance branding. `logo` is the operator's mark, or null when the
            // instance still ships ours — the shipped mark is an inline SVG whose
            // lower planes ride on `currentColor`, which an uploaded file cannot
            // do, so the client only swaps in an <img> once there is something to
            // swap in. `attribution` drives the removable "Powered by" line.
            //
            // Resolving the logo is up to two filesystem stats, and it is the
            // reason this prop is a closure: the stats now run on a first load
            // and never again. The fingerprint deliberately does not cover them
            // — see {@see InstanceFingerprint}.
            'branding' => Inertia::once(fn (): array => [
                'logo' => $this->brandingAssets->logoPath() === null ? null : route('branding.logo'),
                'attribution' => (bool) config('branding.attribution'),
            ])->as($instance.':branding'),
            // Browser-facing Reverb connection details, resolved at runtime so a
            // single built image works for any operator without baking VITE_*
            // values into the bundle. Read by app.ts to configure Echo at boot —
            // and by nothing afterwards, which is what makes it a once prop
            // rather than something the shell keeps re-reading.
            'reverb' => Inertia::once(ReverbConfig::forFrontend(...))->as($instance.':reverb'),
            // Whether this instance can send web push, plus the VAPID public key
            // a browser needs to subscribe. Resolved at runtime for the same
            // reason as the Reverb block above: one published image serves every
            // operator's keypair without a rebuild. Both are withheld when no
            // keypair is configured, which is what hides the settings toggle.
            'webPush' => Inertia::once(WebPushConfig::forFrontend(...))->as($instance.':webPush'),
            // A single deploy-time flag lets self-hosters lock down public
            // registration; when off, Fortify never registers the register
            // routes, so the frontend hides its "sign up" affordances to match.
            'registrationEnabled' => Inertia::once(fn (): bool => Features::enabled(Features::registration()))
                ->as($instance.':registrationEnabled'),
            // Whether new accounts must confirm their email before using the app.
            // Off by default; the frontend reads it only to hide copy that would
            // imply a verification step that can't happen (e.g. the profile
            // "resend verification email" affordance).
            'emailVerificationEnabled' => Inertia::once(fn (): bool => (bool) config('fortify.email_verification_enabled'))
                ->as($instance.':emailVerificationEnabled'),
            // Whether this instance is the public single-shared-account demo.
            // The frontend reads it only to disable the destructive owner-level
            // controls (delete/rename the workspace, change email/password,
            // enable 2FA/passkeys, remove members) with a "disabled in the demo"
            // tooltip — the server enforces every block regardless (see
            // PreventDestructiveDemoActions), so this is UI affordance only.
            'demoMode' => Inertia::once(fn (): bool => (bool) config('demo.mode'))->as($instance.':demoMode'),
            // Single sign-on state for the login page: whether to show the
            // "Sign in with SSO" entry point (an OIDC provider is configured),
            // and whether the password form still applies (off only when SSO
            // enforcement is active — AUTH_SSO_ONLY with a configured provider —
            // where the password login POST is blocked too).
            'sso' => Inertia::once(fn (): array => [
                'oidcEnabled' => (bool) config('sso.oidc.enabled'),
                'passwordLoginEnabled' => ! config('sso.enforced'),
            ])->as($instance.':sso'),
            // The per-file and per-message attachment caps, so the composer can
            // reject an oversized or over-count drop client-side for instant
            // feedback. The upload and send endpoints re-enforce them as the
            // source of truth (see config/attachments.php).
            'attachments' => Inertia::once(fn (): array => [
                'maxSizeMb' => (int) config('attachments.max_size_mb'),
                'maxPerMessage' => (int) config('attachments.max_per_message'),
            ])->as($instance.':attachments'),
            // Whether the Giphy `/gif` picker is available, derived from the API
            // key being set. False fully hides the picker client-side (and the
            // `/gif` command is absent from autocomplete), matching the 404 the
            // search/attach endpoints return when unconfigured.
            'gifPickerEnabled' => Inertia::once(fn (): bool => filled(config('services.giphy.key')))
                ->as($instance.':gifPickerEnabled'),
            // Whether the `/poll` builder is available. False fully hides the
            // builder client-side (and the `/poll` command is absent from
            // autocomplete), matching the 404 the poll endpoints return when off.
            'pollsEnabled' => Inertia::once(fn (): bool => (bool) config('polls.enabled'))
                ->as($instance.':pollsEnabled'),
            // The integrations settings surface (bots, tokens, webhooks) hides
            // entirely unless the platform is on, so the master toggle rides
            // along to gate the nav entry and the Team-settings card. The
            // permission half of that gate stays in share(): it follows the
            // viewer's role, not the instance.
            'integrationsEnabled' => Inertia::once(fn (): bool => (bool) config('integrations.enabled'))
                ->as($instance.':integrationsEnabled'),
            // How long a deleted channel stays restorable. A fixed instance-wide
            // constant, and the delete dialog is raised from the channel shell
            // rather than from a page of its own, so it rides along here instead
            // of being threaded through the channel view as a page prop. A code
            // constant rather than a config value, which `app.version` covers.
            'channelRestoreWindowDays' => Inertia::once(fn (): int => Channel::RESTORE_WINDOW_DAYS)
                ->as($instance.':channelRestoreWindowDays'),
            // The auto-idle threshold rides every request because the detector
            // that enforces it runs in the browser: a tab has to know how long
            // "no activity" may last before it reports itself away.
            'presence' => Inertia::once(fn (): array => [
                'awayAfterMinutes' => max((int) config('presence.away_after_minutes'), 1),
            ])->as($instance.':presence'),
            'demoResetsAt' => $this->demoResetsAt($instance),
            // The instance's version standing, so authenticated users see a
            // low-key "update available" indicator when the self-hosted release
            // is behind. `current` is always present; `latest`/`notesUrl` fill in
            // once a scheduled check has cached a result (never when disabled).
            //
            // Keyed on whether anyone is signed in, because the two answers are
            // different props: without the split, a guest's null would be the
            // cached value a sign-in from that same tab never replaced. It
            // carries the instance key too, since `current` *is* the running
            // version and an in-place upgrade is what settles the comparison.
            'update' => Inertia::once(fn (): ?UpdateStatusData => $user ? app(UpdateChecker::class)->status() : null)
                ->as($instance.':update:'.($user ? 'viewer' : 'guest'))
                ->until(now()->addHours(max((int) config('updates.cache_ttl_hours', 12), 1))),
            'locale' => Inertia::once(fn (): string => app()->getLocale())->as('locale:'.$locale),
            // The active locale's catalog rides the initial document as a "once"
            // prop: it reaches the SSR render and first hydration (so the first
            // paint is already translated) but is excluded from every subsequent
            // SPA visit, keeping navigation payloads free of the catalog.
            //
            // The once key carries the locale it holds, so "already loaded" means
            // "already loaded *this* catalog". A visit that changes the effective
            // locale without a document load — signing in as a French user from
            // the English guest page — therefore ships the new catalog instead of
            // leaving the client rendering the old one (#764).
            'translations' => Inertia::once(fn () => app(TranslationCatalog::class)->messages($locale))
                ->as('translations:'.$locale),
            // The selectable sidebar positions (left / right) ride every request
            // so the user menu can offer them anywhere, not just on
            // Settings → Appearance where the page-level prop is scoped. The enum
            // is the single source of truth, shared the same way the settings page
            // sources its own options. Locale-keyed rather than fingerprinted,
            // because the labels are translated server-side.
            'sidebarPositions' => Inertia::once(SidebarPosition::options(...))->as('sidebarPositions:'.$locale),
            'invitableRoles' => Inertia::once(TeamRole::assignable(...))->as('invitableRoles:'.$locale),
            // The composer's slash-command autocomplete manifest, built from the
            // registry with copy already translated under the active locale.
            // Server-authoritative: a newly registered command appears in
            // autocomplete with no frontend change.
            //
            // Shared everywhere rather than only inside the workspace: a once
            // prop has one value per prop name for the life of the page, so a
            // settings visit answering `[]` would be the value a later workspace
            // visit restored. It is a global registry, so there is one honest
            // answer, and it is paid for once.
            //
            // Carries the instance key as well as the locale, because which
            // commands exist is instance config too: `/poll` and `/gif` are
            // registered only where their feature is configured, so an operator
            // switching one off has to take it out of autocomplete with it.
            'slashCommands' => Inertia::once(fn (): array => app(SlashCommandRegistry::class)->manifest())
                ->as("{$instance}:slashCommands:{$locale}"),
            // A readable name for the device this request came from, so a surface
            // that has to name it (the post-registration passkey prompt prefills
            // its name field with it) does not re-derive the parse client-side.
            // Joined into one line by the frontend through the `:browser on
            // :platform` key the session list already uses, so it follows a live
            // locale switch rather than freezing at render time.
            //
            // Keyed on the raw header rather than on the session: the parse is a
            // function of the header alone, and hashing it is what keeps the
            // regexes off the navigation path. Truncated for the same reason the
            // instance digest is — the key rides a request header on every
            // navigation, and a collision costs a device name, not correctness.
            'currentDevice' => Inertia::once(fn (): array => UserAgentParser::parse($request->userAgent()))
                ->as('currentDevice:'.substr(hash('xxh128', (string) $request->userAgent()), 0, 8)),
            // The one-time account-security prompt owed to an account created in
            // this session, or null. Read from the session rather than the user so
            // it dies with the session — a returning user is no longer "just
            // registered" — and re-gated per request, so switching the feature off
            // withdraws the prompt instead of offering something that would 404.
            //
            // Cached only while there is nothing to offer, which is every visit
            // but a handful: a queued prompt is forced through so that registering
            // in a tab that has already cached the empty answer still raises it.
            // Answering it clears the session key and reloads this prop by name
            // (see `usePostRegistrationPrompt`), which is what stops a refresh
            // re-asking.
            'postRegistrationPrompt' => Inertia::once(fn (): ?string => $prompt)->fresh($prompt !== null),
            // The viewer themselves. Every surface in the shell reads their
            // name, avatar, preferences, status and do-not-disturb state off
            // this one prop, and a dozen settings writes refresh it from the
            // redirect they end in rather than by naming `AUTH_PROPS`.
            //
            // So the key carries a digest of the prop's own payload: any write
            // to the viewer's row — one of those dozen, a status that has
            // lapsed, a schedule window that has opened — moves the key and the
            // next navigation ships it, without every write site having to
            // remember. The reload `useTimezone` already fires still works and
            // is still faster, being a partial request; this is the floor under
            // it rather than a replacement for it.
            'auth' => Inertia::once(fn (): array => ['user' => $user])
                ->as($viewer.':auth:'.substr(hash('xxh128', (string) json_encode($user?->toArray())), 0, 8))
                ->fresh($sessionless),
            // Which of the sidebar's built-in groups the viewer has collapsed —
            // a set on their own account, so it holds across workspaces and is
            // keyed on the viewer alone. Invalidated by `COLLAPSED_SECTION_PROPS`.
            'collapsedChannelSections' => Inertia::once(fn (): array => $user->collapsed_channel_sections ?? [])
                ->as($viewer.':collapsedChannelSections')
                ->fresh($sessionless),
            // The viewer's five most-used emoji in their current workspace,
            // feeding the hover bar's quick-react cluster and the picker's
            // "Frequently used" strip. Derived from reaction history, and now
            // derived once: the reaction write names it (`FREQUENT_EMOJI_PROPS`)
            // and re-ranks on the reply, which is the same eventual consistency
            // it always had and one query less on every navigation between.
            //
            // Shared everywhere rather than only inside the workspace, and keyed
            // on the current team rather than the bound one, because that is
            // what it reads: the ranking is the same answer on a settings page
            // as in the workspace, so there is one value per prop path.
            'frequentEmojis' => Inertia::once(fn (): array => FrequentEmoji::forUser($user))
                ->as($viewer.':frequentEmojis:'.($user?->currentTeam?->getKey() ?? 'none'))
                ->fresh($sessionless),
            // Every workspace the viewer belongs to, and which of them they are
            // standing in — the rail's tiles, the workspace sheet's rows, and
            // the workspace named on every settings page. Shared everywhere
            // rather than only inside the workspace for that last reason: there
            // is one honest answer, so there is one value per prop path.
            //
            // The first pair keyed on something nobody in this browser did. A
            // teammate joining moves a member count the viewer is looking at,
            // and no write of theirs fires to say so — so the key is a digest
            // of the list itself, over one indexed aggregate rather than the
            // list it stands for. Both halves come off one derivation, which is
            // also what stops the current workspace being counted twice.
            'teams' => Inertia::once($workspaces->all(...))->as($workspaceList.':teams')->fresh($sessionless),
            'currentTeam' => Inertia::once($workspaces->current(...))
                ->as($workspaceList.':currentTeam')
                ->fresh($sessionless),
            // The workspace's own rosters. Absent off a workspace route rather
            // than empty: a once prop has one value per prop path for the life
            // of the page, so an empty roster shipped from a settings page would
            // be the answer a later workspace visit restored. Absent, the client
            // drops the key instead — every consumer already defaults it — and
            // the way back in is a first load again.
            ...$shell instanceof WorkspaceShell ? $this->workspaceProps($shell, $viewer, $activeChannel) : [],
        ];
    }

    /**
     * The rosters that describe one workspace, keyed on which workspace that is.
     *
     * Each is invalidated by the set the client already names after the writes
     * that move it — `CHANNEL_LIST_PROPS` for a star, a drag, a mute or a draft,
     * `CHANNEL_SECTION_PROPS` for a rename or a reorder, `REMINDER_PROPS` for a
     * reminder set, cleared or come due. What none of those cover is *switching
     * workspaces*, which is not a write at all: the key moves, but the client
     * has by then declared the previous workspace's key against the same prop
     * path, and returning to it would restore the roster of the one just left.
     * `useTeamSwitch` names them for that reason.
     *
     * `channelSections` and both reminder lists are the viewer's own rows, so
     * the viewer is the only one who can move them. `channels` is not: a
     * teammate can rename a channel, archive one, or add the viewer to one.
     * Renaming the *open* channel is covered — `ChannelUpdated` reloads the
     * roster by name — and a message anywhere brings it back through
     * `useSidebarBadges`. The rest reach the sidebar on the next hard load
     * rather than the next click, which is the one thing this child knowingly
     * gives up; closing it needs broadcasts the server does not send yet.
     *
     * The last three are the ones a third party moves outright, and they are
     * keyed on a digest of their own source rows instead (#1253) — including
     * `teamMembers`, the largest shared prop there is: a 300-member workspace
     * used to re-serialise tens of kilobytes of roster on every click for an
     * answer that had not changed. What that key deliberately does not reach is
     * a *clock*: a
     * teammate's quiet hours opening writes no row, so the do-not-disturb badge
     * now follows `BroadcastDndScheduleEdges`' announcement rather than the
     * next navigation, and lands on the next hard load without one. Everything
     * else about a member — a lapsed status, a cleared pause, an avatar — is a
     * write, and a write moves the digest.
     *
     * @return array<string, mixed>
     */
    protected function workspaceProps(WorkspaceShell $shell, string $viewer, ?Channel $activeChannel): array
    {
        $workspace = $viewer.':team:'.$shell->teamKey();

        return [
            // Deliberately *not* keyed on the active channel, though the roster
            // is built with it: this is the largest prop in the shell, and a key
            // that moved with every click would cache nothing. What the active
            // channel buys is one row — a direct message with no messages yet,
            // opened by the other side, which is listed only while the viewer is
            // standing in it. Reaching one is a document load or a
            // `useNewDirectMessages` reload, both of which rebuild the list; all
            // a cached roster can do is carry that row one channel too far.
            'channels' => Inertia::once(fn (): array => $shell->channels($activeChannel))->as($workspace.':channels'),
            'channelSections' => Inertia::once($shell->channelSections(...))->as($workspace.':channelSections'),
            // The viewer's still-pending reminders in this workspace, soonest
            // first, feeding the "Reminders" list and its sidebar count.
            'reminders' => Inertia::once(fn (): array => $shell->reminders(MessageReminderStatus::Pending))
                ->as($workspace.':reminders'),
            // Reminders that have come due and await acknowledgement, driving
            // the in-app nudges; reloaded live when a MessageReminderDue signal
            // lands, which is a partial request and so reaches the client.
            'firedReminders' => Inertia::once(fn (): array => $shell->reminders(MessageReminderStatus::Fired))
                ->as($workspace.':firedReminders'),
            // The workspace's members, feeding the DM entry points (the sidebar
            // people picker and the ⌘K "People" group) and the presence dots.
            // The expensive one, and the reason this group exists at all.
            'teamMembers' => Inertia::once($shell->teamMembers(...))
                ->as($workspace.':teamMembers:'.$shell->teamMembersFingerprint()),
            // The workspace's custom emoji as a flat name->url map, so message
            // bodies and reaction pills can resolve `:name:` shortcodes to
            // images. A revoked emoji is simply absent, so its token falls back
            // to text — which is why the count is in the key: a revocation moves
            // no timestamp forward.
            'customEmojis' => Inertia::once($shell->customEmojis(...))
                ->as($workspace.':customEmojis:'.$shell->customEmojisFingerprint()),
            // The workspace's mentionable user groups, feeding the composer's
            // `@` menu and the anti-spoof check that decides whether a
            // `group:<id>` token renders as a pill or as plain text. A deleted
            // group is simply absent, so its token falls back to text.
            'userGroups' => Inertia::once($shell->userGroups(...))
                ->as($workspace.':userGroups:'.$shell->userGroupsFingerprint()),
        ];
    }

    /**
     * Which viewer the props above describe, as a once key's discriminator.
     *
     * Taken from the session's CSRF token, which Laravel rotates on both
     * sign-in and sign-out — the two moments these answers stop being this
     * viewer's, and the two the account id alone cannot see. Without it, signing
     * out would be answered under a key the client already holds for the guest
     * page and the client would restore the account that just left; signing back
     * in would restore the guest.
     *
     * Hashed because the token is a secret and this rides a request header on
     * every navigation — 32 bits of an xxh128 over a 40-character random token
     * is not a token anyone can walk back. Truncated for the same reason the
     * instance digest is: the keys are sent on every navigation, and the header
     * is already the honest cost of this whole mechanism.
     *
     * What a collision would take, and cost: two *consecutive sessions in one
     * browser tab* landing on the same 32 bits, which is the only way the client
     * still holds the earlier one's keys. For `auth` that is not enough on its
     * own — its key carries the payload digest too, so both must collide — and
     * for the other two it is one stale preference until the next hard load.
     */
    protected function viewerFingerprint(Request $request): string
    {
        if (! $request->hasSession()) {
            return 'viewer:sessionless';
        }

        return 'viewer:'.substr(hash('xxh128', $request->session()->token()), 0, 8);
    }

    /**
     * The next hourly demo wipe, as the banner's "Resets in X min" chip counts
     * down to it — null off the demo, where there is no wipe to count down to.
     *
     * The one prop here whose trigger is purely a clock: it holds until the top
     * of the next hour and is then recomputed, instead of being recomputed on
     * every click for an answer that changes hourly. Its TTL is also the
     * shortest in this file, and a once prop's TTL **caps the lifetime of a
     * prefetched page** — an hour against the 30 s prefetch cache is no
     * constraint at all, but a TTL shorter than 30 s here would silently shrink
     * that cache instead (#1237).
     *
     * It carries the instance key in both branches even though only one of them
     * has a clock, because *whether* there is a wipe to count down to is
     * instance config like any other: keeping one key for the prop is what stops
     * an instance that switches the demo on from being answered under a key the
     * client already holds the other answer for.
     */
    protected function demoResetsAt(string $instance): OnceProp
    {
        $key = $instance.':demoResetsAt';

        if (! config('demo.mode')) {
            return Inertia::once(fn (): null => null)->as($key);
        }

        $resetsAt = now()->startOfHour()->addHour();

        return Inertia::once(fn (): string => $resetsAt->toIso8601String())->as($key)->until($resetsAt);
    }

    /**
     * The post-registration prompt queued for this session, or null when there is
     * none or it is no longer on offer.
     */
    protected function postRegistrationPrompt(Request $request): ?string
    {
        // Shared props are also computed for an error page, which Inertia renders
        // from the exception handler — outside the session middleware, on a
        // request that has none.
        if (! $request->hasSession()) {
            return null;
        }

        $queued = $request->session()->get(PostRegistrationPrompt::SESSION_KEY);

        if (! is_string($queued)) {
            return null;
        }

        $prompt = PostRegistrationPrompt::tryFrom($queued);

        return $prompt?->isAvailable() ? $prompt->value : null;
    }
}
