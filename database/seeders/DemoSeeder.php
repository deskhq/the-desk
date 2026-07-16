<?php

namespace Database\Seeders;

use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Channels\SyncMentions;
use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\ChannelSection;
use App\Models\CustomEmoji;
use App\Models\Message;
use App\Models\MessageLinkPreview;
use App\Models\MessagePin;
use App\Models\MessageReaction;
use App\Models\MessageReminder;
use App\Models\ScheduledMessage;
use App\Models\Team;
use App\Models\User;
use App\Support\Gravatar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stands up a single, polished, believable workspace for a *public* demo
 * deployment: one fictional company (Northwind Labs) a stranger can log into
 * and click around. Unlike {@see WorkspaceSeeder} (multiple teams, edge-state
 * coverage, `test@example.com`), this seeder deliberately omits admin/edge
 * noise — no trashed team, unverified users, audit exports, security events,
 * SSO identities, or data-export records — and hand-authors a narrative in a
 * few "hero" channels so the product tour feels alive.
 *
 * Structural rows (team, channels, memberships, DMs) go through the same domain
 * actions {@see WorkspaceSeeder} uses so the event-firing paths run: in
 * particular `MembershipObserver` creates the team's protected #general channel.
 * Message history is written through the factory with UUIDv7 ids derived from
 * each row's back-dated `created_at`, so the `id DESC` timeline reads
 * chronologically (the same flake guard {@see WorkspaceSeeder} relies on).
 *
 * The seeder is deterministic (fixed faker seed) and idempotent: each run wipes
 * any prior demo team + accounts (and their on-disk assets) and rebuilds from
 * scratch, so re-running it — the later reset job — never drifts or duplicates.
 */
class DemoSeeder extends Seeder
{
    /**
     * The documented credentials a visitor logs into the public demo with,
     * deliberately distinct from {@see WorkspaceSeeder::DEMO_EMAIL} so the two
     * seeders never collide.
     */
    public const string DEMO_EMAIL = 'demo@northwind.test';

    public const string DEMO_PASSWORD = 'demo-password';

    /**
     * The one company the demo stands up.
     */
    public const string TEAM_NAME = 'Northwind Labs';

    public const string TEAM_SLUG = 'northwind-labs';

    /**
     * Every demo persona shares this email domain, so a wipe can find them all
     * and none collides with the dev fixture's `@example.com` accounts.
     */
    private const string EMAIL_DOMAIN = 'northwind.test';

    /**
     * A fixed faker seed so factory filler (bodies, counts) is reproducible.
     */
    private const int FAKER_SEED = 20260716;

    /**
     * The canonical cast keys, in seeding order — the single source of truth for
     * which accounts the demo owns, shared by seeding and wiping.
     *
     * @var non-empty-list<string>
     */
    private const array PERSONA_KEYS = ['owner', 'leo', 'priya', 'sam', 'jonas', 'amara', 'chloe'];

    public function __construct(
        private readonly CreateTeam $createTeam,
        private readonly CreateChannel $createChannel,
        private readonly JoinChannel $joinChannel,
        private readonly OpenDirectMessage $openDirectMessage,
        private readonly SyncMentions $syncMentions,
    ) {}

    /**
     * Seed the demo workspace from scratch.
     */
    public function run(): void
    {
        fake()->seed(self::FAKER_SEED);

        $this->wipeExistingDemo();

        $cast = $this->seedCast();
        $owner = $cast['owner'];

        $team = $this->createTeam->handle($owner, self::TEAM_NAME);
        $this->addMembers($team, $cast);

        $channels = $this->seedChannels($team, $cast);
        $this->sectionSidebar($owner, $team, $channels);

        $this->seedProductLaunch($channels['product-launch'], $cast);
        $this->seedDesignReview($channels['design-review'], $cast);
        $this->seedFiller($channels, $cast);
        $this->seedAnnouncements($channels['announcements'], $cast);
        $this->seedDirectMessages($team, $cast);
        $this->seedCustomEmoji($team, $channels['general'], $cast);
        $this->seedScheduledMessages($channels, $owner);
        $this->seedReminders($channels['product-launch'], $owner);
        $this->seedUnreadMention($channels['engineering'], $cast);

        $owner->switchTeam($team);

        $this->printSummary($team);
    }

