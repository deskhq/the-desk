import type { AttachmentData } from '@/types/attachments';

/** A single in-flight upload: its eventual result and a way to cancel it. */
export interface UploadHandle {
    /** Resolves with the stored attachment, or rejects with an {@see UploadFailure}. */
    promise: Promise<AttachmentData>;
    /** Cancel the request (e.g. the user removed the row before it finished). */
    abort: () => void;
}

/** Why an upload did not produce an attachment. */
export interface UploadFailure {
    /** The HTTP status, or 0 for a network error or an abort. */
    status: number;
    /**
     * A user-facing validation message from the server (a rejected file type or
     * an over-limit size), or null for a generic transport failure that should
     * surface as a retryable failed row rather than a toast.
     */
    message: string | null;
    /** Whether the failure was a deliberate {@see UploadHandle.abort}. */
    aborted: boolean;
}

/** Starts an upload of `file` to `url`, reporting 0–100% progress as it streams. */
export type Uploader = (
    url: string,
    file: File,
    onProgress: (percent: number) => void,
) => UploadHandle;

/**
 * Pull the Laravel `XSRF-TOKEN` (URL-encoded) out of a cookie string. Exposed so
 * the pure parse is testable without a DOM; {@see xhrUpload} feeds it
 * `document.cookie`.
 */
export function parseXsrfToken(cookie: string): string | null {
    const match = cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Extract a user-facing validation message from a failed response. Only a 422
 * (the upload endpoint's rejected-type / over-size path) yields one; every other
 * status returns null so the caller shows a retryable failed row instead.
 */
function validationMessage(status: number, response: unknown): string | null {
    if (status !== 422 || typeof response !== 'object' || response === null) {
        return null;
    }

    const body = response as {
        message?: unknown;
        errors?: { file?: unknown };
    };
    const fileErrors = body.errors?.file;

    if (Array.isArray(fileErrors) && fileErrors.length > 0) {
        return String(fileErrors[0]);
    }

    return typeof body.message === 'string' ? body.message : null;
}

/**
 * The default {@see Uploader}: a bare `XMLHttpRequest` so we get real per-file
 * upload progress (which `fetch` can't report) and a handle to abort a removed
 * row. Sends the file as multipart with the CSRF header the stateful `web`
 * guard expects. The composable injects a fake in tests, so this browser glue
 * stays thin.
 */
export const xhrUpload: Uploader = (url, file, onProgress) => {
    const xhr = new XMLHttpRequest();

    const promise = new Promise<AttachmentData>((resolve, reject) => {
        const form = new FormData();
        form.append('file', file);

        xhr.open('POST', url);
        xhr.responseType = 'json';
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        const token = parseXsrfToken(document.cookie);

        if (token) {
            xhr.setRequestHeader('X-XSRF-TOKEN', token);
        }

        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        });

        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(xhr.response as AttachmentData);

                return;
            }

            reject({
                status: xhr.status,
                message: validationMessage(xhr.status, xhr.response),
                aborted: false,
            } satisfies UploadFailure);
        });

        xhr.addEventListener('error', () => {
            reject({
                status: 0,
                message: null,
                aborted: false,
            } satisfies UploadFailure);
        });

        xhr.addEventListener('abort', () => {
            reject({
                status: 0,
                message: null,
                aborted: true,
            } satisfies UploadFailure);
        });

        xhr.send(form);
    });

    return { promise, abort: () => xhr.abort() };
};
