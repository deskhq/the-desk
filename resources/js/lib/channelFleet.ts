/**
 * The subscribe/reconcile/teardown bookkeeping for a *fleet* of channel
 * subscriptions — the shared lifecycle behind sidebar badges, chime
 * notifications, and the active channel. Pure and framework-free: it owns the
 * bound-set diffing and the active-channel handoff, and defers the actual Echo
 * subscribe/leave to injected handlers, so the tricky part is unit-testable
 * without Vue or a live Echo mock.
 */
export interface ChannelFleetHandlers {
    /** Attach a live subscription (an Echo private-channel listener) for an id. */
    subscribe: (channelId: string) => void;
    /** Tear down the subscription for an id. */
    leave: (channelId: string) => void;
}

export interface ChannelFleet {
    /**
     * Reconcile the live subscriptions to `desired`, given which channel (if
     * any) is currently open. New ids are subscribed, ids no longer desired are
     * left. The open-channel page owns the *active* channel's subscription and
     * tears it down with a full leave on navigation — which also drops this
     * fleet's listener on it — so when the active channel changes the fleet
     * forgets the previous one (without leaving it) and re-subscribes it on this
     * pass if it is still desired.
     */
    reconcile: (
        desired: Iterable<string>,
        activeChannelId: string | null,
    ) => void;
    /** Leave every bound channel and forget them (component teardown). */
    leaveAll: () => void;
    /** The currently bound channel ids, for inspection and tests. */
    boundIds: () => string[];
}

/**
 * Create a channel fleet over the given subscribe/leave handlers.
 */
export function createChannelFleet(
    handlers: ChannelFleetHandlers,
): ChannelFleet {
    const bound = new Set<string>();
    let previousActiveId: string | null = null;

    function reconcile(
        desired: Iterable<string>,
        activeChannelId: string | null,
    ): void {
        const desiredSet = new Set(desired);

        if (previousActiveId !== null && previousActiveId !== activeChannelId) {
            bound.delete(previousActiveId);
        }

        previousActiveId = activeChannelId;

        for (const id of desiredSet) {
            if (!bound.has(id)) {
                handlers.subscribe(id);
                bound.add(id);
            }
        }

        for (const id of [...bound]) {
            if (!desiredSet.has(id)) {
                handlers.leave(id);
                bound.delete(id);
            }
        }
    }

    function leaveAll(): void {
        for (const id of bound) {
            handlers.leave(id);
        }

        bound.clear();
    }

    function boundIds(): string[] {
        return [...bound];
    }

    return { reconcile, leaveAll, boundIds };
}
