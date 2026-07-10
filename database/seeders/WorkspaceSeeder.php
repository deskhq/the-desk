<?php

namespace Database\Seeders;

use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\SyncMentions;
use App\Actions\Teams\CreateTeam;
use App\Enums\AuditAction;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Database\Seeder;

/**
 * Stands up a rich, log-in-ready local workspace that exercises every model and
 * factory state, so a developer can click through the whole MVP after a single
 * `migrate:fresh --seed`.
 *
 * Teams and memberships are seeded exclusively through the event-firing paths
 * (the domain actions and `members()->attach()`), never with model events
 * disabled: `MembershipObserver` is what creates each team's protected #general
 * channel and keeps channel membership in step with team membership.
 */
class WorkspaceSeeder extends Seeder
{
    /**
     * The fixed credentials of the demo account devs log in with.
     */
    public const string DEMO_EMAIL = 'test@example.com';

    public function __construct(
        private readonly CreateTeam $createTeam,
        private readonly CreateChannel $createChannel,
        private readonly JoinChannel $joinChannel,
        private readonly SyncMentions $syncMentions,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * Seed the local workspace dataset.
     */
    public function run(): void
    {
        $cast = $this->seedUsers();
        $demo = $cast['demo'];

        $acme = $this->seedOwnedTeam($cast);
        $this->seedAdminTeam($demo, $cast['globexOwner'], $cast['member2']);
        $this->seedMemberTeam($demo, $cast['initechOwner']);
        $this->seedTrashedTeam($cast['ghostOwner']);

        $demo->switchTeam($acme);

        $this->printSummary($acme);
    }

    /**
     * Create the demo account plus a supporting cast, covering the unverified and
     * two-factor user states. Every user also gets a personal team (Owner) via
     * `UserFactory::configure()`, which exercises `TeamFactory::personal()`.
     *
     * @return array{demo: User, admin: User, member1: User, member2: User, twoFactor: User, globexOwner: User, initechOwner: User, ghostOwner: User}
     */
    private function seedUsers(): array
    {
        $demo = User::factory()->create([
            'name' => 'Demo User',
            'email' => self::DEMO_EMAIL,
        ]);

        User::factory()->unverified()->create([
            'name' => 'Uma Unverified',
            'email' => 'unverified@example.com',
        ]);

        $twoFactor = User::factory()->withTwoFactor()->create([
            'name' => 'Tina Twofactor',
            'email' => 'twofactor@example.com',
        ]);

        return [
            'demo' => $demo,
            'admin' => User::factory()->create(['name' => 'Alice Admin']),
            'member1' => User::factory()->create(['name' => 'Bob Builder']),
            'member2' => User::factory()->create(['name' => 'Carol Danvers']),
            'twoFactor' => $twoFactor,
            'globexOwner' => User::factory()->create(['name' => 'Grace Globex']),
            'initechOwner' => User::factory()->create(['name' => 'Ivan Initech']),
            'ghostOwner' => User::factory()->create(['name' => 'Nate Nolonger']),
        ];
    }

    /**
     * The primary busy team the demo user owns: extra public channels (with and
     * without topics), private channels the demo is and isn't in, an archived
     * channel, a backfilled #general with edited/deleted/mention messages and
     * varied unread state, plus invitations covering every role and state.
     *
     * @param  array{demo: User, admin: User, member1: User, member2: User, twoFactor: User, globexOwner: User, initechOwner: User, ghostOwner: User}  $cast
     */
    private function seedOwnedTeam(array $cast): Team
    {
        ['demo' => $demo, 'admin' => $admin, 'member1' => $member1, 'member2' => $member2, 'twoFactor' => $twoFactor] = $cast;

        $acme = $this->createTeam->handle($demo, 'Acme Corp');

        $acme->members()->attach($admin, ['role' => TeamRole::Admin->value]);
        $acme->members()->attach($member1, ['role' => TeamRole::Member->value]);
        $acme->members()->attach($member2, ['role' => TeamRole::Member->value]);
        $acme->members()->attach($twoFactor, ['role' => TeamRole::Member->value]);

        $general = $this->generalChannel($acme);
        $members = [$demo, $admin, $member1, $member2, $twoFactor];

        // A busy channel: enough backfill to make infinite scroll meaningful.
        $generalMessages = $this->seedMessages($general, $members, 60);
        $this->seedEditedMessage($general, $admin);
        $this->seedDeletedMessage($general, $member1);
        $this->seedMentionMessage($general, $member2, $demo);
        $this->seedReactions($generalMessages, [$demo, $admin, $member1]);

        // Extra public channels — one with a topic, one without.
        $announcements = $this->createChannel->handle($acme, 'announcements', ChannelVisibility::Public, $demo, 'Company-wide news');
        $random = $this->createChannel->handle($acme, 'random', ChannelVisibility::Public, $member1);
        $this->joinChannel->handle($announcements, $admin);
        $this->joinChannel->handle($random, $demo);
        $this->seedMessages($announcements, [$demo, $admin], 8);
        $this->seedMessages($random, [$member1, $member2], 5);

        // Private channel the demo is a member of (with a topic).
        $leadership = Channel::factory()->for($acme)->private()->create([
            'name' => 'leadership',
            'slug' => 'leadership',
            'topic' => 'Founders only',
            'created_by' => $demo->id,
        ]);
        $leadership->members()->attach([$demo->id, $admin->id]);
        $this->seedMessages($leadership, [$demo, $admin], 6);

        // Private channel the demo is deliberately NOT a member of.
        $secret = Channel::factory()->for($acme)->private()->create([
            'name' => 'secret-project',
            'slug' => 'secret-project',
            'created_by' => $admin->id,
        ]);
        $secret->members()->attach([$admin->id, $member1->id]);

        // An archived channel the demo can still reach from the archive view.
        $archived = Channel::factory()->for($acme)->archived()->create([
            'name' => 'old-stuff',
            'slug' => 'old-stuff',
            'created_by' => $demo->id,
        ]);
        $archived->members()->attach([$demo->id, $admin->id]);

        $this->seedUnreadState($demo, $general, $announcements, $leadership);
        $this->seedInvitations($acme, $demo);
        $this->seedAuditLog($acme, $demo, $admin, $member1, $announcements, $archived, $secret);

        return $acme;
    }

    /**
     * Populate the workspace's append-only audit log with a representative set
     * of admin and moderation actions across two actors, so the admin-only
     * audit viewer (and its action/actor filters) has data to show.
     */
    private function seedAuditLog(
        Team $team,
        User $owner,
        User $admin,
        User $member,
        Channel $announcements,
        Channel $archived,
        Channel $secret,
    ): void {
        $this->auditRecorder->record($team, $owner, AuditAction::TeamRenamed, $team, [
            'old_name' => 'Acme',
            'new_name' => $team->name,
        ]);

        $this->auditRecorder->record($team, $owner, AuditAction::MemberRoleChanged, $admin, [
            'member_name' => $admin->name,
            'old_role' => TeamRole::Member->label(),
            'new_role' => TeamRole::Admin->label(),
        ]);

        $this->auditRecorder->record($team, $owner, AuditAction::ChannelCreated, $announcements, [
            'channel_name' => $announcements->name,
        ]);

        $this->auditRecorder->record($team, $admin, AuditAction::ChannelMemberAdded, $secret, [
            'channel_name' => $secret->name,
            'member_name' => $member->name,
        ]);

        $this->auditRecorder->record($team, $admin, AuditAction::MessageDeleted, null, [
            'channel_name' => Channel::GENERAL_SLUG,
            'author_name' => $member->name,
        ]);

        $this->auditRecorder->record($team, $owner, AuditAction::ChannelArchived, $archived, [
            'channel_name' => $archived->name,
        ]);
    }

    /**
     * A team owned by someone else where the demo user is an Admin, so the
     * admin-gated UI (invitations, channel management) can be exercised.
     */
    private function seedAdminTeam(User $demo, User $owner, User $member): void
    {
        $globex = $this->createTeam->handle($owner, 'Globex');

        $globex->members()->attach($demo, ['role' => TeamRole::Admin->value]);
        $globex->members()->attach($member, ['role' => TeamRole::Member->value]);

        $engineering = $this->createChannel->handle($globex, 'engineering', ChannelVisibility::Public, $owner, 'Ship it');
        $this->joinChannel->handle($engineering, $demo);
        $this->seedMessages($engineering, [$owner, $demo, $member], 12);
    }

    /**
     * A team owned by someone else where the demo user is a plain Member, so
     * permission-denied paths can be exercised.
     */
    private function seedMemberTeam(User $demo, User $owner): void
    {
        $initech = $this->createTeam->handle($owner, 'Initech');

        $initech->members()->attach($demo, ['role' => TeamRole::Member->value]);

        $this->seedMessages($this->generalChannel($initech), [$owner, $demo], 7);
    }

    /**
     * A soft-deleted team (via `TeamFactory::trashed()`) that still respects the
     * invariants: #general is created up front so the membership observer takes
     * its join branch (which never re-queries the now-trashed team) rather than
     * its create branch (which would resolve the soft-deleted team to null).
     */
    private function seedTrashedTeam(User $owner): void
    {
        $ghost = Team::factory()->trashed()->create(['name' => 'Umbrella Corp']);

        $this->createChannel->handle($ghost, Channel::GENERAL_SLUG, ChannelVisibility::Public, $owner);
        $ghost->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    }

    /**
     * Fan out pending, accepted and expired invitations across every role on a
     * team the demo user administers, exercising each `TeamInvitationFactory`
     * state including `expiresIn()`.
     */
    private function seedInvitations(Team $team, User $inviter): void
    {
        // Pending, no expiry.
        TeamInvitation::factory()->for($team)->create([
            'email' => 'pending-member@example.com',
            'role' => TeamRole::Member,
            'invited_by' => $inviter->id,
        ]);

        // Pending, expiring soon.
        TeamInvitation::factory()->for($team)->expiresIn(7)->create([
            'email' => 'pending-admin@example.com',
            'role' => TeamRole::Admin,
            'invited_by' => $inviter->id,
        ]);

        // Accepted.
        TeamInvitation::factory()->for($team)->accepted()->create([
            'email' => 'accepted-admin@example.com',
            'role' => TeamRole::Admin,
            'invited_by' => $inviter->id,
        ]);

        // Expired, covering the Owner role for full role coverage.
        TeamInvitation::factory()->for($team)->expired()->create([
            'email' => 'expired-owner@example.com',
            'role' => TeamRole::Owner,
            'invited_by' => $inviter->id,
        ]);
    }

    /**
     * Post a run of messages to a channel, rotating through the given authors and
     * back-dating them so the channel reads as an ongoing conversation.
     *
     * @param  non-empty-list<User>  $authors
     * @return list<Message>
     */
    private function seedMessages(Channel $channel, array $authors, int $count): array
    {
        $messages = [];

        for ($index = 0; $index < $count; $index++) {
            $author = $authors[$index % count($authors)];

            $messages[] = Message::factory()->for($channel)->for($author)->create([
                'created_at' => now()->subMinutes($count - $index),
            ]);
        }

        return $messages;
    }

    /**
     * Attach a spread of emoji reactions to a few messages so the reaction pills,
     * multi-reactor aggregation, and the "You and N others" tooltip all have data
     * on load — including reactions by the demo user so its own pills highlight.
     *
     * @param  list<Message>  $messages
     * @param  array{0: User, 1: User, 2: User}  $reactors
     */
    private function seedReactions(array $messages, array $reactors): void
    {
        if (count($messages) < 5) {
            return;
        }

        [$demo, $admin, $member] = $reactors;

        // A popular message: three reactors share one emoji, with a second emoji
        // alongside — the viewer reads "You and 2 others" on the first pill.
        $popular = $messages[count($messages) - 2];

        foreach ([$demo, $admin, $member] as $reactor) {
            MessageReaction::factory()->for($popular)->for($reactor)->emoji('👍')->create();
        }

        MessageReaction::factory()->for($popular)->for($demo)->emoji('🎉')->create();

        // A lightly-reacted message: a single emoji from one other member.
        MessageReaction::factory()->for($messages[count($messages) - 5])->for($admin)->emoji('❤️')->create();
    }

    /**
     * Seed a message that has been edited.
     */
    private function seedEditedMessage(Channel $channel, User $author): void
    {
        Message::factory()->for($channel)->for($author)->edited()->create([
            'body' => 'Fixed a typo in my previous message.',
        ]);
    }

    /**
     * Seed a soft-deleted message so tombstone rendering has data.
     */
    private function seedDeletedMessage(Channel $channel, User $author): void
    {
        Message::factory()->for($channel)->for($author)->create([
            'body' => 'This message was removed.',
        ])->delete();
    }

    /**
     * Seed a message carrying a real `@[Name](user-id)` token and reconcile its
     * mention rows through `SyncMentions`.
     */
    private function seedMentionMessage(Channel $channel, User $author, User $mentioned): void
    {
        $message = Message::factory()->for($channel)->for($author)->create([
            'body' => "Hey @[{$mentioned->name}]({$mentioned->id}), can you take a look?",
        ]);

        $this->syncMentions->handle($channel, $message);
    }

    /**
     * Vary the demo user's `last_read_message_id` across channels so some show an
     * unread badge and some are fully caught up.
     */
    private function seedUnreadState(
        User $demo,
        Channel $general,
        Channel $announcements,
        Channel $leadership,
    ): void {
        // #general: read up to an earlier message, leaving later ones unread.
        $readUpTo = $general->messages()->oldest()->skip(39)->first();
        $demo->channels()->updateExistingPivot($general->id, ['last_read_message_id' => $readUpTo?->id]);

        // Announcements: fully caught up.
        $latestAnnouncement = $announcements->messages()->latest()->first();
        $demo->channels()->updateExistingPivot($announcements->id, ['last_read_message_id' => $latestAnnouncement?->id]);

        // Leadership: never opened, so everything is unread.
        $demo->channels()->updateExistingPivot($leadership->id, ['last_read_message_id' => null]);
    }

    /**
     * Resolve a team's protected #general channel.
     */
    private function generalChannel(Team $team): Channel
    {
        return $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    }

    /**
     * Print where to start once seeding finishes.
     */
    private function printSummary(Team $primaryTeam): void
    {
        $this->command->info('Workspace seeded.');
        $this->command->info('  Demo login: '.self::DEMO_EMAIL.' / password');
        $this->command->info('  Lands in "'.$primaryTeam->name.'" (Owner) — also Admin of "Globex" and Member of "Initech".');
    }
}
