import { router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { backgroundVisit } from '@/lib/backgroundVisit';

/**
 * Slide in a reminder nudge the moment one comes due.
 *
 * The per-minute dispatcher flips a due reminder to fired and broadcasts a
 * {@see \App\Events\MessageReminderDue} signal on the viewer's own private
 * `user.{id}` channel. This subscribes to it and, on that signal, reloads only
 * the reminder props: the freshly-fired reminder then appears in
 * `firedReminders` (driving the nudge) and drops out of the pending `reminders`
 * list. Both are recomputed server-side and scoped to the current team, so a
 * user with the workspace open on any page picks the nudge up without a manual
 * reload; one who is away sees it from the same props on their next visit.
 */
export function useMessageReminders(): void {
    const page = usePage();

    const currentUserId = computed(() => String(page.props.auth.user.id));

    function channelName(): string {
        return `user.${currentUserId.value}`;
    }

    // Echo opens a websocket, so touch it only in the browser (never on the SSR
    // pass). The authenticated user is stable for the session, so a single
    // subscribe/teardown pair is enough.
    onMounted(() => {
        echo()
            .private(channelName())
            .listen('MessageReminderDue', () => {
                // The per-minute dispatcher decides when this lands, so it must
                // not interrupt whatever the user is doing; see
                // {@see backgroundVisit}.
                router.reload({
                    ...backgroundVisit,
                    only: ['reminders', 'firedReminders'],
                });
            });
    });

    onBeforeUnmount(() => {
        echo().leave(channelName());
    });
}
