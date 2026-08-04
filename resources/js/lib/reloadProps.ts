/**
 * The shared prop sets a write invalidates, named once each.
 *
 * A partial reload names the props it wants back, and those names travel with
 * the write rather than with the surface: the same `only` list appeared at
 * sixteen call sites across twelve files, so adding a prop to a set meant
 * finding every copy of it, and a surface refreshing one prop of a pair went
 * stale against the other (#1115). The pairs are the sharp edge — `pins` and
 * `pinCount` are two readings of one fact, and so are `thread` and
 * `threadReplies`.
 *
 * Eight invalidation sets live here; the ninth, {@see REMINDER_PROPS}, is next
 * door in {@see reminderReload} with the visit options a reminder mutation
 * carries. All nine are enforced the same way, by a test that fails on a second
 * copy. {@link THREAD_RESET_PROPS} is not one of them — it names what a thread
 * load *resets* rather than what a write invalidates.
 *
 * Every set is typed as a mutable `string[]` because that is what Inertia's
 * `only` takes; a `readonly` tuple would force an `as string[]` cast back at
 * every call site, which is exactly the noise this replaces.
 */

/**
 * The viewer: the account the shell reads its identity, preferences and
 * timezone from.
 *
 * Named for the account writes nobody is watching, where the point is less what
 * comes back than what does not. A background write that omits `only` is
 * answered with the *whole* page rendered as the route stood when it left, and
 * that reply replaces every prop rather than merging into them — so a
 * destination the reader opened in the meantime loses props that were never in
 * the reply to begin with (#1099).
 */
export const AUTH_PROPS: string[] = ['auth'];

/**
 * The sidebar's channel list.
 *
 * Nearly every workspace write touches it, because the prop carries more than
 * the names: the member's star/mute/notification state, placement, and the
 * last-activity order the direct-message group sorts on. A reaction, a vote and
 * a drag all move something the sidebar draws. What it no longer carries is the
 * badges — see {@link UNREAD_DIGEST_PROPS}, which is why marking a channel read
 * is no longer one of the writes that reaches for this set.
 */
export const CHANNEL_LIST_PROPS: string[] = ['channels'];

/**
 * What the viewer has not read, everywhere at once.
 *
 * The counterpart to {@link CHANNEL_LIST_PROPS} and deliberately separate from
 * it: marking a channel read used to reload the whole roster — every channel's
 * name, placement and participants — to move four integers. The badges now live
 * in one prop of their own, so the write that clears them asks for that and
 * nothing else.
 */
export const UNREAD_DIGEST_PROPS: string[] = ['unread'];

/** The viewer's custom sidebar sections, in their persisted order. */
export const CHANNEL_SECTION_PROPS: string[] = ['channelSections'];

/**
 * Which of the sidebar's *built-in* groups the viewer has collapsed.
 *
 * Separate from {@link CHANNEL_SECTION_PROPS} because the two are stored
 * differently: a custom section carries its own collapse flag on its row, while
 * the built-in groups have no rows and are a set on the account.
 */
export const COLLAPSED_SECTION_PROPS: string[] = ['collapsedChannelSections'];

/**
 * The channel's pins, both readings of them.
 *
 * The masthead shows `pinCount` and the panel lists `pins`; a write refreshing
 * one without the other leaves the badge and the list disagreeing about the
 * same message.
 */
export const PIN_PROPS: string[] = ['pins', 'pinCount'];

/**
 * The open thread: its root and metadata, plus the reply page.
 *
 * The root is only meaningful alongside the replies it heads, so the panel asks
 * for both or neither — a load that returned one would render a thread whose
 * body belongs to a different one.
 */
export const THREAD_PROPS: string[] = ['thread', 'threadReplies'];

/**
 * The half of {@link THREAD_PROPS} a thread load must reset rather than merge.
 *
 * `threadReplies` is a merging paginator, so its pages accumulate as older
 * replies scroll in. Opening a *different* thread has to start that accumulation
 * over, or the new thread's first page appends to the previous thread's.
 */
export const THREAD_RESET_PROPS: string[] = ['threadReplies'];

/** The channel's pending scheduled messages, as the later-delivery tray lists them. */
export const SCHEDULED_MESSAGE_PROPS: string[] = ['scheduledMessages'];
