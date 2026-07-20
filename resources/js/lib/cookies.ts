/**
 * Build the assignment string for a cookie the frontend writes itself.
 *
 * Only the two cookies excluded from Laravel's cookie encryption are written
 * this way (`appearance` and `sidebar_state`), and neither carries anything
 * sensitive. They still get the transport flags the server-set cookies get:
 * `SameSite=Lax`, so they are not sent on cross-site subrequests, and `Secure`
 * on an HTTPS page, so the browser never sends them back over plain HTTP.
 * Exposed separately from {@see writeClientCookie} so the string is testable
 * without a DOM.
 */
export function buildClientCookie(
    name: string,
    value: string,
    maxAgeSeconds: number,
    secure: boolean,
): string {
    const attributes = [
        `${name}=${encodeURIComponent(value)}`,
        'path=/',
        `max-age=${maxAgeSeconds}`,
        'SameSite=Lax',
    ];

    if (secure) {
        attributes.push('Secure');
    }

    return attributes.join('; ');
}

/**
 * Persist a client-written cookie, marking it `Secure` whenever the page itself
 * was served over HTTPS. No-ops outside the browser (server-side rendering).
 */
export function writeClientCookie(
    name: string,
    value: string,
    maxAgeSeconds: number,
): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.cookie = buildClientCookie(
        name,
        value,
        maxAgeSeconds,
        window.location.protocol === 'https:',
    );
}
