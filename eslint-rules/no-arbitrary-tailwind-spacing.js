/**
 * Flags arbitrary pixel utilities (e.g. `size-[38px]`) on the Tailwind spacing
 * scale that have an exact non-arbitrary equivalent (`size-9.5`), and auto-fixes
 * them. Tailwind's spacing step is `0.25rem` (4px) with fractional multipliers,
 * so any integer-pixel arbitrary value on a spacing-scale utility maps cleanly to
 * `value / 4` (e.g. `19px` -> `4.75`, `1px` -> `px`). Off-scale utilities such as
 * `rounded-[10px]` or `text-[10.5px]` use a different scale and are left untouched.
 */

/**
 * Spacing-scale utility prefixes whose arbitrary pixel values map onto the
 * numeric spacing scale. Deliberately excludes `max-w`/`max-h`/`basis`, whose
 * arbitrary layout dimensions read better as explicit pixels than large
 * spacing multipliers.
 */
const SPACING_PREFIXES = [
    'size',
    'min-w',
    'min-h',
    'w',
    'h',
    'space-x',
    'space-y',
    'gap-x',
    'gap-y',
    'gap',
    'inset-x',
    'inset-y',
    'inset',
    'translate-x',
    'translate-y',
    'scroll-mx',
    'scroll-my',
    'scroll-mt',
    'scroll-mr',
    'scroll-mb',
    'scroll-ml',
    'scroll-m',
    'scroll-px',
    'scroll-py',
    'scroll-pt',
    'scroll-pr',
    'scroll-pb',
    'scroll-pl',
    'scroll-p',
    'px',
    'py',
    'pt',
    'pr',
    'pb',
    'pl',
    'ps',
    'pe',
    'p',
    'mx',
    'my',
    'mt',
    'mr',
    'mb',
    'ml',
    'ms',
    'me',
    'm',
    'top',
    'right',
    'bottom',
    'left',
    'start',
    'end',
    'indent',
];

// Longest-first so alternation prefers `min-h` over `h`, `scroll-mt` over `m`, etc.
const prefixAlternation = [...SPACING_PREFIXES]
    .sort((a, b) => b.length - a.length)
    .join('|');

const ARBITRARY_PX = new RegExp(
    `(^|[\\s"'\`])((?:[\\w-]+:)*!?-?)(${prefixAlternation})-\\[(\\d+)px\\]`,
    'g',
);

/**
 * Converts an integer pixel value to its Tailwind spacing-scale suffix.
 *
 * @param {number} px
 * @returns {string}
 */
export function scaleSuffix(px) {
    if (px === 1) {
        return 'px';
    }

    return String(px / 4);
}

/**
 * @typedef {object} SpacingReplacement
 * @property {number} start Offset of the arbitrary utility within `text`.
 * @property {string} original The arbitrary utility, e.g. `size-[38px]`.
 * @property {string} suggestion The scale equivalent, e.g. `size-9.5`.
 */

/**
 * Finds every arbitrary pixel spacing utility in a class string that has an
 * exact Tailwind scale equivalent.
 *
 * @param {string} text
 * @returns {SpacingReplacement[]}
 */
export function findSpacingReplacements(text) {
    const replacements = [];

    for (const match of text.matchAll(ARBITRARY_PX)) {
        const [, boundary, variant, prefix, pixels] = match;

        replacements.push({
            start: match.index + boundary.length + variant.length,
            original: `${prefix}-[${pixels}px]`,
            suggestion: `${prefix}-${scaleSuffix(Number(pixels))}`,
        });
    }

    return replacements;
}

/** @type {import('eslint').Rule.RuleModule} */
const rule = {
    meta: {
        type: 'suggestion',
        fixable: 'code',
        docs: {
            description:
                'Prefer the Tailwind spacing scale over arbitrary pixel utilities that have an exact equivalent.',
        },
        schema: [],
        messages: {
            preferScale:
                "Prefer '{{ suggestion }}' over the arbitrary '{{ original }}'; it has an exact Tailwind spacing-scale equivalent.",
        },
    },
    create(context) {
        const sourceCode = context.sourceCode;

        const check = (node) => {
            const text = sourceCode.getText(node);

            for (const { start, original, suggestion } of findSpacingReplacements(text)) {
                const from = node.range[0] + start;
                const to = from + original.length;

                context.report({
                    node,
                    messageId: 'preferScale',
                    data: { original, suggestion },
                    fix: (fixer) => fixer.replaceTextRange([from, to], suggestion),
                });
            }
        };

        const scriptVisitor = { Literal: check, TemplateElement: check };
        const defineTemplateBodyVisitor =
            sourceCode.parserServices?.defineTemplateBodyVisitor;

        if (defineTemplateBodyVisitor) {
            return defineTemplateBodyVisitor(
                { VLiteral: check, Literal: check, TemplateElement: check },
                scriptVisitor,
            );
        }

        return scriptVisitor;
    },
};

export default rule;
