import { describe, expect, it } from 'vitest';
import { canCreateChannel, creatableVisibilities } from '@/lib/channelCreation';

describe('the visibilities the picker offers', () => {
    it('offers them in the picker’s order, whatever order the policy answered in', () => {
        expect(creatableVisibilities(['private', 'public'])).toEqual([
            'public',
            'private',
        ]);
    });

    it('drops the visibility the policy withheld', () => {
        expect(creatableVisibilities(['private'])).toEqual(['private']);
    });

    it('offers nothing before the workspace props have landed', () => {
        expect(creatableVisibilities(undefined)).toEqual([]);
    });
});

describe('whether the viewer may create a channel at all', () => {
    it('says yes while the policy leaves any visibility open', () => {
        expect(canCreateChannel(['private'])).toBe(true);
    });

    it('says no when the policy shut the viewer out of both', () => {
        expect(canCreateChannel([])).toBe(false);
    });

    it('says no on a page carrying no workspace props', () => {
        expect(canCreateChannel(undefined)).toBe(false);
    });
});
