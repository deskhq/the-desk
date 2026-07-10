import { describe, expect, it } from 'vitest';
import { createChannelFleet } from '@/lib/channelFleet';

/**
 * Drive a fleet with spy handlers, recording the sequence of subscribe/leave
 * calls so a test can assert exactly which channels were touched and when.
 */
function trackedFleet() {
    const subscribed: string[] = [];
    const left: string[] = [];
    const fleet = createChannelFleet({
        subscribe: (id) => subscribed.push(id),
        leave: (id) => left.push(id),
    });

    return { fleet, subscribed, left };
}

describe('createChannelFleet', () => {
    it('subscribes each desired channel once and reports them as bound', () => {
        const { fleet, subscribed } = trackedFleet();

        fleet.reconcile(['a', 'b'], null);
        fleet.reconcile(['a', 'b'], null);

        expect(subscribed).toEqual(['a', 'b']);
        expect(fleet.boundIds()).toEqual(['a', 'b']);
    });

    it('subscribes a newly added channel without re-subscribing the rest', () => {
        const { fleet, subscribed } = trackedFleet();

        fleet.reconcile(['a'], null);
        fleet.reconcile(['a', 'b'], null);

        expect(subscribed).toEqual(['a', 'b']);
    });

    it('leaves a channel that drops out of the desired set', () => {
        const { fleet, subscribed, left } = trackedFleet();

        fleet.reconcile(['a', 'b'], null);
        fleet.reconcile(['a'], null);

        expect(subscribed).toEqual(['a', 'b']);
        expect(left).toEqual(['b']);
        expect(fleet.boundIds()).toEqual(['a']);
    });

    it('re-subscribes the just-left active channel on handoff without leaving it itself', () => {
        // The open-channel page owns the active channel's subscription and tears
        // it down with a full leave on navigation, which also drops the fleet's
        // listener on it. On the next reconcile the fleet must re-attach the
        // channel it just moved away from — and must never call leave on it,
        // since the page already did.
        const { fleet, subscribed, left } = trackedFleet();

        fleet.reconcile(['a', 'b'], 'a');
        fleet.reconcile(['a', 'b'], 'b');

        expect(subscribed).toEqual(['a', 'b', 'a']);
        expect(left).toEqual([]);
        expect(fleet.boundIds()).toEqual(['b', 'a']);
    });

    it('leaves every bound channel on teardown and forgets them', () => {
        const { fleet, left } = trackedFleet();

        fleet.reconcile(['a', 'b'], null);
        fleet.leaveAll();

        expect(left).toEqual(['a', 'b']);
        expect(fleet.boundIds()).toEqual([]);
    });
});
