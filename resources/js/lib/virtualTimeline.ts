import type { TimelineItem } from '@/lib/timeline';

/**
 * Pure helpers backing the virtualized channel timeline. Windowed rendering
 * drops off-screen rows from the DOM, so the position-dependent affordances that
 * once leaned on `getElementById` and an `IntersectionObserver` (jump-to-message,
 * the unread boundary, reverse infinite scroll) are re-expressed here in terms of
 * render-item indices and the virtualizer's visible range. Keeping the logic pure
 * makes it unit-testable without a layout engine.
 */

/**
 * The render-item index of the group that holds `messageId`, or -1 when no group
 * contains it. Consecutive messages collapse into a single group, so a message
 * grouped mid-run resolves to its group's index — exactly the index the
 * virtualizer must scroll to in order to bring that message on screen.
 */
export function timelineItemIndexForMessage(
    items: TimelineItem[],
    messageId: string,
): number {
    return items.findIndex(
        (item) =>
            item.type === 'group' &&
            item.messages.some((message) => message.id === messageId),
    );
}

/**
 * The render-item index of the "new messages" unread divider, or -1 when the
 * timeline has no unread boundary to mark.
 */
export function unreadDividerIndex(items: TimelineItem[]): number {
    return items.findIndex(
        (item) => item.type === 'divider' && item.variant === 'unread',
    );
}

/**
 * Whether the render item at `index` currently sits within the virtualizer's
 * rendered window (`startIndex`..`endIndex`, inclusive). A negative index — a
 * boundary that doesn't exist — is never visible.
 */
export function isDividerVisible(
    index: number,
    startIndex: number,
    endIndex: number,
): boolean {
    return index >= 0 && index >= startIndex && index <= endIndex;
}

/**
 * Whether the floating "New messages" jump pill should show: only while an
 * existing unread divider sits strictly above the rendered window, i.e. the
 * reader has scrolled (or opened) past it and it's off-screen upward. Once the
 * divider scrolls into or below the window the pill is redundant and hides.
 *
 * `dividerSeen` is a per-visit latch: once the reader has reached the unread
 * boundary (scrolled it into view) or jumped back to the present, the pill is
 * dismissed for the rest of the channel visit and must not reappear when they
 * later scroll away from the (frozen) divider.
 */
export function shouldShowUnreadJump(
    dividerIndex: number,
    startIndex: number,
    endIndex: number,
    dividerSeen: boolean,
): boolean {
    return (
        !dividerSeen &&
        dividerIndex >= 0 &&
        !isDividerVisible(dividerIndex, startIndex, endIndex) &&
        dividerIndex < startIndex
    );
}

/**
 * Whether to fetch the next (older) page: the first rendered item has come
 * within `threshold` items of the top of the loaded history, another page
 * remains, and no request is already in flight.
 */
export function shouldLoadOlder(
    startIndex: number,
    threshold: number,
    hasMore: boolean,
    isLoading: boolean,
): boolean {
    return hasMore && !isLoading && startIndex <= threshold;
}

/**
 * The `scrollTop` that keeps the viewport visually anchored after older history
 * is prepended above it. The list grew taller at the top, so shifting the scroll
 * offset by the height gained holds the same rows under the reader's eye instead
 * of yanking them downward.
 */
export function anchorAfterPrepend(
    oldScrollHeight: number,
    newScrollHeight: number,
    oldScrollTop: number,
): number {
    return oldScrollTop + (newScrollHeight - oldScrollHeight);
}

/**
 * Whether a virtual row should render a height-stable skeleton placeholder
 * instead of its full message content: only while the list is actively being
 * scrubbed and the row has not yet been measured. Deferring the expensive
 * message render during a fast scroll keeps the scrub smooth; the row
 * materializes into real content the moment scrolling settles or its height is
 * known.
 */
export function shouldRenderSkeleton(
    isScrolling: boolean,
    isMeasured: boolean,
): boolean {
    return isScrolling && !isMeasured;
}
