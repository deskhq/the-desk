import { describe, expect, it } from 'vitest';
import { buildTimelineItems } from '@/lib/timeline';
import {
    anchorAfterPrepend,
    bottomGlueSettled,
    isDividerVisible,
    shouldLoadOlder,
    shouldRenderSkeleton,
    shouldShowUnreadJump,
    timelineItemIndexForMessage,
    unreadDividerIndex,
} from '@/lib/virtualTimeline';
import type { Message } from '@/types';

/** A message carrying just the fields the timeline grouping reads. */
function message(
    id: string,
    userId: string,
    name: string,
    createdAt: string,
): Message {
    return {
        id,
        user: { id: userId, name },
        createdAt,
        type: 'standard',
    } as unknown as Message;
}

const DAY_1_NOON = '2026-07-10T12:00:00.000Z';

function minutesLater(iso: string, minutes: number): string {
    return new Date(new Date(iso).getTime() + minutes * 60_000).toISOString();
}

describe('timelineItemIndexForMessage', () => {
    it('finds the render-item index of the group holding a message', () => {
        const items = buildTimelineItems(
            [
                message('m1', 'u1', 'Maya', DAY_1_NOON),
                message('m2', 'u2', 'Jonas', minutesLater(DAY_1_NOON, 1)),
            ],
            null,
        );

        // [day divider, group(m1), group(m2)] — m2 lives in the item at index 2.
        expect(timelineItemIndexForMessage(items, 'm1')).toBe(1);
        expect(timelineItemIndexForMessage(items, 'm2')).toBe(2);
    });

    it('finds a message grouped mid-run, not just a group lead', () => {
        const items = buildTimelineItems(
            [
                message('m1', 'u1', 'Maya', DAY_1_NOON),
                message('m2', 'u1', 'Maya', minutesLater(DAY_1_NOON, 1)),
            ],
            null,
        );

        // Both messages collapse into one group at index 1.
        expect(timelineItemIndexForMessage(items, 'm2')).toBe(1);
    });

    it('returns -1 when no group holds the message', () => {
        const items = buildTimelineItems(
            [message('m1', 'u1', 'Maya', DAY_1_NOON)],
            null,
        );

        expect(timelineItemIndexForMessage(items, 'missing')).toBe(-1);
    });
});

describe('unreadDividerIndex', () => {
    it('returns the index of the unread divider item', () => {
        const items = buildTimelineItems(
            [
                message('m1', 'u1', 'Maya', DAY_1_NOON),
                message('m2', 'u1', 'Maya', minutesLater(DAY_1_NOON, 1)),
            ],
            'm2',
        );

        // [day divider, group(m1), unread divider, group(m2)].
        expect(unreadDividerIndex(items)).toBe(2);
    });

    it('returns -1 when there is no unread divider', () => {
        const items = buildTimelineItems(
            [message('m1', 'u1', 'Maya', DAY_1_NOON)],
            null,
        );

        expect(unreadDividerIndex(items)).toBe(-1);
    });
});

describe('isDividerVisible', () => {
    it('is visible when inside the rendered window, inclusive of the edges', () => {
        expect(isDividerVisible(2, 2, 5)).toBe(true);
        expect(isDividerVisible(5, 2, 5)).toBe(true);
        expect(isDividerVisible(3, 2, 5)).toBe(true);
    });

    it('is not visible outside the window or when absent', () => {
        expect(isDividerVisible(1, 2, 5)).toBe(false);
        expect(isDividerVisible(6, 2, 5)).toBe(false);
        expect(isDividerVisible(-1, 0, 5)).toBe(false);
    });
});

describe('shouldShowUnreadJump', () => {
    it('shows the jump pill only while the divider sits above the window', () => {
        // Divider at 2, window starts at 4 -> scrolled past it, offer the jump.
        expect(shouldShowUnreadJump(2, 4, 8, false)).toBe(true);
    });

    it('hides the pill once the divider is within or below the window', () => {
        expect(shouldShowUnreadJump(4, 4, 8, false)).toBe(false);
        expect(shouldShowUnreadJump(9, 4, 8, false)).toBe(false);
    });

    it('hides the pill when there is no unread divider', () => {
        expect(shouldShowUnreadJump(-1, 0, 8, false)).toBe(false);
    });

    it('stays hidden once the divider has been seen, even scrolled away', () => {
        // Same geometry as the "shows" case (divider above the window), but the
        // reader has already reached the boundary this visit — the pill must not
        // reappear when they scroll (or jump) away from it.
        expect(shouldShowUnreadJump(2, 4, 8, true)).toBe(false);
    });
});

describe('shouldLoadOlder', () => {
    it('loads when the first rendered item nears the top and more remain', () => {
        expect(shouldLoadOlder(3, 5, true, false)).toBe(true);
        expect(shouldLoadOlder(0, 5, true, false)).toBe(true);
    });

    it('does not load past the threshold, while loading, or with nothing left', () => {
        expect(shouldLoadOlder(6, 5, true, false)).toBe(false);
        expect(shouldLoadOlder(3, 5, true, true)).toBe(false);
        expect(shouldLoadOlder(3, 5, false, false)).toBe(false);
    });
});

describe('anchorAfterPrepend', () => {
    it('offsets scrollTop by the height gained above the viewport', () => {
        // 400px of older history prepended: keep the same rows under the eye.
        expect(anchorAfterPrepend(1000, 1400, 200)).toBe(600);
    });

    it('leaves scrollTop untouched when nothing was prepended', () => {
        expect(anchorAfterPrepend(1000, 1000, 200)).toBe(200);
    });
});

describe('bottomGlueSettled', () => {
    it('counts a frame as stable when the measured height held steady', () => {
        // Same height as last frame: one more consecutive stable frame, and not
        // yet settled while below the required run.
        expect(bottomGlueSettled(5000, 5000, 1, 3)).toEqual({
            stableFrames: 2,
            settled: false,
        });
    });

    it('resets the run the moment the height grows (rows still measuring)', () => {
        // A row measured taller than its estimate, so the true bottom moved down:
        // the glue must keep following it, not release.
        expect(bottomGlueSettled(5200, 5000, 2, 3)).toEqual({
            stableFrames: 0,
            settled: false,
        });
    });

    it('resets the run when the height shrinks below the last frame', () => {
        // A row measured shorter than its estimate is still a height change, so
        // measurement is in flight: reset rather than declare victory.
        expect(bottomGlueSettled(4800, 5000, 2, 3)).toEqual({
            stableFrames: 0,
            settled: false,
        });
    });

    it('settles once the height has held for the required consecutive frames', () => {
        // The plateau is reached: the newest message will no longer drift down,
        // so the glue can release.
        expect(bottomGlueSettled(5000, 5000, 2, 3)).toEqual({
            stableFrames: 3,
            settled: true,
        });
    });
});

describe('shouldRenderSkeleton', () => {
    it('shows a placeholder only while scrubbing an unmeasured row', () => {
        expect(shouldRenderSkeleton(true, false)).toBe(true);
    });

    it('renders real content once measured or once scrolling settles', () => {
        expect(shouldRenderSkeleton(true, true)).toBe(false);
        expect(shouldRenderSkeleton(false, false)).toBe(false);
        expect(shouldRenderSkeleton(false, true)).toBe(false);
    });
});
