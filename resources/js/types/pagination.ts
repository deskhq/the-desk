/**
 * One page of a simply-paginated list: the rows, and the links either side.
 *
 * Simple (prev/next) pagination is what the admin logs use, so a log can be
 * paged through in full without a bounded cap and without counting the whole
 * table for a page number nobody reads. `App\Support\SimplePage` is the server
 * half; the two are one envelope, and this is the only place it is spelled.
 *
 * Cursor-paged surfaces (`MessagePage`, `ThreadInboxPage`) are a different
 * envelope for a different pagination mode and deliberately do not use this.
 */
export type SimplePage<T> = {
    data: T[];
    prevPageUrl: string | null;
    nextPageUrl: string | null;
};
