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
    case EmojiRevoked = 'emoji_revoked';

    /**
     * Get the short human-readable label used in the action filter and headers.
     */
    public function label(): string
    {
        return match ($this) {
            self::TeamRenamed => __('Workspace renamed'),
            self::MemberRoleChanged => __('Member role changed'),
            self::MemberRemoved => __('Member removed'),
            self::OwnershipTransferred => __('Ownership transferred'),
            self::ChannelCreated => __('Channel created'),
            self::ChannelArchived => __('Channel archived'),
            self::ChannelMemberAdded => __('Channel member added'),
            self::ChannelMemberRemoved => __('Channel member removed'),
            self::MessageDeleted => __('Message deleted'),
            self::EmojiRevoked => __('Custom emoji revoked'),
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
            self::TeamRenamed => sprintf(__('Renamed the workspace from “%s” to “%s”'), $this->text($context, 'old_name'), $this->text($context, 'new_name')),
            self::MemberRoleChanged => sprintf(__('Changed %s’s role from %s to %s'), $this->text($context, 'member_name'), $this->text($context, 'old_role'), $this->text($context, 'new_role')),
            self::MemberRemoved => sprintf(__('Removed %s from the workspace'), $this->text($context, 'member_name')),
            self::OwnershipTransferred => sprintf(__('Transferred ownership to %s'), $this->text($context, 'new_owner_name')),
            self::ChannelCreated => sprintf(__('Created #%s'), $this->text($context, 'channel_name')),
            self::ChannelArchived => sprintf(__('Archived #%s'), $this->text($context, 'channel_name')),
            self::ChannelMemberAdded => sprintf(__('Added %s to #%s'), $this->text($context, 'member_name'), $this->text($context, 'channel_name')),
            self::ChannelMemberRemoved => sprintf(__('Removed %s from #%s'), $this->text($context, 'member_name'), $this->text($context, 'channel_name')),
            self::MessageDeleted => sprintf(__('Deleted a message from %s in #%s'), $this->text($context, 'author_name'), $this->text($context, 'channel_name')),
            self::EmojiRevoked => sprintf(__('Revoked the :%s: custom emoji'), $this->text($context, 'emoji_name')),
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
