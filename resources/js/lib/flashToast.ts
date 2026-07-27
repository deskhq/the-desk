import { router } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    const toast = useToast();

    /**
     * The tones a server flash may ask for. Deliberately a lookup rather than
     * `toast[data.type]`: the payload crosses the wire, so a tone the client
     * cannot speak has to be ignorable rather than a call on `undefined`.
     */
    const tones: Record<FlashToast['type'], (message: string) => void> = {
        success: toast.success,
        error: toast.error,
        warning: toast.warning,
    };

    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        tones[data.type]?.(data.message);
    });
}
