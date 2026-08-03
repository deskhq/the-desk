import type { SimplePage } from './pagination';

/**
 * A recorded security-relevant account event as shown on the Security settings
 * page.
 */
export type SecurityActivityEvent = App.Data.SecurityEventData;

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

/** One page of admin security-log events. */
export type SecurityEventsPage = SimplePage<TeamSecurityEvent>;