    /**
     * Remove any prior demo team and personas — plus their on-disk custom-emoji
     * and attachment assets, which no database cascade reaches — so a re-run
     * rebuilds a single pristine workspace rather than stacking duplicates. The
     * fixed persona emails would otherwise collide on the unique index.
     */
    private function wipeExistingDemo(): void
    {
        Team::withTrashed()->where('slug', self::TEAM_SLUG)->get()->each(function (Team $team): void {
            Storage::disk(CustomEmoji::DISK)->deleteDirectory("custom-emoji/{$team->id}");

            $team->channels()->pluck('id')->each(function (string $channelId): void {
                Storage::disk((string) config('attachments.disk'))->deleteDirectory("attachments/{$channelId}");
            });

            // Force-delete so the database cascade drops channels, messages and
            // every dependent row rather than merely soft-deleting the team.
            $team->forceDelete();
        });

        User::whereIn('email', $this->personaEmails())->get()->each(function (User $user): void {
            // Each persona's factory-spawned personal team is removed alongside
            // them, so the wipe leaves nothing behind.
            $this->deletePersonalTeams($user);
            $user->forceDelete();
        });
    }

    /**
     * The exact set of demo persona emails, the single source of truth for both
     * seeding and wiping. Scoping the wipe to this explicit list (rather than a
     * broad domain match) guarantees it can only ever touch the demo accounts.
     *
     * @return non-empty-list<string>
     */
    private function personaEmails(): array
    {
        return array_map(
            $this->personaEmail(...),
            self::PERSONA_KEYS,
        );
    }

    /**
     * Resolve a persona's email from its cast key: the owner logs in with the
     * documented demo address, everyone else uses `{key}@{domain}`.
     */
    private function personaEmail(string $key): string
    {
        return $key === 'owner' ? self::DEMO_EMAIL : $key.'@'.self::EMAIL_DOMAIN;
    }

    /**
     * Create the seven demo personas (a named Owner plus a supporting cast with
     * varied roles), each with a deterministic identicon avatar. Every persona's
     * factory-created personal team is dropped immediately so the demo settles
     * on exactly one team once the company is built.
     *
     * @return array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}
     */
    private function seedCast(): array
    {
        $cast = [
            'owner' => $this->seedPersona('Maya Okonkwo', $this->personaEmail('owner')),
            'leo' => $this->seedPersona('Leo Tanaka', $this->personaEmail('leo')),
            'priya' => $this->seedPersona('Priya Nair', $this->personaEmail('priya')),
            'sam' => $this->seedPersona('Sam Rivera', $this->personaEmail('sam')),
            'jonas' => $this->seedPersona('Jonas Berg', $this->personaEmail('jonas')),
            'amara' => $this->seedPersona('Amara Diallo', $this->personaEmail('amara')),
            'chloe' => $this->seedPersona('Chloe Dubois', $this->personaEmail('chloe')),
        ];

        foreach ($cast as $persona) {
            $this->deletePersonalTeams($persona);
        }

        return $cast;
    }

