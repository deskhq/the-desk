import { router, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import {
    channelUploadKey,
    channelUploads,
    releaseChannelUploads,
} from '@/composables/useChannelUploads';
import { useUploadProgressToast } from '@/composables/useUploadProgressToast';
import type { Channel } from '@/types/channels';

/**
 * The workspace's staged uploads, as seen from outside any one composer.
 *
 * A tray outlives the composer that started it, so something above every channel
 * has to answer the two questions {@see useUploadProgressToast} asks — which
 * channel is on screen, and what a tray's channel is called — and has to be the
 * one to drop the whole registry when the viewer leaves the workspace those
 * trays belong to. Only the shell is above every channel, which is why this ran
 * inline in the layout; it needs nothing from the layout but the shared props,
 * so it lives here instead (#1093).
 */
export function useChannelUploadToasts(): void {
    const page = usePage();

    const currentTeam = computed(() => page.props.currentTeam);
    const channels = computed(() => page.props.channels ?? []);
    const activeChannelSlug = computed(
        () =>
            (page.props.channel as { slug?: string } | undefined)?.slug ?? null,
    );

    /**
     * The sidebar's channels by the key their upload tray is filed under, so the
     * upload toast can name and open a channel without taking the key apart
     * again.
     */
    const channelsByUploadKey = computed(() => {
        const team = currentTeam.value;

        if (!team) {
            return new Map<string, Channel>();
        }

        return new Map(
            channels.value.map((channel) => [
                channelUploadKey(team.slug, channel.slug),
                channel,
            ]),
        );
    });

    // Staged attachments outlive the composer that started them, but not the
    // workspace they were staged in: the channels they belong to are gone from
    // the sidebar, so their trays have no surface to come back to. Dropping the
    // whole registry here is what releases their previews.
    watch(
        () => currentTeam.value?.id,
        () => releaseChannelUploads(),
    );

    useUploadProgressToast({
        channels: () => channelUploads.value,
        activeChannelKey: () =>
            currentTeam.value && activeChannelSlug.value
                ? channelUploadKey(
                      currentTeam.value.slug,
                      activeChannelSlug.value,
                  )
                : null,
        channelName: (key) => {
            const channel = channelsByUploadKey.value.get(key);

            if (!channel) {
                return null;
            }

            return channel.isDirect ? channel.name : `#${channel.name}`;
        },
        openChannel: (key) => {
            const channel = channelsByUploadKey.value.get(key);

            if (channel && currentTeam.value) {
                router.visit(
                    show({
                        team: currentTeam.value.slug,
                        channel: channel.slug,
                    }).url,
                );
            }
        },
    });
}
