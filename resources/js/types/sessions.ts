/**
 * An active browser/device session as shown on the Security settings page.
 * Mirrors the `App\Data\SessionData` DTO. `isCurrentDevice` marks the session
 * the request is being made from, which cannot be revoked.
 */
export type ActiveSession = {
    id: string;
    ipAddress: string | null;
    browser: string;
    platform: string;
    lastActive: string;
    isCurrentDevice: boolean;
};
