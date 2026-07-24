/**
 * Flags text-entry form controls whose font size drops below 16px on a phone,
 * and auto-fixes them to the `text-base md:<size>` pattern the shared `Input`
 * primitive already uses.
 *
 * iOS Safari auto-zooms the whole page onto a focused `<input>`, `<textarea>`
 * or `<select>` whose computed `font-size` is under 16px, and does not zoom
 * back out when the keyboard closes: the app is left scaled up and horizontally
 * scrolled, with controls pushed off-screen (#855). Pinning the field to 16px
 * below `md` and restoring the design size from `md` up removes the trigger
 * without touching desktop typography — and without the `maximum-scale=1`
 * viewport hack, which would break pinch-to-zoom for everyone (WCAG 1.4.4).
 *
 * Only sizes that actually apply on a phone are considered: a utility behind a
 * min-width breakpoint (`md:text-sm`) or a state variant (`placeholder:`,
 * `file:`, `@lg:`) is left alone, and a field with no font size at all inherits
 * the 16px body size.
 */

/** Tailwind's named font-size scale, in pixels. */
const NAMED_SIZES = {
    xs: 12,
    sm: 14,
    base: 16,
    lg: 18,
    xl: 20,
    '2xl': 24,
    '3xl': 30,
    '4xl': 36,
    '5xl': 48,
    '6xl': 60,
    '7xl': 72,
    '8xl': 96,
    '9xl': 128,
};

/** Below this, iOS Safari zooms the page onto the focused field. */
const MINIMUM_MOBILE_FONT_PX = 16;

/** Root font size, for resolving `rem` / `em` arbitrary values. */
const ROOT_FONT_PX = 16;

/** Variants that still apply on a phone viewport (`max-md:` and friends). */
const MOBILE_VARIANT = /^max-(sm|md|lg|xl|2xl)$/;

/** Input types that open no keyboard, so never trigger the focus zoom. */
const NON_TEXT_INPUT_TYPES = new Set([
    'button',
    'checkbox',
    'color',
    'file',
    'hidden',
    'image',
    'radio',
    'range',
    'reset',
    'submit',
]);

/** Native elements the browser zooms onto when focused. */
const TEXT_ENTRY_ELEMENTS = new Set(['input', 'textarea', 'select']);

/**
 * Shared wrappers that render one of those elements, so a font size handed to
 * them lands on the control itself. `ListboxFilter` is the reka-ui primitive
 * `CommandInput` is built on.
 */
export const TEXT_ENTRY_COMPONENTS = new Set([
    'Input',
    'PasswordInput',
    'SidebarInput',
    'CommandInput',
    'NativeSelect',
    'Textarea',
    'ListboxFilter',
]);

/**
 * Splits a utility into its variants and its base utility, ignoring colons
 * nested inside an arbitrary value (`text-[length:14px]`).
 *
 * @param {string} utility
 * @returns {{ variants: string[], base: string }}
 */
function splitVariants(utility) {
    const parts = [];
    let depth = 0;
    let current = '';

    for (const character of utility) {
        if (character === '[' || character === '(') {
            depth += 1;
        } else if (character === ']' || character === ')') {
            depth -= 1;
        }

        if (character === ':' && depth === 0) {
            parts.push(current);
            current = '';
            continue;
        }

        current += character;
    }

    parts.push(current);

    return { variants: parts.slice(0, -1), base: parts.at(-1) };
}

/**
 * Resolves a Tailwind font-size utility to pixels, or `null` when the utility
 * is not a font size (a colour, an alignment, an unrelated class).
 *
 * @param {string} utility A base utility with its variants already stripped.
 * @returns {number|null}
 */
export function fontSizeInPixels(utility) {
    const match = /^!?text-(.+?)!?$/.exec(utility);

    if (!match) {
        return null;
    }

    const suffix = match[1];

    if (suffix in NAMED_SIZES) {
        return NAMED_SIZES[suffix];
    }

    const arbitrary = /^\[(?:length:)?(\d*\.?\d+)(px|rem|em)\]$/.exec(suffix);

    if (!arbitrary) {
        return null;
    }

    const [, value, unit] = arbitrary;

    return unit === 'px' ? Number(value) : Number(value) * ROOT_FONT_PX;
}

/**
 * @typedef {object} SmallMobileFont
 * @property {number} start Offset of the offending utility within `text`.
 * @property {string} original The utility that applies on a phone, e.g. `text-sm`.
 * @property {number} pixels Its resolved size.
 * @property {string} suggestion The replacement, e.g. `text-base md:text-sm`.
 */

/**
 * Finds the font size a class string lands on for a phone viewport, and returns
 * it only when that size would trigger the focus zoom. Later utilities win, so
 * `text-sm max-md:text-base` reads as 16px, the same way the cascade (and
 * `tailwind-merge`) resolves it.
 *
 * @param {string} text
 * @returns {SmallMobileFont|null}
 */
