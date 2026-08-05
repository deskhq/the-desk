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
 * Twelve invalidation sets live here; the thirteenth, {@see REMINDER_PROPS}, is
 * next door in {@see reminderReload} with the visit options a reminder mutation
 * carries. All thirteen are enforced the same way, by a test that fails on a
 * second copy. Two are not quite invalidation sets: {@link THREAD_RESET_PROPS}
 * names what a thread load *resets* rather than what a write invalidates, and
 * {@link WORKSPACE_PROPS} names what a *switch* invalidates, which is not a
 * write at all.
 *
 * Since #1252 these sets carry a second duty. Most of the props they name are
 * `once` props, excluded from every ordinary navigation, and it is the reload
 * naming them that brings them back — the once exclusion is bypassed on partial
 * requests. A write that stops naming its set therefore no longer goes stale on
 * the next navigation; it stays stale until a hard reload.
 *
 * Every set is typed as a mutable `string[]` because that is what Inertia's
 * `only` takes; a `readonly` tuple would force an `as string[]` cast back at
 * every call site, which is exactly the noise this replaces.
 */

import { REMINDER_PROPS } from '@/lib/reminderReload';

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

/**
 * Everything the server renders in the reader's own language.
 *
 * These are `once` props keyed by the locale they hold (#1251), which answers
 * moving *to* a new language: the key is one the client has never declared, so
 * the props ship. It does not answer moving *back*. The client stores a once
 * prop by key but restores it by prop name, so `en → fr → en` finds `locale:en`
 * already declared, excludes the props, and restores the French copy it happens
 * to be holding — labels frozen in the language the reader just left.
 *
 * A partial reload is what closes that, because the once exclusion is bypassed
 * on partial requests. Hence the one write that changes the reader's language
 * asks for these by name; the visit that persists the preference cannot do it
 * on its own, since it is an ordinary visit and subject to the exclusion.
 */
export const LOCALE_PROPS: string[] = [
    'locale',
    'translations',
    'slashCommands',
    'sidebarPositions',
    'invitableRoles',
];

/**
 * The team's member roster, as the DM entry points and the presence dots read it.
 *
 * Named for the reload it replaces. A teammate changing their avatar, status or
 * do-not-disturb state used to reload the page with no `only` at all — the
 * anti-pattern this file exists to retire (#1099), and on someone else's timing:
 * the reply is the whole page as the route stood when it left, and it replaces
 * every prop rather than merging, so a destination the reader opened in the
 * meantime loses props that were never in the reply.
 */
export const TEAM_MEMBER_PROPS: string[] = ['teamMembers'];

/**
 * The viewer's frequently-used emoji, as the quick-react cluster ranks them.
 *
 * Rides the reaction write next to {@link CHANNEL_LIST_PROPS} rather than alone:
 * a reaction is the only thing that re-ranks the list, and it already asks for
 * the roster because the sidebar's activity order moves with it.
 */
export const FREQUENT_EMOJI_PROPS: string[] = ['frequentEmojis'];

/**
 * Everything whose answer is one workspace's, named for the one move that
 * changes all of them at once and is not a write: switching workspace.
 *
 * These are `once` props keyed by the team they describe (#1252), which answers
 * moving *to* a workspace the client has not seen. It does not answer moving
 * *back*. The client stores a once prop by key but restores it by prop name, so
 * A → B → A finds team A's key already declared, excludes the props, and
 * restores the rosters of the workspace the reader just left — the sidebar of
 * one workspace on the pages of another.
 *
 * A partial reload is what closes that, exactly as it does for
 * {@link LOCALE_PROPS}: the once exclusion is bypassed on partial requests. It
 * fires once the switch has landed rather than riding the visit that lands it,
 * because that visit is a navigation and needs the destination's page props too.
 */
export const WORKSPACE_PROPS: string[] = [
    ...CHANNEL_LIST_PROPS,
    ...CHANNEL_SECTION_PROPS,
    ...FREQUENT_EMOJI_PROPS,
    ...REMINDER_PROPS,
];

/**
 * The one-time security prompt owed to an account created in this session.
 *
 * Cached while there is nothing to offer — which is every visit but a handful —
 * so answering it has to say so by name. Without this the dismissal would leave
 * the client restoring the prompt it just answered, and the modal would come
 * back on the next navigation.
 */
export const POST_REGISTRATION_PROMPT_PROPS: string[] = [
    'postRegistrationPrompt',
];
