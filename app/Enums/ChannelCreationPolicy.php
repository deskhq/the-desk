<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Team;

/**
 * Who, in a workspace, is allowed to open a new channel.
 *
 * Held per visibility ({@see Team::creationPolicyFor()}): a
 * workspace can curate its public directory while leaving private channels
 * self-service, or the reverse.
 */
enum ChannelCreationPolicy: string
{
    /** Anyone in the workspace may create one. The default, and how it has always worked. */
    case Members = 'members';

    /** Reserved for team Admins and the Owner. */
    case Admins = 'admins';

    /**
     * Get the display label for the policy.
     */
    public function label(): string
    {
        return match ($this) {
            self::Members => __('Everyone'),
            self::Admins => __('Admins only'),
        };
    }

    /**
     * Get the one-line explanation of who the policy lets through, for the
     * settings form's option list.
     */
    public function description(): string
    {
        return match ($this) {
            self::Members => __('Every member of the workspace can create one.'),
            self::Admins => __('Only admins and the workspace owner can create one.'),
        };
    }

    /**
     * Determine whether a member holding the given workspace role passes.
     *
     * A null role means the user is not in the workspace at all, which no
     * policy ever lets through.
     */
    public function permits(?TeamRole $role): bool
    {
        return match ($this) {
            self::Members => $role instanceof TeamRole,
            self::Admins => $role?->isAtLeast(TeamRole::Admin) ?? false,
        };
    }

    /**
     * Get the selectable policies as value/label pairs for the settings UI.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $policy): array => [
                'value' => $policy->value,
                'label' => $policy->label(),
                'description' => $policy->description(),
            ],
            self::cases(),
        );
    }
}
