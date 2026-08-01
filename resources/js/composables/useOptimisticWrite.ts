import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import type { useMessageStream } from '@/composables/useMessageStream';
import { useToast } from '@/composables/useToast';
import { backgroundVisit } from '@/lib/backgroundVisit';

type MessageStream = ReturnType<typeof useMessageStream>;

/**
 * How to put back exactly what a capture read, when the server refuses the
 * write it was taken for.
 */
export type Rollback = () => void;

/** The verbs an optimistic write posts under. */
export type OptimisticMethod = 'post' | 'patch' | 'delete';

/**
 * A value an optimistic write's payload may carry.
 *
 * Structurally Inertia's own `FormDataConvertible`, spelled out here because
 * that type lives in `@inertiajs/core` — a package this app reaches only
 * through `@inertiajs/vue3`, which does not re-export it. Files are left out on
 * purpose: an upload is not something to show before the server has taken it.
 */
type PayloadValue =
    | string
    | number
    | boolean
    | null
    | undefined
    | PayloadValue[]
    | { [key: string]: PayloadValue };

/** The request body an optimistic write posts. */
export type OptimisticPayload = Record<string, PayloadValue>;

/** One optimistic write: what it shows, what it posts, and what it undoes. */
export interface OptimisticWrite {
    /**
     * Read whatever {@link apply} is about to overwrite, and hand back how to
     * put it back. Run before the mutation, so the closure always closes over
     * the pre-write value — the module owns that ordering rather than trusting
     * each call site to get it right.
     */
    capture: () => Rollback;
    /**
     * Show the outcome the reader asked for, ahead of the server's answer.
     *
     * Optional, because one shape of optimistic write has already happened: a
     * vuedraggable handler fires *after* the drag has written to the list it is
     * bound to, so that site captures and restores but applies nothing.
     */
    apply?: () => void;
    /**
     * What the write is *for*, read once before the write and again before the
     * rollback: whatever this returns identifies the thing on screen the
     * mutation belongs to, and the rollback runs only while the two still
     * agree.
     *
     * A background write can outlive the surface that fired it. Star `#general`,
     * navigate to `#random`, and let the star be refused: the refs the rollback
     * closed over now hold `#random`'s preferences, and restoring `#general`'s
     * snapshot over them shows the reader a channel's state they have left
     * (#1120). Passing `subject: options.channelId` makes that restore a no-op.
     *
     * Capturing the identity here rather than taking a ready-made predicate is
     * the same call as capturing strictly before {@link apply}: a call site
     * asked to snapshot its own subject first is a call site that can forget to.
     *
     * Omit it for a write whose subject cannot change identity underneath it —
     * a message row keyed on its own uuid, a sidebar that reseeds from props.
     */
    subject?: () => unknown;
    /** Defaults to `post`. */
    method?: OptimisticMethod;
    url: string;
    /**
     * The request body. Ignored by `delete`, which sends none.
     *
     * A function is read *after* {@link apply}, for the endpoints that take the
     * value the mutation just decided — a star toggle posts the new star state,
     * so spelling it out up front would say it twice.
     */
    data?: OptimisticPayload | (() => OptimisticPayload);
    /** The props the write invalidates — one of the named sets in `lib/reloadProps`. */
    only?: string[];
    /**
     * Whether the page component's state survives the response. True by
     * default: an optimistic write is not a navigation, so the surface that
     * fired it should still be there afterwards. A write that deliberately
     * replaces the whole prop set passes false.
     */
    preserveState?: boolean;
    /** True for a write nobody on screen is waiting on; see {@see backgroundVisit}. */
    background?: boolean;
    /**
     * What the reader is told when the write is refused, already translated.
     * A function receives the server's validation errors, for the endpoints that
     * refuse with a sentence of their own (the per-channel pin cap).
     */
    failure: string | ((errors: Record<string, string>) => string);
    /** Runs on a successful write, for the writes that also confirm themselves. */
    onSuccess?: (page: unknown) => void;
}