    /**
     * Create one demo persona with the shared demo password and a generated
     * identicon avatar (a distinct, deterministic picture per email, so every
     * avatar surface renders without an initials fallback).
     */
    private function seedPersona(string $name, string $email): User
    {
        return User::factory()->createOne([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::DEMO_PASSWORD),
            'avatar_url' => Gravatar::url($email, 'identicon'),
        ]);
    }

    /**
     * Force-delete every personal team a persona owns, so the auto-created
     * "{name}'s Team" never shows up alongside the company in the demo.
     */
    private function deletePersonalTeams(User $user): void
    {
        Team::where('is_personal', true)
            ->whereHas('members', fn ($query) => $query->whereKey($user->id))
            ->get()
            ->each->forceDelete();
    }

    /**
     * Attach the supporting cast to the company with a realistic spread of roles
     * (two admins, four members) and land each of them on the company as their
     * current team, so the workspace reads as an established org.
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     */
    private function addMembers(Team $team, array $cast): void
    {
        $roles = [
            'leo' => TeamRole::Admin,
            'amara' => TeamRole::Admin,
            'priya' => TeamRole::Member,
            'sam' => TeamRole::Member,
            'jonas' => TeamRole::Member,
            'chloe' => TeamRole::Member,
        ];

        foreach ($roles as $key => $role) {
            $team->members()->attach($cast[$key], ['role' => $role->value]);
            $cast[$key]->switchTeam($team);
        }
    }

    /**
     * Build the company's channel structure: the protected #general, a spread of
     * public channels across two topics, and two private channels the owner
     * belongs to. Every persona joins each public channel so the channels read as
     * busy, and the owner (whom the visitor logs in as) is a member of all of
     * them, so the whole sidebar is reachable.
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     * @return array<string, Channel>
     */
    private function seedChannels(Team $team, array $cast): array
    {
        $owner = $cast['owner'];
        $everyone = array_values($cast);

        $channels = [
            'general' => $this->generalChannel($team),
            'announcements' => $this->createChannel->handle($team, 'announcements', ChannelVisibility::Public, $owner, 'Company-wide news'),
            'product-launch' => $this->createChannel->handle($team, 'product-launch', ChannelVisibility::Public, $owner, 'Countdown to launch 🚀'),
            'marketing' => $this->createChannel->handle($team, 'marketing', ChannelVisibility::Public, $cast['amara'], 'Campaigns & copy'),
            'engineering' => $this->createChannel->handle($team, 'engineering', ChannelVisibility::Public, $cast['leo'], 'Ship it'),
            'design-review' => $this->createChannel->handle($team, 'design-review', ChannelVisibility::Public, $cast['priya'], 'Critiques & mockups'),
            'random' => $this->createChannel->handle($team, 'random', ChannelVisibility::Public, $cast['sam']),
            'watercooler' => $this->createChannel->handle($team, 'watercooler', ChannelVisibility::Public, $cast['chloe'], 'Off-topic & fun'),
        ];

        // Everyone joins every public channel (the creator is already a member),
        // so the demo never shows a one-person room.
        foreach ($channels as $channel) {
            foreach ($everyone as $persona) {
                $this->joinChannel->handle($channel, $persona, announce: false);
            }
        }

        // Two private channels the owner is part of, so a visitor can see how
        // private rooms render without a wall of invite-only noise.
        $channels['leadership'] = $this->seedPrivateChannel($team, 'leadership', 'Founders & leads', $owner, [$cast['leo'], $cast['amara']]);
        $channels['design-crit'] = $this->seedPrivateChannel($team, 'design-crit', 'Closed design critiques', $cast['priya'], [$owner, $cast['sam']]);

        return $channels;
    }

    /**
     * Create a private channel and add its members through the join action, so
     * membership rows and the owning team stay consistent with the real flow.
     *
     * @param  non-empty-list<User>  $members
     */
    private function seedPrivateChannel(Team $team, string $name, string $topic, User $creator, array $members): Channel
    {
        $channel = $this->createChannel->handle($team, $name, ChannelVisibility::Private, $creator, $topic);

        foreach ($members as $member) {
            $this->joinChannel->handle($channel, $member, announce: false);
        }

        return $channel;
    }

    /**
     * Group the owner's sidebar into two named sections ("Company" and "Team"),
     * mirroring how a real user tidies a busy workspace. Sections are per-user,
     * so only the owner's `channel_members` rows carry the section pointer.
     *
     * @param  array<string, Channel>  $channels
     */
    private function sectionSidebar(User $owner, Team $team, array $channels): void
    {
        $company = ChannelSection::factory()->for($owner)->for($team)->position(0)->create(['name' => 'Company']);
        $teamSection = ChannelSection::factory()->for($owner)->for($team)->position(1)->create(['name' => 'Team']);

        $layout = [
            'announcements' => $company,
            'product-launch' => $company,
            'marketing' => $company,
            'engineering' => $teamSection,
            'design-review' => $teamSection,
            'random' => $teamSection,
            'watercooler' => $teamSection,
        ];

        foreach ($layout as $key => $section) {
            $owner->channels()->updateExistingPivot($channels[$key]->id, ['section_id' => $section->id]);
        }
    }

    /**
     * The flagship hero channel: a hand-authored product-launch narrative with a
     * live thread, reactions, pins, and a roadmap PDF attachment, so the busiest
     * surface of the tour reads like a real launch week.
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     */
    private function seedProductLaunch(Channel $channel, array $cast): void
    {
        $start = Carbon::now()->subDays(2);

        $messages = $this->postScript($channel, $cast, [
            ['owner', 'Launch week is officially here. Let\'s make it a good one, team. 🚀'],
            ['leo', 'Staging is green across the board — final build is cutting now.'],
            ['amara', 'Press embargo lifts Thursday 9am. Blog post and email are queued.'],
            ['priya', 'Updated the hero illustration overnight, it\'s in design-review for a last look.'],
            ['sam', 'QA sign-off done. Two cosmetic tickets left, both non-blocking.'],
            ['owner', 'Fantastic. I\'ve pinned the roadmap so everyone has the same picture.'],
            ['jonas', 'Analytics dashboards are wired up — we\'ll see signups land in real time.'],
            ['chloe', 'Support macros and the FAQ are ready for the flood. Bring it on.'],
            ['owner', 'This is the calmest launch prep we\'ve ever had. Proud of this crew.'],
        ], $start);

        // A live thread hanging off the roadmap message.
        $this->seedThread($channel, $cast, $messages[5], [
            ['leo', 'One tweak — can we push the mobile milestone a week? Depends on the API work.'],
            ['owner', 'Works for me. Update the dates and I\'ll re-share.'],
            ['leo', 'Done. Roadmap reflects the new mobile date now.'],
            ['priya', 'Nice, that gives design more runway too. 🙌'],
        ], $start->copy()->addMinutes(30));

        $this->seedReactions([$messages[0], $messages[5], $messages[8]], $cast);
        $this->seedPins($channel, [$messages[5], $messages[0]], $cast['owner']);
        $this->attachDocument($channel, $cast['owner'], $messages[5], 'roadmap.pdf');
    }

    /**
     * The design hero channel: a short critique narrative carrying an image
     * attachment (so the inline image and lightbox previews render) plus a
     * couple of reactions.
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     */
    private function seedDesignReview(Channel $channel, array $cast): void
    {
        $messages = $this->postScript($channel, $cast, [
            ['priya', 'Fresh pass on the launch hero — flattened the gradient and bumped the contrast.'],
            ['priya', 'Mockup attached. Curious what everyone thinks of the new lockup.'],
            ['owner', 'Big improvement. The headline breathes a lot more now.'],
            ['sam', 'Agreed. Holds up on every breakpoint I tested.'],
            ['chloe', 'Love it. The identicon avatars look great against that background too.'],
        ], Carbon::now()->subDay());

        $this->attachImage($channel, $cast['priya'], $messages[1], 'product-mockup.png');
        $this->seedReactions([$messages[0], $messages[2]], $cast);
    }

    /**
     * Backfill the secondary channels with light factory filler so infinite
     * scroll and the sidebar's unread counts have volume behind them, without
     * hand-authoring every room.
     *
     * @param  array<string, Channel>  $channels
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     */
    private function seedFiller(array $channels, array $cast): void
    {
        $everyone = array_values($cast);

        $volumes = [
            'general' => 45,
            'engineering' => 60,
            'marketing' => 35,
            'random' => 40,
            'watercooler' => 30,
            'leadership' => 12,
        ];

        foreach ($volumes as $key => $count) {
            $this->fillChannel($channels[$key], $everyone, $count);
        }
    }

    /**
     * Seed #announcements with a couple of curated posts, one of which carries a
     * URL so its link-preview unfurl renders on the timeline.
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     */
    private function seedAnnouncements(Channel $channel, array $cast): void
    {
        $messages = $this->postScript($channel, $cast, [
            ['owner', 'Welcome to Northwind Labs. This channel is for company-wide news.'],
            ['amara', 'Our launch story is live on the blog — give it a read and a share: https://northwind.test/blog/launch-week'],
        ], Carbon::now()->subDays(3));

        MessageLinkPreview::factory()->ready()->for($messages[1])->create([
            'url' => 'https://northwind.test/blog/launch-week',
            'title' => 'Launch week at Northwind Labs',
            'description' => 'How our small team shipped a calmer, faster workspace — and what comes next.',
            'site_name' => 'Northwind Labs',
        ]);
    }

    /**
     * Open a handful of owner-centric direct messages so the sidebar's "Direct
     * messages" group is populated: an unread 1:1 that badges on open, a fully
     * read 1:1, a short exchange, and a self-DM the sidebar renders as "You".
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     */
    private function seedDirectMessages(Team $team, array $cast): void
    {
        $owner = $cast['owner'];

        // Unread 1:1 — the other person spoke last, so it badges on open.
        $withLeo = $this->openDirectMessage->handle($team, $owner, $cast['leo']);
        $this->seedConversation($withLeo, [
            [$owner, 'Great work holding the release train steady this week.'],
            [$cast['leo'], 'Team effort. The new deploy checks caught two regressions early.'],
            [$owner, 'Let\'s write that up so the whole team benefits.'],
            [$cast['leo'], 'On it — I\'ll draft something for #engineering tomorrow.'],
        ]);

        // Fully caught-up 1:1 — read pointer at the latest message, no badge.
        $withPriya = $this->openDirectMessage->handle($team, $owner, $cast['priya']);
        $this->seedConversation($withPriya, [
            [$cast['priya'], 'Sent over the final hero export. Let me know if you need other sizes.'],
            [$owner, 'Perfect, thank you! Nothing else for now.'],
        ]);
        $latest = $withPriya->messages()->latest()->first();
        $owner->channels()->updateExistingPivot($withPriya->id, ['last_read_message_id' => $latest?->id]);

        // A short exchange with an admin.
        $withAmara = $this->openDirectMessage->handle($team, $owner, $cast['amara']);
        $this->seedConversation($withAmara, [
            [$cast['amara'], 'Embargo assets are all staged and ready to publish Thursday.'],
            [$owner, 'You\'re a machine. 🙌'],
        ]);

        // A self-DM the sidebar renders as "You" — notes to self.
        $notes = $this->openDirectMessage->handle($team, $owner, $owner);
        $this->seedConversation($notes, [
            [$owner, 'Remember to thank the team individually after launch. 💛'],
        ]);
    }

    /**
     * Seed a small custom-emoji registry (a shared placeholder image backs each
     * row, as a real upload would write per-emoji art) plus one message body and
     * one reaction that use a shortcode, so the picker's Custom strip and inline
     * `:name:` rendering both have data on load.
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     */
    private function seedCustomEmoji(Team $team, Channel $channel, array $cast): void
    {
        $png = (string) file_get_contents($this->fixture('product-mockup.png'));
        $names = ['party-parrot', 'shipit', 'northwind', 'coffee'];

        foreach ($names as $index => $name) {
            $path = "custom-emoji/{$team->id}/{$name}.png";
            Storage::disk(CustomEmoji::DISK)->put($path, $png);

            $team->customEmojis()->create([
                'created_by' => array_values($cast)[$index]->id,
                'name' => $name,
                'path' => $path,
            ]);
        }

        // A message body and a reaction that exercise the `:name:` render paths.
        $createdAt = Carbon::now()->subHours(4);
        $this->postMessage($channel, $cast['leo'], 'Deploy is out :shipit: nice work everyone :party-parrot:', $createdAt);

        $reactionTarget = $this->postMessage($channel, $cast['owner'], 'That went smoothly.', $createdAt->copy()->addMinute());
        MessageReaction::factory()->for($reactionTarget)->for($cast['sam'])->emoji(':northwind:')->create();
    }

    /**
     * Queue a couple of scheduled messages owned by the demo account so the
     * "scheduled" surface has pending rows a visitor can inspect.
     *
     * @param  array<string, Channel>  $channels
     */
    private function seedScheduledMessages(array $channels, User $owner): void
    {
        ScheduledMessage::factory()->for($channels['general'])->for($owner)->create([
            'body' => 'Reminder: launch retro is Friday at 3pm. Come with one win and one lesson.',
            'send_at' => Carbon::now()->addDays(2)->setTime(9, 0),
        ]);

        ScheduledMessage::factory()->for($channels['announcements'])->for($owner)->create([
            'body' => 'Thank you all for an incredible launch week. Numbers to follow. 💛',
            'send_at' => Carbon::now()->addDay()->setTime(17, 0),
        ]);
    }

    /**
     * Set a couple of message reminders owned by the demo account, so the
     * reminders surface renders pending rows.
     */
    private function seedReminders(Channel $channel, User $owner): void
    {
        $messages = $channel->messages()->latest('id')->take(2)->get();

        foreach ($messages as $index => $message) {
            MessageReminder::factory()->create([
                'user_id' => $owner->id,
                'message_id' => $message->id,
                'remind_at' => Carbon::now()->addHours(3 + $index),
            ]);
        }
    }

    /**
     * Post a message that mentions the demo account and leave it unread, so the
     * owner lands on the demo with a real mention badge waiting.
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     */
    private function seedUnreadMention(Channel $channel, array $cast): void
    {
        $owner = $cast['owner'];

        // Mark everything currently in the channel as read first, so only the
        // mention that follows — the newest row — is left unread.
        $lastRead = $channel->messages()->latest('id')->first();
        $owner->channels()->updateExistingPivot($channel->id, ['last_read_message_id' => $lastRead?->id]);

        $mention = $this->postMessage(
            $channel,
            $cast['leo'],
            "Hey @[{$owner->name}]({$owner->id}), can you review the deploy notes before Thursday?",
            Carbon::now(),
        );

        $this->syncMentions->handle($channel, $mention);
    }

    /**
     * Post a hand-authored script to a channel, rotating authors by cast key and
     * back-dating each line a minute after the last so the conversation reads in
     * order.
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     * @param  non-empty-list<array{0: string, 1: string}>  $script
     * @return list<Message>
     */
    private function postScript(Channel $channel, array $cast, array $script, Carbon $start): array
    {
        $messages = [];

        foreach ($script as $index => [$authorKey, $body]) {
            $messages[] = $this->postMessage($channel, $cast[$authorKey], $body, $start->copy()->addMinutes($index));
        }

        return $messages;
    }

    /**
     * Post a run of replies into a message's thread and bring the root's
     * denormalised `reply_count` / `last_reply_at` into line, as the real send
     * path does, so the thread panel and threads inbox both read consistently.
     *
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     * @param  non-empty-list<array{0: string, 1: string}>  $replies
     */
    private function seedThread(Channel $channel, array $cast, Message $root, array $replies, Carbon $start): void
    {
        $last = $start;

        foreach ($replies as $index => [$authorKey, $body]) {
            $createdAt = $start->copy()->addMinutes($index);
            $last = $createdAt;

            Message::factory()->for($channel)->for($cast[$authorKey])->inThread($root)->create([
                'id' => (string) Str::uuid7($createdAt),
                'body' => $body,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $root->forceFill([
            'reply_count' => count($replies),
            'last_reply_at' => $last,
        ])->save();
    }

    /**
     * Post an ad-hoc conversation into a channel (used for DMs), rotating the
     * given [author, body] pairs and back-dating them in order.
     *
     * @param  non-empty-list<array{0: User, 1: string}>  $lines
     */
    private function seedConversation(Channel $channel, array $lines): void
    {
        $start = Carbon::now()->subHours(6);

        foreach ($lines as $index => [$author, $body]) {
            $this->postMessage($channel, $author, $body, $start->copy()->addMinutes($index));
        }
    }

    /**
     * Fill a channel with a run of factory messages, rotating through the given
     * authors and back-dating them so it reads as an ongoing conversation.
     *
     * @param  non-empty-list<User>  $authors
     */
    private function fillChannel(Channel $channel, array $authors, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $author = $authors[$index % count($authors)];
            $createdAt = Carbon::now()->subMinutes($count - $index);

            Message::factory()->for($channel)->for($author)->create([
                'id' => (string) Str::uuid7($createdAt),
                'body' => fake()->realText(fake()->numberBetween(40, 160)),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    /**
     * Create a single back-dated message whose UUIDv7 id encodes its own
     * `created_at`, so the `id DESC` timeline stays chronological.
     */
    private function postMessage(Channel $channel, User $author, string $body, Carbon $createdAt): Message
    {
        return Message::factory()->for($channel)->for($author)->create([
            'id' => (string) Str::uuid7($createdAt),
            'body' => $body,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * Attach a spread of emoji reactions across the given messages, including
     * reactions by the owner so its own pills highlight and a multi-reactor
     * message that reads "You and N others".
     *
     * @param  non-empty-list<Message>  $messages
     * @param  array{owner: User, leo: User, priya: User, sam: User, jonas: User, amara: User, chloe: User}  $cast
     */
    private function seedReactions(array $messages, array $cast): void
    {
        $popular = $messages[0];

        foreach ([$cast['owner'], $cast['leo'], $cast['priya']] as $reactor) {
            MessageReaction::factory()->for($popular)->for($reactor)->emoji('🎉')->create();
        }

        MessageReaction::factory()->for($popular)->for($cast['owner'])->emoji('🚀')->create();

        foreach (array_slice($messages, 1) as $message) {
            MessageReaction::factory()->for($message)->for($cast['amara'])->emoji('❤️')->create();
        }
    }

    /**
     * Pin the given messages to a channel so the masthead pins button, its count
     * badge, and the pins popover all have data on load.
     *
     * @param  non-empty-list<Message>  $messages
     */
    private function seedPins(Channel $channel, array $messages, User $pinner): void
    {
        foreach ($messages as $message) {
            MessagePin::factory()->for($message)->for($channel)->for($pinner, 'pinnedBy')->create();
        }
    }

    /**
     * Copy a committed image fixture onto the attachments disk and claim it to a
     * message, thumbnail and all, so the inline image and lightbox previews
     * render from real bytes on disk.
     */
    private function attachImage(Channel $channel, User $uploader, Message $message, string $fixture): void
    {
        $bytes = (string) file_get_contents($this->fixture($fixture));
        $disk = (string) config('attachments.disk');
        $path = "attachments/{$channel->id}/{$fixture}";
        $thumbPath = "attachments/{$channel->id}/thumbnails/{$fixture}";

        Storage::disk($disk)->put($path, $bytes);
        Storage::disk($disk)->put($thumbPath, $bytes);

        [$width, $height] = getimagesizefromstring($bytes) ?: [16, 16];

        Attachment::factory()->attachedTo($message)->create([
            'user_id' => $uploader->id,
            'disk' => $disk,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'original_filename' => $fixture,
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'width' => $width,
            'height' => $height,
        ]);
    }

    /**
     * Copy a committed document fixture onto the attachments disk and claim it to
     * a message, so the file-card preview renders from real bytes on disk.
     */
    private function attachDocument(Channel $channel, User $uploader, Message $message, string $fixture): void
    {
        $bytes = (string) file_get_contents($this->fixture($fixture));
        $disk = (string) config('attachments.disk');
        $path = "attachments/{$channel->id}/{$fixture}";

        Storage::disk($disk)->put($path, $bytes);

        Attachment::factory()->document()->attachedTo($message)->create([
            'user_id' => $uploader->id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $fixture,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'width' => null,
            'height' => null,
        ]);
    }

    /**
     * Resolve a committed demo fixture's absolute path.
     */
    private function fixture(string $name): string
    {
        return __DIR__.'/demo/'.$name;
    }

    /**
     * Resolve a team's protected #general channel.
     */
    private function generalChannel(Team $team): Channel
    {
        return $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    }

    /**
     * Print the documented demo credentials and where the visitor lands.
     */
    private function printSummary(Team $team): void
    {
        $this->command->info('Demo workspace seeded.');
        $this->command->info('  Login: '.self::DEMO_EMAIL.' / '.self::DEMO_PASSWORD);
        $this->command->info('  Lands in "'.$team->name.'" (Owner).');
    }
}
