<?php

namespace App\Http\Middleware;

use App\Data\UpdateStatusData;
use App\Enums\MessageReminderStatus;
use App\Enums\NavDestination;
use App\Enums\PostRegistrationPrompt;
use App\Enums\SidebarPosition;
use App\Enums\TeamRole;
use App\Enums\ThreadInboxFilter;
use App\Models\Channel;
use App\Support\Branding\BrandingAssets;
use App\Support\FrequentEmoji;
use App\Support\MessageSearchPanel;
use App\Support\PendingInvitations;
use App\Support\ReverbConfig;
use App\Support\TranslationCatalog;
use App\Support\UpdateChecker;
use App\Support\UserAgentParser;
use App\Support\WebPushConfig;
use App\Support\WorkspaceShell;
use Illuminate\Http\Request;
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
     * What it deliberately does not do is compute any of them: instance-level
     * props read config or a `Support` helper, and everything an in-workspace
     * page needs comes off {@see WorkspaceShell}, which resolves the
     * (signed in, on a workspace route, with a bound team) precondition once.
     * A null shell means there is no workspace to describe, and each of its props
     * falls back to the empty value the frontend already renders for "not here".
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
        $pinned = NavDestination::fromQuery($request->query(NavDestination::QUERY_PARAM));
        $route = $request->route('channel');
        $activeChannel = $route instanceof Channel ? $route : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Instance branding. `logo` is the operator's mark, or null when the
            // instance still ships ours — the shipped mark is an inline SVG whose
            // lower planes ride on `currentColor`, which an uploaded file cannot
            // do, so the client only swaps in an <img> once there is something to
            // swap in. `attribution` drives the removable "Powered by" line.
            'branding' => [
                'logo' => $this->brandingAssets->logoPath() === null ? null : route('branding.logo'),
                'attribution' => (bool) config('branding.attribution'),
            ],
            // Browser-facing Reverb connection details, resolved at runtime so a
            // single built image works for any operator without baking VITE_*
            // values into the bundle. Read by app.ts to configure Echo at boot.
            'reverb' => ReverbConfig::forFrontend(),
            // Whether this instance can send web push, plus the VAPID public key
            // a browser needs to subscribe. Resolved at runtime for the same
            // reason as the Reverb block above: one published image serves every
            // operator's keypair without a rebuild. Both are withheld when no
            // keypair is configured, which is what hides the settings toggle.
            'webPush' => WebPushConfig::forFrontend(),
            'locale' => app()->getLocale(),
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
            'translations' => Inertia::once(fn () => app(TranslationCatalog::class)->messages(app()->getLocale()))
                ->as('translations:'.app()->getLocale()),
            // A single deploy-time flag lets self-hosters lock down public
            // registration; when off, Fortify never registers the register
            // routes, so the frontend hides its "sign up" affordances to match.
            'registrationEnabled' => Features::enabled(Features::registration()),
            // Whether new accounts must confirm their email before using the app.
            // Off by default; the frontend reads it only to hide copy that would
            // imply a verification step that can't happen (e.g. the profile
            // "resend verification email" affordance).
            'emailVerificationEnabled' => (bool) config('fortify.email_verification_enabled'),
            // Whether this instance is the public single-shared-account demo.
            // The frontend reads it only to disable the destructive owner-level
            // controls (delete/rename the workspace, change email/password,
            // enable 2FA/passkeys, remove members) with a "disabled in the demo"
            // tooltip — the server enforces every block regardless (see
            // PreventDestructiveDemoActions), so this is UI affordance only.
            'demoMode' => (bool) config('demo.mode'),
            // ISO-8601 instant of the next hourly demo wipe (top of the hour),
            // so the banner's "Resets in X min" chip ticks against the real
            // schedule (see routes/console.php). Null off the demo.
            'demoResetsAt' => config('demo.mode')
                ? now()->startOfHour()->addHour()->toIso8601String()
                : null,
            // Single sign-on state for the login page: whether to show the
            // "Sign in with SSO" entry point (an OIDC provider is configured),
            // and whether the password form still applies (off only when SSO
            // enforcement is active — AUTH_SSO_ONLY with a configured provider —
            // where the password login POST is blocked too).
            'sso' => [
                'oidcEnabled' => (bool) config('sso.oidc.enabled'),
                'passwordLoginEnabled' => ! config('sso.enforced'),
            ],
            // A readable name for the device this request came from, so a surface
            // that has to name it (the post-registration passkey prompt prefills
            // its name field with it) does not re-derive the parse client-side.
            // Joined into one line by the frontend through the `:browser on
            // :platform` key the session list already uses, so it follows a live
            // locale switch rather than freezing at render time.
            'currentDevice' => UserAgentParser::parse($request->userAgent()),
            // The one-time account-security prompt owed to an account created in
            // this session, or null. Read from the session rather than the user so
            // it dies with the session — a returning user is no longer "just
            // registered" — and re-gated per request, so switching the feature off
            // withdraws the prompt instead of offering something that would 404.
            'postRegistrationPrompt' => $this->postRegistrationPrompt($request),
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
            // Whether the `/poll` builder is available. False fully hides the
            // builder client-side (and the `/poll` command is absent from
            // autocomplete), matching the 404 the poll endpoints return when off.
            'pollsEnabled' => (bool) config('polls.enabled'),
            // The instance's version standing, so authenticated users see a
            // low-key "update available" indicator when the self-hosted release
            // is behind. `current` is always present; `latest`/`notesUrl` fill in
            // once a scheduled check has cached a result (never when disabled).
            'update' => fn (): ?UpdateStatusData => $user ? app(UpdateChecker::class)->status() : null,
            'auth' => [
                'user' => $user,
            ],
            // The selectable sidebar positions (left / right) ride every request
            // so the user-menu quick switcher can offer them anywhere, not just on
            // Settings → Appearance where the page-level prop is scoped. The enum
            // is the single source of truth, shared the same way the settings page
            // sources its own options.
            'sidebarPositions' => SidebarPosition::options(),
            // The auto-idle threshold rides every request because the detector
            // that enforces it runs in the browser: a tab has to know how long
            // "no activity" may last before it reports itself away.
            'presence' => [
                'awayAfterMinutes' => max((int) config('presence.away_after_minutes'), 1),
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
            // Which kinds of channel the viewer may open here, per the
            // workspace's channel-creation policy. The create modal is raised
            // from the sidebar and the New menu rather than from a page of its
            // own, so this rides along instead of being threaded through them.
            'creatableChannelVisibilities' => fn (): array => $shell?->creatableChannelVisibilities() ?? [],
            // The workspace sheet offers "Workspace settings" only to someone who
            // can actually change the workspace. The page-scoped permission set
            // is not in reach from the shell, so the one flag the sheet needs
            // rides along like its siblings below.
            'canUpdateCurrentTeam' => fn (): bool => $user?->currentTeam
                ? $user->toTeamPermissions($user->currentTeam)->canUpdateTeam
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
            // The integrations settings surface (bots, tokens, webhooks) hides
            // entirely unless the viewer can manage it and the platform is on, so
            // the permission and the master toggle ride along with every request
            // to gate the nav entry and the Team-settings card.
            'canManageCurrentTeamIntegrations' => fn (): bool => $user?->currentTeam
                ? $user->toTeamPermissions($user->currentTeam)->canManageIntegrations
                : false,
            'integrationsEnabled' => (bool) config('integrations.enabled'),
            // How long a deleted channel stays restorable. A fixed instance-wide
            // constant, and the delete dialog is raised from the channel shell
            // rather than from a page of its own, so it rides along here instead
            // of being threaded through the channel view as a page prop.
            'channelRestoreWindowDays' => Channel::RESTORE_WINDOW_DAYS,
            'invitableRoles' => TeamRole::assignable(),
            'channels' => fn (): array => $shell?->channels($activeChannel) ?? [],
            // The current team's members feed the DM entry points (the sidebar
            // people picker and the ⌘K "People" group); empty off the workspace.
            'teamMembers' => fn (): array => $shell?->teamMembers() ?? [],
            'channelSections' => fn (): array => $shell?->channelSections() ?? [],
            // The current team's custom emoji as a flat name->url map, so message
            // bodies and reaction pills can resolve `:name:` shortcodes to images.
            // A revoked emoji is simply absent, so its token falls back to text.
            'customEmojis' => fn (): array => $shell?->customEmojis() ?? [],
            // The viewer's five most-used emoji in their current workspace,
            // feeding the hover bar's quick-react cluster and the picker's
            // "Frequently used" strip. Derived from reaction history per visit
            // (frequently-used is slow-moving, so it is eventually consistent —
            // a live reaction doesn't re-rank until the next Inertia visit).
            'frequentEmojis' => fn (): array => FrequentEmoji::forUser($user),
            // The current team's mentionable user groups, feeding the composer's
            // `@` menu and the anti-spoof check that decides whether a
            // `group:<id>` token renders as a pill or as plain text. A deleted
            // group is simply absent, so its token falls back to text.
            'userGroups' => fn (): array => $shell?->userGroups() ?? [],
            // The composer's slash-command autocomplete manifest, built from the
            // registry with copy already translated under the active locale.
            // Server-authoritative: a newly registered command appears in
            // autocomplete with no frontend change. Empty off the workspace,
            // where the composer isn't rendered.
            'slashCommands' => fn (): array => $shell?->slashCommands() ?? [],
            'collapsedChannelSections' => fn () => $user->collapsed_channel_sections ?? [],
            'hasUnreadThreads' => fn (): bool => $shell?->hasUnreadThreads() ?? false,
            // The Threads panel's inbox and its "Unread" tally, present only while
            // the dock actually has that destination pinned.
            ...$shell?->threadsPanelProps($pinned, ThreadInboxFilter::fromQuery($request->query('filter'))) ?? [],
            // The Search panel's echoed criteria and matches, on the same terms.
            ...$shell?->searchPanelProps($pinned, MessageSearchPanel::criteriaFromRequest($request)) ?? [],
            'pendingInvitations' => Inertia::optional(fn (): array => $user ? PendingInvitations::forUser($user) : []),
            // The viewer's still-pending reminders in this team, soonest first,
            // feeding the "Reminders" list and its sidebar count.
            'reminders' => fn (): array => $shell?->reminders(MessageReminderStatus::Pending) ?? [],
            // Reminders that have come due and await acknowledgement, driving the
            // in-app nudges; reloaded live when a MessageReminderDue signal lands.
            'firedReminders' => fn (): array => $shell?->reminders(MessageReminderStatus::Fired) ?? [],
        ];
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
