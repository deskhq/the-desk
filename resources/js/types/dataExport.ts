/**
 * The authenticated user's most recent personal-data export, as shown in the
 * "Data & privacy" section of the profile settings page. Mirrors the
 * `App\Data\DataExportData` DTO. `isReady` is true only while the archive is
 * built and still inside its download window.
 */
export type DataExport = {
    id: string;
    status: string;
    statusLabel: string;
    isReady: boolean;
    requestedAt: string;
    expiresAt: string | null;
};
