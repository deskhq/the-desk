/**
 * A recorded security-relevant account event as shown on the Security settings
 * page. Mirrors the `App\Data\SecurityEventData` DTO. `isNewDevice` flags a
 * sign-in from an IP and browser not seen on a prior sign-in.
 */
export type SecurityActivityEvent = {
    id: string;
    type: string;
    label: string;
    ipAddress: string | null;
    browser: string;
    platform: string;
    isNewDevice: boolean;
    occurredAt: string;
};

/**
 * A security event as shown in a workspace's admin security log. Generated from
 * the `App\Data\TeamSecurityEventData` DTO. The live membership join guarantees
 * the acting member exists, so `actorName` is always present.
 */
export type TeamSecurityEvent = App.Data.TeamSecurityEventData;

export type SecurityEventTypeOption = {
    value: string;
    label: string;
};

export type SecurityLogActor = {
    id: string;
    name: string;
};

/**
 * One page of admin security-log events. Uses simple (prev/next) pagination so
 * the log can be paged through in full without a bounded cap.
 */
export type SecurityEventsPage = {
    data: TeamSecurityEvent[];
    prevPageUrl: string | null;
    nextPageUrl: string | null;
};
