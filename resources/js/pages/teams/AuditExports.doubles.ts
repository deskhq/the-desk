import type { AuditExport, AuditExportOption, Team } from '@/types';

/**
 * Fixtures shared by the two Exports-page suites. Written against the page
 * before it was split, so both suites staying green across the move is the
 * proof nothing changed (#993).
 */
export const team: Team = {
    id: 't-1',
    name: 'Acme Corp',
    slug: 'acme',
    isPersonal: false,
    role: 'owner',
    membersCount: 12,
};

export const logTypeOptions: AuditExportOption[] = [
    { value: 'audit', label: 'Audit log' },
    { value: 'security', label: 'Security events' },
];

export const formatOptions: AuditExportOption[] = [
    { value: 'csv', label: 'CSV' },
    { value: 'json', label: 'JSON' },
];

/** A ready-to-download audit-log export, overridable field by field. */
export function auditExport(overrides: Partial<AuditExport> = {}): AuditExport {
    return {
        id: 'exp-1',
        logType: 'audit',
        logTypeLabel: 'Audit log',
        format: 'csv',
        formatLabel: 'CSV',
        status: 'completed',
        statusLabel: 'Completed',
        isReady: true,
        isExpired: false,
        rangeStart: null,
        rangeEnd: null,
        requestedByName: 'Ada Lovelace',
        requestedAt: '2026-03-04T10:30:00+00:00',
        expiresAt: '2026-03-11T10:30:00+00:00',
        ...overrides,
    };
}

/** The element carrying a `data-test` selector, as the browser tests find it. */
export function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}
