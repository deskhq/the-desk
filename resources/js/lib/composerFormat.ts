/**
 * The result of toggling an inline mark on a textarea's value: the new text and
 * where the selection should sit afterwards, so the caller can restore it.
 */
export type InlineMarkToggle = {
    value: string;
    selectionStart: number;
    selectionEnd: number;
};

/**
 * Whether `text` carries the marker at the boundary adjacent to the selection
 * (its end for the text before, its start for the text after) as an *exact*
 * run — not as part of a longer run of the same character. This keeps a `*`
 * (italic) toggle from mistaking the inner `*` of a surrounding `**` (bold) for
 * its own marker and stripping one star off each fence.
 */
function hasExactBoundaryMarker(
    text: string,
    marker: string,
    atEnd: boolean,
): boolean {
    const markerChar = marker[marker.length - 1];

    if (atEnd) {
        if (!text.endsWith(marker)) {
            return false;
        }

        return text.charAt(text.length - marker.length - 1) !== markerChar;
    }

    if (!text.startsWith(marker)) {
        return false;
    }

    return text.charAt(marker.length) !== markerChar;
}

/**
 * Wrap (or unwrap) the current textarea selection in a Markdown inline marker,
 * returning the new value and selection. Pure so the composer's formatting
 * buttons and keyboard shortcuts share one tested behaviour:
 *
 * - A non-empty selection is wrapped and the inner text stays selected, so
 *   pressing the same shortcut again toggles the wrap straight back off.
 * - An empty selection inserts the paired markers with the caret between them.
 * - A selection already wrapped by an exact marker pair (the markers sit just
 *   outside it) is unwrapped, keeping the inner text selected.
 */
export function toggleInlineMark(
    value: string,
    selectionStart: number,
    selectionEnd: number,
    marker: string,
): InlineMarkToggle {
    const selected = value.slice(selectionStart, selectionEnd);
    const before = value.slice(0, selectionStart);
    const after = value.slice(selectionEnd);

    if (
        hasExactBoundaryMarker(before, marker, true) &&
        hasExactBoundaryMarker(after, marker, false)
    ) {
        return {
            value:
                before.slice(0, before.length - marker.length) +
                selected +
                after.slice(marker.length),
            selectionStart: selectionStart - marker.length,
            selectionEnd: selectionEnd - marker.length,
        };
    }

    return {
        value: before + marker + selected + marker + after,
        selectionStart: selectionStart + marker.length,
        selectionEnd: selectionEnd + marker.length,
    };
}
