import { vi } from 'vitest';
import type { Component } from 'vue';
import { defineComponent, h } from 'vue';
import type { UploadHandle } from '@/lib/uploadAttachment';

/**
 * What the phone-layout composer suite's `vi.mock` factories return.
 *
 * Deliberately a leaf: those factories are hoisted above every import, so they
 * can only reach a module by importing it dynamically — and if that module
 * pulled in the composer, resolving the mock would wait on a module graph that
 * is itself waiting on the mock. Only the type import above touches the app,
 * and types are erased. The mount helpers live in
 * MessageComposer.mobile.doubles.ts, which the suite imports normally.
 */

export type PendingUpload = {
    resolve: (value: unknown) => void;
    reject: (reason: unknown) => void;
    progress: (percent: number) => void;
};

/** Every upload started so far, newest last, each settleable by hand. */
export const pendingUploads: PendingUpload[] = [];

/** Whether the faked browser reports it can record at all. */
export const recordingSupported = { value: true };

export function fakeUpload(
    _endpoint: string,
    _file: File,
    onProgress: (percent: number) => void,
): UploadHandle {
    let resolve!: (value: unknown) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    pendingUploads.push({ resolve, reject, progress: onProgress });

    return { promise: promise as Promise<never>, abort: vi.fn() };
}

/** Renders its default slot into a plain element, forwarding attributes. */
function stub(name: string, tag: string): Component {
    return defineComponent({
        name,
        inheritAttrs: false,
        setup:
            (_props, { attrs, slots }) =>
            () =>
                h(tag, attrs, slots.default?.()),
    });
}

/** The same, but rendering nothing at all unless it is `open`. */
function gatedStub(name: string): Component {
    return defineComponent({
        name,
        inheritAttrs: false,
        props: { open: Boolean },
        setup:
            (props, { attrs, slots }) =>
            () =>
                props.open ? h('div', attrs, slots.default?.()) : null,
    });
}

/**
 * The dialog primitive down to something that honours `open` and nothing else:
 * its portal, focus trap and drag are its own tested concern, and none of them
 * survive jsdom intact anyway.
 */
export function dialogStubs(): Record<string, Component> {
    return {
        Dialog: gatedStub('DialogStub'),
        DialogContent: stub('DialogContentStub', 'div'),
        DialogDescription: stub('DialogDescriptionStub', 'p'),
        DialogTitle: stub('DialogTitleStub', 'h2'),
    };
}

export function buttonStub(): Record<string, Component> {
    return { Button: stub('ButtonStub', 'button') };
}

export function tooltipStubs(): Record<string, Component> {
    return {
        Tooltip: stub('TooltipStub', 'div'),
        TooltipContent: stub('TooltipContentStub', 'div'),
        TooltipProvider: stub('TooltipProviderStub', 'div'),
        TooltipTrigger: stub('TooltipTriggerStub', 'div'),
    };
}
