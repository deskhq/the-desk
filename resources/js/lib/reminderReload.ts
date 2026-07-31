/**
 * The shared props a reminder mutation invalidates.
 *
 * The two move together. A reminder coming due leaves the pending `reminders`
 * list and appears in `firedReminders`; acknowledging the nudge takes it out of
 * the second; setting one puts it back in the first. Every surface that reads
 * either — the Reminders destination, the rail's dot, the nudges — is therefore
 * stale the moment only one of them is refreshed, so the pair is named once here
 * rather than at each mutation (#1093).
 *
 * Typed as a mutable `string[]` because that is what Inertia's `only` takes; a
 * `readonly` tuple would force an `as string[]` cast back at every call site,
 * which is exactly the noise this replaces.
 */
export const REMINDER_PROPS: string[] = ['reminders', 'firedReminders'];

/**
 * The visit options a reminder mutation the user just asked for carries: refresh
 * the two props above and leave everything else — the channel behind the panel,
 * the scroll position, the open dialogs — exactly where it was.
 *
 * A *background* refresh (the per-minute dispatcher's broadcast, an Undo) needs
 * {@see backgroundVisit} on top of the prop list rather than these options, so
 * it takes {@link REMINDER_PROPS} directly.
 */
export const reminderReload = {
    preserveScroll: true,
    preserveState: true,
    only: REMINDER_PROPS,
};
