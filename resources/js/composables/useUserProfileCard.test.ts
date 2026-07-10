import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchUserProfile } from './useUserProfileCard';

// Stub the Wayfinder route so the composable's logic is tested in isolation.
vi.mock('@/routes/teams/members', () => ({
    card: (args: [string, string]) => ({ url: `/card/${args[0]}/${args[1]}` }),
}));

function mockFetchOnce(profile: unknown, ok = true): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockResolvedValue({
        ok,
        json: () => Promise.resolve(profile),
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('fetchUserProfile', () => {
    it('fetches a profile and memoises it per user', async () => {
        const profile = { id: 'u1', name: 'Ada' };
        const fetchMock = mockFetchOnce(profile);

        const first = await fetchUserProfile('acme', 'u1');
        const second = await fetchUserProfile('acme', 'u1');

        expect(first).toEqual(profile);
        expect(second).toEqual(profile);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('returns null when the response is not ok', async () => {
        mockFetchOnce(null, false);

        expect(await fetchUserProfile('acme', 'gone')).toBeNull();
    });

    it('returns null when the request throws', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network')));

        expect(await fetchUserProfile('acme', 'boom')).toBeNull();
    });
});
