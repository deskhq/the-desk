import { parseXsrfToken } from '@/lib/uploadAttachment';
import type { AttachmentData } from '@/types/attachments';

/**
 * Fetch a page of GIFs (trending or search) from the server-side Giphy proxy.
 * The endpoint returns the normalized {@see App.Data.GiphySearchData} shape — the
 * client never touches the Giphy API or key directly. An `AbortSignal` lets a
 * superseded search (the user kept typing) be cancelled.
 */
export async function fetchGiphyPage(
    url: string,
    signal?: AbortSignal,
): Promise<App.Data.GiphySearchData> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal,
    });

    if (!response.ok) {
        throw new Error('giphy-search-failed');
    }

    return (await response.json()) as App.Data.GiphySearchData;
}

/**
 * Attach a chosen GIF by its opaque Giphy id: the server re-resolves the id (the
 * sole authority on the stored URL) and creates a pending remote attachment,
 * returning its DTO. Sends the CSRF header the stateful `web` guard expects.
 */
export async function attachGiphyGif(
    url: string,
    id: string,
): Promise<AttachmentData> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = parseXsrfToken(document.cookie);

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    const response = await fetch(url, {
        method: 'POST',
        headers,
        body: JSON.stringify({ id }),
    });

    if (!response.ok) {
        throw new Error('giphy-attach-failed');
    }

    return (await response.json()) as AttachmentData;
}
