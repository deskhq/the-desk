import {
    computed,
    effectScope,
    onScopeDispose,
    shallowReactive,
    watch,
} from 'vue';
import type { ComputedRef, EffectScope } from 'vue';
import { useAttachmentUploads } from '@/composables/useAttachmentUploads';
import type {
    AttachmentUploads,
    AttachmentUploadsOptions,
} from '@/composables/useAttachmentUploads';

export interface ChannelUploadsOptions extends AttachmentUploadsOptions {
    /**
     * The channel this tray belongs to, as `team/channel`. Null where the
     * composer has no channel to upload to (a thread composer), which gets an
     * unregistered tray owned by the calling scope instead.
     */
    channelKey: string | null;
}

/** One channel's tray, and what it takes to know when to let go of it. */
interface RegistryEntry {
    /** Owns the tray's effects, so stopping it runs the tray's own teardown. */
    scope: EffectScope;
    uploads: AttachmentUploads;
    /** How many composers are mounted on this channel right now. */
    consumers: number;
}

/**
 * Shallow rather than deep, so an entry reads back as the object that was put
 * in: the tray a composer holds and the tray the registry hands the next one
 * have to be the same object, not two proxies of it.
 */
const registry = shallowReactive(new Map<string, RegistryEntry>());

/** One channel's tray, as everything outside the composer sees it. */
export interface ChannelUploads {
    /** The channel it belongs to, as `team/channel`. */
    channelKey: string;
    uploads: AttachmentUploads;
}

/**
 * Every channel holding a tray right now — the whole of what is staged across
 * the workspace, which is what lets a surface outside the composer (the upload
 * toast) report on channels the user is not looking at.
 */
export const channelUploads: ComputedRef<ChannelUploads[]> = computed(() =>
    [...registry.entries()].map(([channelKey, entry]) => ({
        channelKey,
        uploads: entry.uploads,
    })),
);

/** The registry key for a channel's tray. */
export function channelUploadKey(
    teamSlug: string,
    channelSlug: string,
): string {
    return `${teamSlug}/${channelSlug}`;
}

/**
 * Build a channel's tray in a scope of its own, outside whichever component
 * happened to ask for it first, and arrange for it to be released the moment it
 * holds nothing anyone is coming back for.
 */
function createEntry(
    channelKey: string,
    options: ChannelUploadsOptions,
): RegistryEntry {
    const scope = effectScope(true);

    // Read the caller's getters once: they close over the props of the composer
    // that opened the channel, which the entry now outlives. Everything they
    // answer is fixed by the channel key anyway — a renamed channel is a new
    // key, and the caps are server config — so freezing them here keeps a
    // long-lived entry from reading an unmounted component's props.
    const endpoint = options.endpoint();
    const maxSizeMb = options.maxSizeMb();
    const maxPerMessage = options.maxPerMessage();

    let uploads!: AttachmentUploads;

    scope.run(() => {
        uploads = useAttachmentUploads({
            endpoint: () => endpoint,
            maxSizeMb: () => maxSizeMb,
            maxPerMessage: () => maxPerMessage,
            uploader: options.uploader,
            createObjectUrl: options.createObjectUrl,
            revokeObjectUrl: options.revokeObjectUrl,
        });

        // The tray's rows are the only reason to keep it: once the last one has
        // been sent, removed or cleared and no composer is showing it, the entry
        // has nothing left to hand back. Synchronous so the release lands with
        // the removal that caused it rather than a tick later.
        watch(uploads.isIdle, () => releaseIfSpent(channelKey), {
            flush: 'sync',
        });
    });

    const entry: RegistryEntry = { scope, uploads, consumers: 0 };
    registry.set(channelKey, entry);

    return entry;
}

/** Drop a channel's entry, running the tray's teardown and freeing its previews. */
function release(channelKey: string): void {
    const entry = registry.get(channelKey);

    if (!entry) {
        return;
    }

    // Deleted first, so the teardown below cannot re-enter this entry.
    registry.delete(channelKey);
    entry.scope.stop();
}

/** Release a channel's entry if nothing is watching it and nothing is in it. */
function releaseIfSpent(channelKey: string): void {
    const entry = registry.get(channelKey);

    if (entry && entry.consumers === 0 && entry.uploads.isIdle.value) {
        release(channelKey);
    }
}

/**
 * The pre-send attachment tray for a channel, held by the channel rather than
 * by the composer that renders it.
 *
 * The composer is mounted per channel and remounts on every switch, so a tray
 * it owned would abort its own in-flight uploads the moment the user looked at
 * another channel — destroying the transfer rather than merely hiding it. Held
 * here, an upload runs on to its end and its row is still in the tray on
 * return, still claimable by a send (the server keeps an unclaimed upload for
 * `attachments.pending_ttl_hours`).
 *
 * The lifetime that replaces "dies with its composer": an entry lives while it
 * holds rows or an in-flight send's snapshot, and is released as soon as it
 * holds neither and no composer is mounted on it — which is what frees its
 * preview object URLs. There is no timed eviction and no LRU: a row in a tray
 * is work the user can still see and act on, and evicting it loses that
 * silently. {@see releaseChannelUploads} drops the lot on a team switch.
 */
export function useChannelUploads(
    options: ChannelUploadsOptions,
): AttachmentUploads {
    const { channelKey } = options;

    // No channel, no tray to outlive: a composer that cannot upload anywhere
    // (the thread composer) keeps its own, torn down with it as it always was.
    if (channelKey === null) {
        return useAttachmentUploads(options);
    }

    const entry = registry.get(channelKey) ?? createEntry(channelKey, options);

    entry.consumers += 1;

    onScopeDispose(() => {
        entry.consumers -= 1;
        releaseIfSpent(channelKey);
    });

    return entry.uploads;
}

/**
 * Drop every channel's tray, aborting what is in flight and releasing every
 * preview. Called on a team switch: the registry is scoped to one workspace,
 * and the trays of the one being left have no surface to come back to.
 */
export function releaseChannelUploads(): void {
    for (const channelKey of [...registry.keys()]) {
        release(channelKey);
    }
}
