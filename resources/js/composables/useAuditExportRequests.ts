import { router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { backgroundVisit } from '@/lib/backgroundVisit';
import { store } from '@/routes/teams/audit-exports';
import type { AuditExport } from '@/types';

export interface AuditExportRequestsOptions {
    /** The workspace the exports belong to. */
    teamSlug: () => string;
    /** The exports the server last listed for it. */
    exports: () => AuditExport[];
}

export interface AuditExportRequests {
    /** Whether a request is in flight, so the form can hold its button down. */
    submitting: Ref<boolean>;
    /** Whether a file is still being built, which blocks a second request. */
    hasPending: ComputedRef<boolean>;
    /** Ask for one log, in one format, over one period. */
    requestExport: (
        logType: string,
        format: string,
        start: string | null,
        end: string | null,
    ) => void;
    /** Ask again for exactly what a failed export asked for. */
    retryExport: (entry: AuditExport) => void;
}

/**
 * Everything on the Exports page that talks to the server: the request itself,
 * the retry that repeats a failed one, and the poll that keeps a generating row
 * moving. One export builds at a time, so `hasPending` is what the whole page
 * gates on — the submit button, the retry buttons and the poll alike.
 */
export function useAuditExportRequests(
    options: AuditExportRequestsOptions,
): AuditExportRequests {
    const submitting = ref(false);

    const hasPending = computed(() =>
        options.exports().some((entry) => entry.status === 'pending'),
    );

    function requestExport(
        logType: string,
        format: string,
        start: string | null,
        end: string | null,
    ): void {
        router.post(
            store(options.teamSlug()).url,
            {
                log_type: logType,
                format,
                range_start: start,
                range_end: end,
            },
            {
                preserveScroll: true,
                onStart: () => {
                    submitting.value = true;
                },
                onFinish: () => {
                    submitting.value = false;
                },
            },
        );
    }

    function retryExport(entry: AuditExport): void {
        if (hasPending.value) {
            return;
        }

        requestExport(
            entry.logType,
            entry.format,
            entry.rangeStart,
            entry.rangeEnd,
        );
    }

    // Poll for readiness while any export is still generating, matching the
    // Mailable with an in-page refresh; the request is a no-op once nothing pends.
    let pollTimer: ReturnType<typeof setInterval> | undefined;

    onMounted(() => {
        pollTimer = setInterval(() => {
            if (hasPending.value) {
                // A timer tick, not a click; see {@see backgroundVisit}.
                router.reload({ ...backgroundVisit, only: ['exports'] });
            }
        }, 4000);
    });

    onUnmounted(() => {
        if (pollTimer) {
            clearInterval(pollTimer);
        }
    });

    return { submitting, hasPending, requestExport, retryExport };
}
