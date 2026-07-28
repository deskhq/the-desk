import { ref } from 'vue';
import type { Ref } from 'vue';

export interface PaneFileDropOptions {
    /** Whether a drop would be staged at all (a member of a live channel). */
    canDrop: () => boolean;
    /** Where the dropped files go. */
    onFiles: (files: File[]) => void;
}

export interface PaneFileDrop {
    /** Whether the brass drop overlay is showing. */
    isDraggingFiles: Ref<boolean>;
    canDropAttachments: () => boolean;
    onPaneDragEnter: (event: DragEvent) => void;
    onPaneDragOver: (event: DragEvent) => void;
    onPaneDragLeave: () => void;
    onPaneDrop: (event: DragEvent) => void;
}

/** Whether a drag actually carries files (vs. selected text or an element). */
function dragCarriesFiles(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

/**
 * Whole-pane drag-and-drop: dropping files anywhere over the channel content
 * stages them in the composer tray. A depth counter tracks nested
 * dragenter/dragleave so the brass overlay doesn't flicker as the pointer
 * crosses child elements. Only meaningful where the composer itself is shown.
 */
export function usePaneFileDrop(options: PaneFileDropOptions): PaneFileDrop {
    const isDraggingFiles = ref(false);
    let dragDepth = 0;

    function canDropAttachments(): boolean {
        return options.canDrop();
    }

    function onPaneDragEnter(event: DragEvent): void {
        if (!canDropAttachments() || !dragCarriesFiles(event)) {
            return;
        }

        dragDepth += 1;
        isDraggingFiles.value = true;
    }

    function onPaneDragOver(event: DragEvent): void {
        if (!dragCarriesFiles(event)) {
            return;
        }

        // Claim every file drag so the browser never navigates to the dropped file,
        // even where we won't accept it (archived channel, non-member).
        event.preventDefault();

        if (!canDropAttachments()) {
            return;
        }

        // Show the copy cursor only where the drop will actually be staged.
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'copy';
        }
    }

    function onPaneDragLeave(): void {
        if (!isDraggingFiles.value) {
            return;
        }

        dragDepth -= 1;

        if (dragDepth <= 0) {
            dragDepth = 0;
            isDraggingFiles.value = false;
        }
    }

    function onPaneDrop(event: DragEvent): void {
        dragDepth = 0;
        isDraggingFiles.value = false;

        if (!dragCarriesFiles(event)) {
            return;
        }

        // Prevent the browser navigating to the dropped file for every file drag,
        // then only stage the files where the channel actually accepts them.
        event.preventDefault();

        if (!canDropAttachments()) {
            return;
        }

        const files = Array.from(event.dataTransfer?.files ?? []);

        if (files.length > 0) {
            options.onFiles(files);
        }
    }

    return {
        isDraggingFiles,
        canDropAttachments,
        onPaneDragEnter,
        onPaneDragOver,
        onPaneDragLeave,
        onPaneDrop,
    };
}
