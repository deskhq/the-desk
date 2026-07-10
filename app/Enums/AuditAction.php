<?php

namespace App\Enums;

/**
 * The admin and moderation actions recorded in a workspace's audit log. The
 * value is stored in the activity log's `event` column and is the primary
 * filter dimension for the viewer.
 */
enum AuditAction: string
{
    case TeamRenamed = 'team_renamed';
    case MemberRoleChanged = 'member_role_changed';
    case MemberRemoved = 'member_removed';
    case OwnershipTransferred = 'ownership_transferred';
    case ChannelCreated = 'channel_created';
    case ChannelArchived = 'channel_archived';
    case ChannelMemberAdded = 'channel_member_added';
    case ChannelMemberRemoved = 'channel_member_removed';
    case MessageDeleted = 'message_deleted';

    /**
     * Get the short human-readable label used in the action filter and headers.
     */
    public function label(): string
    {
        return match ($this) {
            self::TeamRenamed => 'Workspace renamed',
            self::MemberRoleChanged => 'Member role changed',
            self::MemberRemoved => 'Member removed',
            self::OwnershipTransferred => 'Ownership transferred',
            self::ChannelCreated => 'Channel created',
            self::ChannelArchived => 'Channel archived',
            self::ChannelMemberAdded => 'Channel member added',
            self::ChannelMemberRemoved => 'Channel member removed',
            self::MessageDeleted => 'Message deleted',
        };
    }

    /**
     * Build the full human sentence describing the action, from the entry's
     * recorded context. The acting user is shown separately, so this reads as
     * the predicate (e.g. "Removed Dana from #general").
     *
     * @param  array<string, mixed>  $context
     */
    public function describe(array $context): string
    {
        return match ($this) {
            self::TeamRenamed => sprintf('Renamed the workspace from “%s” to “%s”', $this->text($context, 'old_name'), $this->text($context, 'new_name')),
            self::MemberRoleChanged => sprintf('Changed %s’s role from %s to %s', $this->text($context, 'member_name'), $this->text($context, 'old_role'), $this->text($context, 'new_role')),
            self::MemberRemoved => sprintf('Removed %s from the workspace', $this->text($context, 'member_name')),
            self::OwnershipTransferred => sprintf('Transferred ownership to %s', $this->text($context, 'new_owner_name')),
            self::ChannelCreated => sprintf('Created #%s', $this->text($context, 'channel_name')),
            self::ChannelArchived => sprintf('Archived #%s', $this->text($context, 'channel_name')),
            self::ChannelMemberAdded => sprintf('Added %s to #%s', $this->text($context, 'member_name'), $this->text($context, 'channel_name')),
            self::ChannelMemberRemoved => sprintf('Removed %s from #%s', $this->text($context, 'member_name'), $this->text($context, 'channel_name')),
            self::MessageDeleted => sprintf('Deleted a message from %s in #%s', $this->text($context, 'author_name'), $this->text($context, 'channel_name')),
        };
    }

    /**
     * Get the selectable action options for the viewer's filter dropdown.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $action): array => ['value' => $action->value, 'label' => $action->label()],
            self::cases(),
        );
    }

    /**
     * Read a context value as a display string, falling back for missing or
     * non-scalar values so a sentence always renders.
     *
     * @param  array<string, mixed>  $context
     */
    private function text(array $context, string $key): string
    {
        $value = $context[$key] ?? null;

        return is_scalar($value) ? (string) $value : '—';
    }
}
