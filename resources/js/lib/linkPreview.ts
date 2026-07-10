/**
 * The display host for a link preview: the URL's hostname with a leading `www.`
 * stripped (e.g. `https://www.example.com/x` -> `example.com`). Falls back to the
 * raw string when the URL can't be parsed, so a malformed link still shows
 * something rather than throwing.
 */
export function previewHost(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}
