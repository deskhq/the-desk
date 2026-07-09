import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { playChime, unlockChimeAudio } from '@/lib/chimeSounds';
import { update } from '@/routes/notifications';
import type { ChimeSound } from '@/types';

/**
 * Read and mutate the current user's account-wide chime preference, and preview
 * sounds. The preference is the shared `auth.user.chime_sound` prop, so every
 * consumer (the settings page and the realtime listener) stays in sync.
 */
export function useChimes() {
    const page = usePage();

    const chimeSound = computed<ChimeSound>(
        () => page.props.auth.user.chime_sound ?? 'ping',
    );

    const chimeEnabled = computed(() => chimeSound.value !== 'off');

    /**
     * Play a sound now. Called from a click, so it also unlocks the AudioContext
     * that the autoplay policy keeps suspended until the first user gesture.
     */
    function preview(sound: ChimeSound): void {
        unlockChimeAudio();
        playChime(sound);
    }

    /**
     * Persist a new chime choice. The shared prop refreshes from the redirect, so
     * no optimistic state is needed.
     */
    function updateChimeSound(sound: ChimeSound): void {
        router.patch(
            update().url,
            { chime_sound: sound },
            { preserveScroll: true, preserveState: true },
        );
    }

    return { chimeSound, chimeEnabled, preview, updateChimeSound };
}