export interface OptimisticWriter {
    /** Run one optimistic write end to end. */
    write: (spec: OptimisticWrite) => void;
}

/**
 * The optimistic write, as one module.
 *
 * Capture → apply → post → restore-on-error → toast is the shape behind roughly
 * a dozen writes across the sidebar, the timeline and the thread panel: a star,
 * a vote, a reaction, an edit, a pin, a drag. Every one of them mutates the
 * interface before the server has agreed, which means every one of them owes the
 * reader a way back if the server refuses — and a written-out copy of the shape
 * is a copy that can quietly lose its rollback, leaving the interface showing
 * something the account never stored.
 *
 * So the ordering (snapshot strictly before the mutation), the visit options an
 * optimistic write always wants (`preserveScroll`, `preserveState` — it is not a
 * navigation), the restore and the failure toast live here, and a call site
 * supplies only what actually differs: what to snapshot, what to show, where to
 * post, which props that invalidates, and what to say when it fails.
 *
 * {@see useReminders} is the reference adopter, and the one that named the
 * lesson first: set and snooze are the same write, so their triple was written
 * once there rather than at each call site (#1115).
 *
 * Writes with *nothing* to roll back — a post that only toasts on failure — do
 * not belong here. They would gain uniformity and nothing else, and the line
 * this module draws is behavioural.
 */
export function useOptimisticWrite(): OptimisticWriter {
    const toast = useToast();

    function write(spec: OptimisticWrite): void {
        const subject = spec.subject?.();
        const rollback = spec.capture();
        spec.apply?.();

        // A refusal that arrives after its subject has left the screen has
        // nothing left to undo: the state the rollback closed over now belongs
        // to whatever took its place. See {@link OptimisticWrite.subject}.
        const stillRelevant = (): boolean =>
            spec.subject === undefined || Object.is(spec.subject(), subject);

        const options = {
            ...(spec.background ? backgroundVisit : {}),
            preserveScroll: true,
            preserveState: spec.preserveState ?? true,
            ...(spec.only ? { only: spec.only } : {}),
            ...(spec.onSuccess ? { onSuccess: spec.onSuccess } : {}),
            onError: (errors: Record<string, string>): void => {
                if (!stillRelevant()) {
                    return;
                }

                rollback();
                toast.error(
                    typeof spec.failure === 'function'
                        ? spec.failure(errors)
                        : spec.failure,
                );
            },
        };

        // `router.delete` takes its options in the second slot: it sends no body.
        if (spec.method === 'delete') {
            router.delete(spec.url, options);

            return;
        }

        const data = typeof spec.data === 'function' ? spec.data() : spec.data;

        router[spec.method ?? 'post'](spec.url, data ?? {}, options);
    }

    return { write };
}

/**
 * Capture a ref's current value, restoring exactly that value.
 *
 * The single-ref case — a star, a mute, a notification level, a collapsed set —
 * where the optimistic mutation is an assignment and its inverse is the
 * assignment back.
 */
export function snapshotRef<T>(target: Ref<T>): Rollback {
    const previous = target.value;

    return () => {
        target.value = previous;
    };
}

/**
 * Capture one message row across every stream showing it, restoring all of them
 * together.
 *
 * A message can be on screen twice at once — in the channel timeline and in the
 * open thread panel — over two independent {@see useMessageStream} instances, so
 * a pin, a vote, a reaction, an edit or a delete patches both and a refusal has
 * to undo both. Restoring one and not the other is the failure this exists to
 * make unwritable: the same message would render pinned in the timeline and
 * unpinned in the thread beside it.
 *
 * The row is keyed on its client uuid, which is what a stream files patches
 * under; `undefined` for a stream that carried no patch is a meaningful
 * snapshot, and restores to carrying none.
 */
export function snapshotStreams(
    clientUuid: string,
    ...streams: MessageStream[]
): Rollback {
    const previous = streams.map((stream) => stream.getPatch(clientUuid));

    return () => {
        streams.forEach((stream, index) => {
            stream.restorePatch(clientUuid, previous[index]);
        });
    };
}
