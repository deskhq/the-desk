import type { Ref } from 'vue';
import { onMounted, ref } from 'vue';
import { isStandaloneDisplay } from '@/lib/displayMode';

/**
 * Whether this sign-in should outlive the session lifetime, defaulted to what
 * the device it happens on deserves.
 *
 * The installed app presents itself as an app on someone's own device, so being
 * signed out after a couple of idle hours reads as a defect. A browser tab may
 * be a shared or public machine, where a silently persistent login would be a
 * security regression, so it keeps its opt-in. Either way the user still owns
 * the choice — this only decides where the checkbox starts.
 *
 * The default is applied after mount, never during SSR, so the server-rendered
 * markup is the same for every device.
 */
export function useRememberDevice(): Ref<boolean> {
    const remember = ref(false);

    onMounted(() => {
        if (isStandaloneDisplay()) {
            remember.value = true;
        }
    });

    return remember;
}
