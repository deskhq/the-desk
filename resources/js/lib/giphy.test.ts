import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { attachGiphyGif, fetchGiphyPage } from '@/lib/giphy';

describe('fetchGiphyPage', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('returns the parsed page for a successful response', async () => {
        const page = {
            results: [
                {
                    id: 'a',
                    url: 'u',
                    previewUrl: 'p',
                    width: 1,
                    height: 1,
                    description: null,
                },
            ],
            nextOffset: 24,
        };
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(page),
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetchGiphyPage('/gifs?q=cats')).resolves.toEqual(page);
        expect(fetchMock).toHaveBeenCalledWith('/gifs?q=cats', {
            headers: { Accept: 'application/json' },
            signal: undefined,
        });
    });

    it('throws when the response is not ok', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));

        await expect(fetchGiphyPage('/gifs')).rejects.toThrow(
            'giphy-search-failed',
        );
    });
});

describe('attachGiphyGif', () => {
    beforeEach(() => {
        vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=tok%20en' });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts the id with the CSRF header and returns the attachment', async () => {
        const attachment = { id: 'att-1', source: 'giphy' };
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(attachment),
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(attachGiphyGif('/gifs', 'giphy-id')).resolves.toEqual(
            attachment,
        );

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/gifs');
        expect(init.method).toBe('POST');
        expect(init.body).toBe(JSON.stringify({ id: 'giphy-id' }));
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok en');
        expect(init.headers['Content-Type']).toBe('application/json');
    });

    it('throws when the attach response is not ok', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));

        await expect(attachGiphyGif('/gifs', 'x')).rejects.toThrow(
            'giphy-attach-failed',
        );
    });
});
