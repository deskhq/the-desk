import type { MaybeRefOrGetter, Ref } from 'vue';
import { onBeforeUnmount, readonly, ref, toValue, watch } from 'vue';

const ELLIPSIS = '…';

/**
 * Keep `text` fitting on a single line of `element`, truncating it with an
 * ellipsis when it would overflow (and wrap). Built for the composer
 * placeholder (#802): textarea placeholders wrap by default and
 * `::placeholder` overflow styling is inconsistent across browsers, so the
 * string itself is truncated instead, via canvas text metrics matching the
 * element's computed font.
 *
 * Falls back to the full text whenever it cannot measure (no element yet, no
 * canvas 2D context, zero width) and re-fits when the text changes, when the
 * element resizes, and once webfonts finish loading.
 */
export function useEllipsizedText(
    element: Ref<HTMLElement | null>,
    text: MaybeRefOrGetter<string>,
): Readonly<Ref<string>> {
    const ellipsized = ref(toValue(text));

    function update(): void {
        ellipsized.value = fitToElement(element.value, toValue(text));
    }

    const observer =
        typeof ResizeObserver === 'undefined'
            ? null
            : new ResizeObserver(update);

    watch(
        [element, (): string => toValue(text)],
        () => {
            observer?.disconnect();

            if (element.value) {
                observer?.observe(element.value);
            }

            update();
        },
        { immediate: true },
    );

    if (typeof document !== 'undefined') {
        document.fonts?.ready.then(update);
    }

    onBeforeUnmount(() => observer?.disconnect());

    return readonly(ellipsized);
}

function fitToElement(element: HTMLElement | null, text: string): string {
    if (!element) {
        return text;
    }

    const style = window.getComputedStyle(element);
    const available =
        element.clientWidth -
        (Number.parseFloat(style.paddingLeft) || 0) -
        (Number.parseFloat(style.paddingRight) || 0);

    if (available <= 0) {
        return text;
    }

    const context = document.createElement('canvas').getContext('2d');

    if (!context) {
        return text;
    }

    context.font =
        style.font ||
        `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;

    return ellipsizeToWidth(
        text,
        available,
        (candidate) => context.measureText(candidate).width,
    );
}

/**
 * Fit `text` on a single line of at most `maxWidth` units, truncating with an
 * ellipsis when it overflows. `measure` returns the rendered width of a
 * candidate string; the search is over code points so surrogate pairs (emoji
 * in a recipient or channel name) are never split.
 */
export function ellipsizeToWidth(
    text: string,
    maxWidth: number,
    measure: (candidate: string) => number,
): string {
    if (measure(text) <= maxWidth) {
        return text;
    }

    const codePoints = [...text];
    const candidate = (length: number): string =>
        codePoints.slice(0, length).join('').trimEnd() + ELLIPSIS;

    let low = 0;
    let high = codePoints.length - 1;

    while (low < high) {
        const mid = Math.ceil((low + high) / 2);

        if (measure(candidate(mid)) <= maxWidth) {
            low = mid;
        } else {
            high = mid - 1;
        }
    }

    return candidate(low);
}
