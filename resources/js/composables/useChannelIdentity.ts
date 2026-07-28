import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useTranslations } from '@/composables/useTranslations';
import { groupDmMastheadName } from '@/lib/groupDm';
import type { Channel, Mention } from '@/types';

export interface ChannelIdentityOptions {
    channel: () => Channel;
    /** The channel's members, as the roster and the mention menu see them. */
    members: () => Mention[];
    /** Whether the viewer belongs to the channel. */
    isMember: () => boolean;
}

export interface ChannelIdentity {
    currentUser: ComputedRef<{ id: string; name: string }>;
    /** Whether this is the viewer's own self-DM, which reads "You". */
    isSelfDm: ComputedRef<boolean>;
    /** What the channel is called from the viewer's side of it. */
    mastheadTitle: ComputedRef<string>;
    /** Whether the viewer may grow this DM by adding people. */
    canAddPeople: ComputedRef<boolean>;
    /** A DM's composer placeholder, or undefined to keep the channel default. */
    composerPlaceholder: ComputedRef<string | undefined>;
    /** Whether the viewer may delete anyone's message here (a team Admin+). */
    canModerate: ComputedRef<boolean>;
    /** The members the composer offers for `@`. */
    mentionableMembers: ComputedRef<Mention[]>;
    /** Whether the channel has a bot member. */
    channelHasBots: ComputedRef<boolean>;
}

/**
 * How the open channel reads to this viewer: its name, its composer
 * placeholder, who they may mention, and what they are allowed to do in it.
 *
 * A direct message renders viewer-relative: no "#", the other participant's
 * name (the viewer's own self-DM reads "You"), or a group's participant-joined
 * name. That drives the `<Head>` title, the masthead, and the empty state's
 * viewer-relative copy; the masthead owns the DM avatar and facepile itself.
 */
export function useChannelIdentity(
    options: ChannelIdentityOptions,
): ChannelIdentity {
    const page = usePage();
    const { t } = useTranslations();

    const currentUser = computed(() => ({
        id: String(page.props.auth.user.id),
        name: page.props.auth.user.name,
    }));

    const isSelfDm = computed(
        () =>
            options.channel().isDirect &&
            options.channel().dmUserId === currentUser.value.id,
    );

    const mastheadTitle = computed(() => {
        if (options.channel().isGroupDirect) {
            return (
                groupDmMastheadName(options.channel().dmParticipants ?? []) ||
                t('Group conversation')
            );
        }

        return isSelfDm.value ? t('You') : options.channel().name;
    });

    /**
     * The viewer may add people to any DM they belong to; grows a 1:1 into a group
     * or a group further. Drives the masthead's "Add people" button and its modal.
     */
    const canAddPeople = computed(
        () => options.channel().isDirect && options.isMember(),
    );

    /**
     * A DM's composer addresses the conversation by its participant name rather
     * than a "#channel", so its placeholder overrides the composer's default.
     */
    const composerPlaceholder = computed(() =>
        options.channel().isDirect
            ? t('Message :name', { name: mastheadTitle.value })
            : undefined,
    );

    /** A team Admin+ may delete anyone's message in the channel (moderation). */
    const canModerate = computed(() =>
        ['admin', 'owner'].includes(page.props.currentTeam?.role ?? ''),
    );

    // You can't @mention yourself, and bots have no inbox to reach, so drop the
    // current user and any bots from the composer list — the roster facepile still
    // shows the bots.
    const mentionableMembers = computed(() =>
        options
            .members()
            .filter(
                (member) => member.id !== currentUser.value.id && !member.isBot,
            ),
    );

    // Whether this channel has a bot member, so the composer's mention menu can
    // note once, quietly, why bots aren't mentionable.
    const channelHasBots = computed(() =>
        options.members().some((member) => member.isBot),
    );

    return {
        currentUser,
        isSelfDm,
        mastheadTitle,
        canAddPeople,
        composerPlaceholder,
        canModerate,
        mentionableMembers,
        channelHasBots,
    };
}
