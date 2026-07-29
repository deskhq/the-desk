import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { NEAR_BOTTOM_THRESHOLD } from '@/composables/useScrollPin';
import { useTimelineVirtualizer } from '@/composables/useTimelineVirtualizer';
import type { TimelineItem } from '@/lib/timeline';
import { bottomGlueSettled, shouldRenderSkeleton } from '@/lib/virtualTimeline';

/** One rendered row: a render item plus its absolute offset when windowed. */
export type RenderRow = {
    item: TimelineItem;
    index: number;
    offsetTop: number | null;
};

/**
 * A windowed jump can't know the true bottom up front: `scrollToEnd` aims at the
 * bottom the virtualizer can see now, but each row measured as the animation
 * passes it grows the list, so the target keeps moving. `finalizeJump` follows
 * the animation to the bottom, then glues the view there frame by frame until both
 * the measured height plateaus and the scroll goes quiet, so late measurements
 * settle without a visible bounce.
 *
 * The glue releases on convergence, not a fixed frame count: it holds until the
 * scroll height has been steady for `JUMP_SETTLE_FRAMES` consecutive frames *and*
 * the container has stopped scrolling. A fixed budget let go too early on a slow
 * render — while rows were still measuring, or while the glue's own `scrollTop`
 * writes still counted as scrolling — so unsettled rows flipped in their scrub
 * skeletons and the virtualizer's re-enabled size-change anchoring drifted the
 * view below the fold: the timeline landing short of the newest message on initial
 * open of a long list of tall rows (#500).
 */
const JUMP_SETTLE_FRAMES = 4;
const JUMP_MAX_FRAMES = 180;

export type TimelineWindow = {
    /** Whether rows are being windowed right now (opted in, and mounted). */
    virtualizeActive: ComputedRef<boolean>;
    /** The virtual list's full height, spacing the absolutely-placed rows. */
    totalSize: Ref<number>;
    /** The rows to render: the whole list, or just the virtualizer's window. */
    renderRows: ComputedRef<RenderRow[]>;
    /** Function ref handing each rendered row to the virtualizer to measure. */
    measureRow: ReturnType<typeof useTimelineVirtualizer>['measureRow'];
    /** Whether a row should stand in as a height-stable scrub placeholder. */
    showsSkeleton: (item: TimelineItem) => boolean;
    /** Bring an off-screen row (a jump target, the unread boundary) into view. */
    scrollToIndex: ReturnType<typeof useTimelineVirtualizer>['scrollToIndex'];
    /** Scroll to the newest message, following it to the settled bottom. */
    scrollToLatest: (behavior?: ScrollBehavior) => void;
};

/**
 * Windowed rendering for the message timeline, and the jump-to-present that has
 * to chase a bottom which moves as rows measure.
 *
 * Only the main channel timeline opts in (`virtualize`); the thread panel leaves
 * the virtualizer idle (count 0) and renders every row. The virtualizer drives
 * the parent's scroll container, keeping its real `scrollHeight`/`scrollTop` so
 * the shared pin-to-bottom math is untouched. Windowing is client-only: the
 * virtualizer needs a live scroll element and measured heights, neither of which
 * exist during SSR. Rendering the full list on the server (and through first
 * hydration) keeps server and client markup identical, then we switch to the
 * window once mounted — a post-hydration re-render, not a mismatch.
 */
