import { describe, expect, it } from 'vitest';
import { dmParticipantPresence, presenceLabelKey } from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';

const roster: Record<string, RenderedPresence> = {
    maya: 'away',
    jonas: 'active',
};

const presenceFor = (id: string): RenderedPresence => roster[id] ?? 'offline';

describe('dmParticipantPresence', () => {
    it('reads the other participant off the roster', () => {
        expect(dmParticipantPresence('maya', presenceFor, 'active')).toBe(
            'away',
        );
    });

    it('falls back to the viewer in a self-DM, which has no other participant', () => {
        expect(dmParticipantPresence(null, presenceFor, 'away')).toBe('away');
        expect(dmParticipantPresence(undefined, presenceFor, 'active')).toBe(
            'active',
        );
    });

    it('still reports a participant who has left the roster as offline', () => {
        expect(dmParticipantPresence('gone', presenceFor, 'active')).toBe(
            'offline',
        );
    });
});

describe('presenceLabelKey', () => {
    it('names each state in English, for the caller to translate', () => {
        expect(presenceLabelKey('active')).toBe('Active');
        expect(presenceLabelKey('away')).toBe('Away');
        expect(presenceLabelKey('offline')).toBe('Offline');
    });
});