export function findSmallMobileFont(text) {
    let effective = null;
    let hasDesktopSize = false;

    for (const match of text.matchAll(/[^\s"'`]+/g)) {
        const { variants, base } = splitVariants(match[0]);
        const pixels = fontSizeInPixels(base);

        if (pixels === null) {
            continue;
        }

        if (variants.length > 0 && !variants.every((variant) => MOBILE_VARIANT.test(variant))) {
            hasDesktopSize = true;
            continue;
        }

        effective = { start: match.index, original: match[0], pixels };
    }

    if (!effective || effective.pixels >= MINIMUM_MOBILE_FONT_PX) {
        return null;
    }

    return {
        ...effective,
        suggestion: hasDesktopSize ? 'text-base' : `text-base md:${effective.original}`,
    };
}

/**
 * The value of a plain (non-bound) attribute, or `null` when it is absent or
 * bound to an expression.
 *
 * @param {import('vue-eslint-parser').AST.VElement} element
 * @param {string} name
 * @returns {string|null}
 */
function staticAttribute(element, name) {
    const attribute = element.startTag.attributes.find(
        (candidate) => !candidate.directive && candidate.key.name === name,
    );

    return attribute?.value?.value ?? null;
}

/**
 * Whether focusing this element would make a phone browser zoom the page.
 *
 * @param {import('vue-eslint-parser').AST.VElement} element
 * @returns {boolean}
 */
function isTextEntryElement(element) {
    const tag = element.rawName;

    if (TEXT_ENTRY_COMPONENTS.has(tag)) {
        return true;
    }

    if (!TEXT_ENTRY_ELEMENTS.has(tag.toLowerCase())) {
        return false;
    }

    const type = staticAttribute(element, 'type');

    return type === null || !NON_TEXT_INPUT_TYPES.has(type.toLowerCase());
}

/**
 * Collects every string literal in a subtree, so a bound `:class` is read the
 * same way as a static one.
 *
 * @param {unknown} node
 * @param {object[]} collected
 * @returns {object[]}
 */
function collectStringNodes(node, collected = []) {
    if (Array.isArray(node)) {
        for (const child of node) {
            collectStringNodes(child, collected);
        }

        return collected;
    }

    if (!node || typeof node !== 'object' || typeof node.type !== 'string') {
        return collected;
    }

    if (node.type === 'TemplateElement' || (node.type === 'Literal' && typeof node.value === 'string')) {
        collected.push(node);

        return collected;
    }

    for (const [key, value] of Object.entries(node)) {
        if (key === 'parent' || key === 'range' || key === 'loc') {
            continue;
        }

        collectStringNodes(value, collected);
    }

    return collected;
}

/**
 * The nodes holding class strings for an element: the static `class` attribute
 * and every string inside a bound `:class`.
 *
 * @param {import('vue-eslint-parser').AST.VElement} element
 * @returns {object[]}
 */
function classNodes(element) {
    return element.startTag.attributes.flatMap((attribute) => {
        if (!attribute.value) {
            return [];
        }

        if (!attribute.directive) {
            return attribute.key.name === 'class' ? [attribute.value] : [];
        }

        const isClassBinding =
            attribute.key.name.name === 'bind' && attribute.key.argument?.name === 'class';

        return isClassBinding ? collectStringNodes(attribute.value) : [];
    });
}

/** @type {import('eslint').Rule.RuleModule} */
const rule = {
    meta: {
        type: 'problem',
        fixable: 'code',
        docs: {
            description:
                'Keep text-entry controls at 16px or larger below the `md` breakpoint so focusing them does not zoom the page on iOS.',
        },
        schema: [],
        messages: {
            zoomsOnFocus:
                "'{{ original }}' resolves to {{ pixels }}px on a phone, so iOS Safari zooms the whole page onto this field when it is focused. Use '{{ suggestion }}' to keep 16px below `md` and the design size from `md` up.",
        },
    },
    create(context) {
        const sourceCode = context.sourceCode;
        const defineTemplateBodyVisitor = sourceCode.parserServices?.defineTemplateBodyVisitor;

        if (!defineTemplateBodyVisitor) {
            return {};
        }

        return defineTemplateBodyVisitor({
            VElement(element) {
                if (!isTextEntryElement(element)) {
                    return;
                }

                for (const node of classNodes(element)) {
                    const violation = findSmallMobileFont(sourceCode.getText(node));

                    if (!violation) {
                        continue;
                    }

                    const from = node.range[0] + violation.start;

                    context.report({
                        node,
                        messageId: 'zoomsOnFocus',
                        data: {
                            original: violation.original,
                            pixels: String(violation.pixels),
                            suggestion: violation.suggestion,
                        },
                        fix: (fixer) =>
                            fixer.replaceTextRange(
                                [from, from + violation.original.length],
                                violation.suggestion,
                            ),
                    });
                }
            },
        });
    },
};

export default rule;
