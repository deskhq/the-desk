import { describe, expect, it } from 'vitest';
import { planForward } from '@/lib/forwardPlacement';
import type { ForwardTarget } from '@/types/forward';

/** A public channel the forward may or may not target. */
const channel = { id: 'chan-1', isDirect: false, name: 'general' };

/** The current viewer's self-DM, standing in for any direct-message source. */
const dm = { id: 'dm-1', isDirect: true, name: 'Ada Lovelace' };

const toChannel = (id: string, name = 'somewhere'): ForwardTarget => ({
    kind: 'channel',
    id,
    name,
});
const toUser = (id: string, name = 'Grace'): ForwardTarget => ({
    kind: 'user',
    id,
    name,
});

describe('planForward', () => {
    it('flags a forward back into the current channel so it renders optimistically', () => {
        const plan = planForward({ target: toChannel('chan-1'), channel });

        expect(plan.toCurrentChannel).toBe(true);
        expect(plan.destination).toEqual({ target_channel_id: 'chan-1' });
    });

    it('does not flag a forward into a different channel', () => {
        const plan = planForward({ target: toChannel('chan-2'), channel });

        expect(plan.toCurrentChannel).toBe(false);
        expect(plan.destination).toEqual({ target_channel_id: 'chan-2' });
    });

    it('routes a person target to their DM and never treats it as the current channel', () => {
        const plan = planForward({ target: toUser('user-9'), channel });

        expect(plan.toCurrentChannel).toBe(false);
        expect(plan.destination).toEqual({ target_user_id: 'user-9' });
    });

    it('quotes a channel source by its name so the reference reads "#general"', () => {
        const plan = planForward({ target: toChannel('chan-2'), channel });

        expect(plan.quoteChannelName).toBe('general');
    });

    it('quotes a DM source with a null channel name so it reads "a direct message"', () => {
        const plan = planForward({ target: toChannel('chan-2'), channel: dm });

        expect(plan.quoteChannelName).toBeNull();
    });
});