export function useTimelineWindow(options: {
    /** The parent-owned scroll container the virtualizer drives. */
    scrollElement: () => HTMLElement | null;
    /** The flat, divider-interleaved render list being windowed. */
    renderItems: ComputedRef<TimelineItem[]>;
    /** Whether the consumer opts into windowing at all. */
    virtualize: () => boolean;
    hasOlder: () => boolean;
    isLoadingOlder: () => boolean;
    loadOlder: () => void;
    /** Called whenever the visible render-item window changes. */
    onRangeChange: (range: { startIndex: number; endIndex: number }) => void;
}): TimelineWindow {
    const isMounted = ref(false);

    onMounted(() => {
        isMounted.value = true;
    });

    const virtualizeActive = computed(
        () => options.virtualize() && isMounted.value,
    );

    /**
     * True while a deliberate jump-to-present is in flight. The scrub skeletons swap
     * full message content for a fixed 56px placeholder, so measuring them mid-jump
     * feeds the virtualizer short heights; when rows then swap to their taller real
     * content the list grows and the animation is left stranded above the newest
     * message — the "it stops when the skeleton loads" symptom (#347). While this is
     * set, skeletons are suppressed so every row entering the window measures its
     * real height, and the virtualizer's upward size-change anchoring is disabled so
     * a row measured above the viewport can't drift us up as we settle on the bottom.
     */
    const jumpingToBottom = ref(false);

    const {
        virtualRows,
        totalSize,
        isScrolling,
        range,
        scrollToIndex,
        scrollToEnd,
        measureRow,
    } = useTimelineVirtualizer({
        scrollElement: computed(() => options.scrollElement()),
        count: computed(() =>
            virtualizeActive.value ? options.renderItems.value.length : 0,
        ),
        hasOlder: options.hasOlder,
        isLoadingOlder: options.isLoadingOlder,
        loadOlder: options.loadOlder,
        // Suppress upward anchor adjustments while jumping to the bottom: there we
        // want to stay glued to the newest message, not compensate an upper row.
        isPinning: () => jumpingToBottom.value,
    });

    /**
     * The rows to render: the full list when the thread panel renders it flat, or
     * just the virtualizer's window (each carrying its absolute offset) for the main
     * timeline.
     */
    const renderRows = computed<RenderRow[]>(() => {
        if (!virtualizeActive.value) {
            return options.renderItems.value.map((item, index) => ({
                item,
                index,
                offsetTop: null,
            }));
        }

        return virtualRows.value.map((row) => ({
            item: options.renderItems.value[row.index],
            index: row.index,
            offsetTop: row.start,
        }));
    });

    /**
     * Render items whose height the virtualizer has already settled. A row is
     * recorded once scrolling stops, so a fast scrub shows height-stable skeletons
     * for rows it hasn't paused on, and they materialize the instant it settles.
     */
    const settledKeys = ref<Set<string>>(new Set());

    /**
     * Whether the container sits within the pinned threshold of the real bottom.
     * A missing element counts as pinned, mirroring `useScrollPin`.
     */
    function isAtBottom(): boolean {
        const el = options.scrollElement();

        if (!el) {
            return true;
        }

        return (
            el.scrollHeight - el.scrollTop - el.clientHeight <=
            NEAR_BOTTOM_THRESHOLD
        );
    }

    watch(isScrolling, (scrolling) => {
        if (scrolling) {
            return;
        }

        for (const row of virtualRows.value) {
            const item = options.renderItems.value[row.index];

            if (item) {
                settledKeys.value.add(item.key);
            }
        }
    });

    let jumpRaf: number | null = null;

    /**
     * Drive an in-flight jump-to-present all the way to the newest message.
     *
     * Phase one follows the scroll: while the virtualizer reports it's still
     * scrolling the (possibly smooth) animation is running, so leave it be; only
     * once it has stopped short — stalled on a skeleton-height estimate rather than
     * the real bottom — snap the residual gap. Gating on the scrolling flag means an
     * explicit `smooth` jump keeps animating instead of being force-converted to an
     * instant snap. Once the bottom is reached, phase two pins `scrollTop` to the end
     * every frame — so a row measured late (taller or shorter than its estimate)
     * can't drift the view up or leave it short — and releases only once the measured
     * height has settled (`bottomGlueSettled`) *and* the scroll has quiesced, rather
     * than after a fixed budget that could expire mid-measurement and strand the view
     * (#347, #500). Bounded by a frame budget so a list that never settles can't spin
     * forever.
     */
    function finalizeJump(): void {
        if (jumpRaf !== null) {
            cancelAnimationFrame(jumpRaf);
        }

        let reachedBottom = false;
        let stableFrames = 0;
        // Sentinel so the first glue frame always registers as a change: the glue
        // needs at least `JUMP_SETTLE_FRAMES` genuine same-height frames to release.
        let lastHeight = -1;
        let frames = 0;

        const step = (): void => {
            const el = options.scrollElement();

            if (!el || !jumpingToBottom.value || frames >= JUMP_MAX_FRAMES) {
                jumpingToBottom.value = false;
                jumpRaf = null;

                return;
            }

            frames += 1;

            if (!reachedBottom) {
                if (isAtBottom()) {
                    reachedBottom = true;
                } else if (!isScrolling.value) {
                    // The scroll stopped short of the newest message; snap the gap
                    // and let the newly measured rows re-target.
                    scrollToEnd('auto');
                }
            } else {
                // Glue to the bottom so late row measurements settle invisibly, and
                // hold until the height stops moving — the true bottom has arrived.
                el.scrollTop = el.scrollHeight;

                const settle = bottomGlueSettled(
                    el.scrollHeight,
                    lastHeight,
                    stableFrames,
                    JUMP_SETTLE_FRAMES,
                );
                stableFrames = settle.stableFrames;
                lastHeight = el.scrollHeight;

                // Release only once the height has settled *and* the scroll has gone
                // quiet. Letting go while `isScrolling` is still true (the glue's own
                // `scrollTop` writes keep it set for ~150ms after the last change)
                // would flip `jumpingToBottom` off mid-scroll, so unsettled rows render
                // their 56px scrub skeletons — shrinking the content above the viewport
                // and drifting the view up off the newest message (#347, #500). Waiting
                // for the scroll to quiesce keeps skeletons suppressed until the view is
                // safely at rest on the bottom.
                if (settle.settled && !isScrolling.value) {
                    jumpingToBottom.value = false;
                    jumpRaf = null;

                    return;
                }
            }

            jumpRaf = requestAnimationFrame(step);
        };

        jumpRaf = requestAnimationFrame(step);
    }

    onUnmounted(() => {
        if (jumpRaf !== null) {
            cancelAnimationFrame(jumpRaf);
        }
    });

    /**
     * Whether a windowed group row should show its skeleton placeholder rather than
     * its full content: only mid-scrub, never during a jump-to-present (measuring
     * the placeholder height would strand the jump short), and only before the row
     * has settled.
     */
    function showsSkeleton(item: TimelineItem): boolean {
        return (
            virtualizeActive.value &&
            !jumpingToBottom.value &&
            item.type === 'group' &&
            shouldRenderSkeleton(
                isScrolling.value,
                settledKeys.value.has(item.key),
            )
        );
    }

    // Surface the visible window so the parent can drive the unread-jump pill.
    watch(
        () =>
            range.value
                ? `${range.value.startIndex}:${range.value.endIndex}`
                : null,
        () => {
            if (range.value) {
                options.onRangeChange({
                    startIndex: range.value.startIndex,
                    endIndex: range.value.endIndex,
                });
            }
        },
    );

    /**
     * Scroll to the newest message. When windowed, drive the virtualizer so it
     * re-targets the real bottom as off-screen rows measure (a native
     * `scrollTo(scrollHeight)` settles short on an estimated spacer, #347); when the
     * list is rendered flat (the thread panel), `scrollHeight` is already exact, so
     * scroll the container directly.
     */
    function scrollToLatest(behavior: ScrollBehavior = 'auto'): void {
        if (virtualizeActive.value) {
            // Suppress scrub skeletons for the whole jump so their fixed placeholder
            // height can't strand the scroll short of the newest message (#347), then
            // let `finalizeJump` watch the animation to the true bottom and release.
            jumpingToBottom.value = true;
            scrollToEnd(behavior);
            finalizeJump();

            return;
        }

        const el = options.scrollElement();

        el?.scrollTo({ top: el.scrollHeight, behavior });
    }

    return {
        virtualizeActive,
        totalSize,
        renderRows,
        measureRow,
        showsSkeleton,
        scrollToIndex,
        scrollToLatest,
    };
}
