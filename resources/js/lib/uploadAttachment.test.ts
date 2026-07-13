import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { parseXsrfToken, xhrUpload } from '@/lib/uploadAttachment';
import type { UploadFailure } from '@/lib/uploadAttachment';

describe('parseXsrfToken', () => {
    it('extracts and URL-decodes the XSRF-TOKEN cookie', () => {
        expect(parseXsrfToken('XSRF-TOKEN=abc%3D%3D')).toBe('abc==');
    });

    it('finds the token among other cookies', () => {
        expect(
            parseXsrfToken('foo=1; XSRF-TOKEN=tok3n; laravel_session=xyz'),
        ).toBe('tok3n');
    });

    it('returns null when no token cookie is present', () => {
        expect(parseXsrfToken('foo=1; bar=2')).toBeNull();
        expect(parseXsrfToken('')).toBeNull();
    });
});

/** A minimal XMLHttpRequest stand-in whose events the test drives by hand. */
class FakeXhr {
    static instances: FakeXhr[] = [];

    status = 0;
    response: unknown = null;
    responseType = '';
    method = '';
    url = '';
    sent: unknown = null;
    headers: Record<string, string> = {};
    private listeners: Record<string, ((event?: unknown) => void)[]> = {};
    upload = {
        listeners: {} as Record<string, ((event?: unknown) => void)[]>,
        addEventListener(type: string, cb: (event?: unknown) => void) {
            (this.listeners[type] ??= []).push(cb);
        },
    };

    constructor() {
        FakeXhr.instances.push(this);
    }

    open(method: string, url: string): void {
        this.method = method;
        this.url = url;
    }

    setRequestHeader(key: string, value: string): void {
        this.headers[key] = value;
    }

    addEventListener(type: string, cb: (event?: unknown) => void): void {
        (this.listeners[type] ??= []).push(cb);
    }

    send(body: unknown): void {
        this.sent = body;
    }

    abort(): void {
        this.emit('abort');
    }

    emit(type: string, event?: unknown): void {
        (this.listeners[type] ?? []).forEach((cb) => cb(event));
    }

    emitUpload(type: string, event?: unknown): void {
        (this.upload.listeners[type] ?? []).forEach((cb) => cb(event));
    }
}

describe('xhrUpload', () => {
    beforeEach(() => {
        FakeXhr.instances = [];
        vi.stubGlobal('XMLHttpRequest', FakeXhr);
        vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=tok3n' });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    /** The XHR the last {@see xhrUpload} call created. */
    function lastXhr(): FakeXhr {
        return FakeXhr.instances[FakeXhr.instances.length - 1];
    }

    function upload() {
        const onProgress = vi.fn();
        const handle = xhrUpload(
            '/t/acme/c/design/attachments',
            new File(['x'], 'a.png', { type: 'image/png' }),
            onProgress,
        );

        return { handle, onProgress, xhr: lastXhr() };
    }

    it('posts multipart with the CSRF header and resolves the DTO on 2xx', async () => {
        const dto = { id: 'att-1', filename: 'a.png' };
        const { handle, xhr } = upload();

        expect(xhr.method).toBe('POST');
        expect(xhr.headers['X-XSRF-TOKEN']).toBe('tok3n');
        expect(xhr.sent).toBeInstanceOf(FormData);

        xhr.status = 201;
        xhr.response = dto;
        xhr.emit('load');

        await expect(handle.promise).resolves.toEqual(dto);
    });

    it('omits the CSRF header when no cookie is present', () => {
        vi.stubGlobal('document', { cookie: '' });
        const { xhr } = upload();

        expect(xhr.headers['X-XSRF-TOKEN']).toBeUndefined();
    });

    it('reports streaming progress as a rounded percentage', () => {
        const { onProgress, xhr } = upload();

        xhr.emitUpload('progress', {
            lengthComputable: true,
            loaded: 1,
            total: 3,
        });
        expect(onProgress).toHaveBeenCalledWith(33);

        // A non-computable event is ignored (no total to divide by).
        xhr.emitUpload('progress', {
            lengthComputable: false,
            loaded: 1,
            total: 0,
        });
        expect(onProgress).toHaveBeenCalledTimes(1);
    });

    it('rejects a 422 with the field validation message', async () => {
        const { handle, xhr } = upload();

        xhr.status = 422;
        xhr.response = { errors: { file: ['This file type is not allowed.'] } };
        xhr.emit('load');

        await expect(handle.promise).rejects.toMatchObject({
            status: 422,
            message: 'This file type is not allowed.',
            aborted: false,
        } satisfies UploadFailure);
    });

    it('falls back to the top-level message when a 422 has no field errors', async () => {
        const { handle, xhr } = upload();

        xhr.status = 422;
        xhr.response = { message: 'The file failed validation.' };
        xhr.emit('load');

        await expect(handle.promise).rejects.toMatchObject({
            status: 422,
            message: 'The file failed validation.',
        });
    });

    it('carries no message for a 422 with neither errors nor a message', async () => {
        const { handle, xhr } = upload();

        xhr.status = 422;
        xhr.response = {};
        xhr.emit('load');

        await expect(handle.promise).rejects.toMatchObject({
            status: 422,
            message: null,
        });
    });

    it('rejects a non-validation error status with a null message', async () => {
        const { handle, xhr } = upload();

        xhr.status = 500;
        xhr.response = { message: 'Server error' };
        xhr.emit('load');

        await expect(handle.promise).rejects.toMatchObject({
            status: 500,
            message: null,
            aborted: false,
        });
    });

    it('rejects a network error as a generic transport failure', async () => {
        const { handle, xhr } = upload();

        xhr.emit('error');

        await expect(handle.promise).rejects.toMatchObject({
            status: 0,
            message: null,
            aborted: false,
        });
    });

    it('rejects as aborted when the handle is cancelled', async () => {
        const { handle } = upload();

        handle.abort();

        await expect(handle.promise).rejects.toMatchObject({
            status: 0,
            message: null,
            aborted: true,
        });
    });
});
