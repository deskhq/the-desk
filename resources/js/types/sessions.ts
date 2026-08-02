/**
 * An active browser/device session as shown on the Security settings page.
 * `isCurrentDevice` marks the session the request is being made from, which
 * cannot be revoked.
 */
export type ActiveSession = App.Data.SessionData;
