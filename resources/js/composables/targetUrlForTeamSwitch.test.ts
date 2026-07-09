import { describe, expect, it } from 'vitest';
import { targetUrlForTeamSwitch } from '@/composables/useTeamSwitch';

describe('targetUrlForTeamSwitch', () => {
    it('drops a channel path with no guaranteed counterpart and lands on the target team index', () => {
        expect(
            targetUrlForTeamSwitch('/t/acme/c/random', 'acme', 'globex'),
        ).toBe('/t/globex');
    });

    it('drops a channel path even when it carries a query or hash', () => {
        expect(
            targetUrlForTeamSwitch(
                '/t/acme/c/random?highlight=5#msg-1',
                'acme',
                'globex',
            ),
        ).toBe('/t/globex');
    });

    it('rewrites the bare workspace index to the target team', () => {
        expect(targetUrlForTeamSwitch('/t/acme', 'acme', 'globex')).toBe(
            '/t/globex',
        );
    });

    it('preserves team-level routes that exist in every workspace', () => {
        expect(
            targetUrlForTeamSwitch('/t/acme/channels/browse', 'acme', 'globex'),
        ).toBe('/t/globex/channels/browse');
        expect(
            targetUrlForTeamSwitch('/t/acme/search?q=hi', 'acme', 'globex'),
        ).toBe('/t/globex/search?q=hi');
    });

    it('preserves a query string on the workspace index', () => {
        expect(
            targetUrlForTeamSwitch('/t/acme?tab=all', 'acme', 'globex'),
        ).toBe('/t/globex?tab=all');
    });

    it('returns null when not on a workspace page for the previous team', () => {
        expect(
            targetUrlForTeamSwitch('/settings/profile', 'acme', 'globex'),
        ).toBeNull();
    });

    it('does not treat a slug that is merely a prefix of the current one as a match', () => {
        expect(
            targetUrlForTeamSwitch('/t/acme-corp/c/random', 'acme', 'globex'),
        ).toBeNull();
    });
});
