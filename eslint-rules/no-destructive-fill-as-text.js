/**
 * Flags the `--destructive` *fill* token painted as text (`text-destructive`)
 * and auto-fixes it to `text-destructive-text`, the accessible text form.
 *
 * The fill token is tuned to carry `--destructive-foreground` on a solid button,
 * not to be read as ink: as inline text it measures 3.93:1 on the dark card and
 * 3.60:1 on the `bg-destructive/10` tint, below the 4.5:1 WCAG AA bar the theme
 * holds itself to (#678, #717). `--destructive-text` is the same hue tuned to
 * clear AA on every surface destructive copy lands on, so text, error captions,
 * status pills and the icons sitting beside them should all use it.
 *
 * `text-destructive-foreground` (ink *on* a solid destructive fill) and every
 * non-text utility (`bg-destructive/10`, `border-destructive/25`) are untouched.
 */

/**
 * Matches the `text-destructive` utility with any variant, `!` or opacity
 * modifier around it, but not the longer `text-destructive-text` /
 * `text-destructive-foreground` tokens, and not a utility that merely ends in
 * the same word (`context-destructive`).
 */
const DESTRUCTIVE_TEXT = /(?<![\w-])text-destructive(?![\w-])/g;

const SUGGESTION = 'text-destructive-text';

/**
 * @typedef {object} DestructiveTextMatch
 * @property {number} start Offset of the utility within `text`.
 * @property {string} original The flagged utility, always `text-destructive`.
 * @property {string} suggestion The accessible replacement.
 */

/**
 * Finds every use of the destructive *fill* token as a text colour in a class
 * string.
 *
 * @param {string} text
 * @returns {DestructiveTextMatch[]}
 */
export function findDestructiveTextUtilities(text) {
    return [...text.matchAll(DESTRUCTIVE_TEXT)].map((match) => ({
        start: match.index,
        original: match[0],
        suggestion: SUGGESTION,
    }));
}

/** @type {import('eslint').Rule.RuleModule} */
const rule = {
    meta: {
        type: 'problem',
        fixable: 'code',
        docs: {
            description:
                'Use the accessible `text-destructive-text` token instead of painting the `--destructive` fill token as text.',
        },
        schema: [],
        messages: {
            preferDestructiveText:
                "Use '{{ suggestion }}' instead of '{{ original }}'; the fill token drops below WCAG AA 4.5:1 as inline text on the dark card and on the `bg-destructive/10` tint.",
        },
    },
    create(context) {
        const sourceCode = context.sourceCode;

        const check = (node) => {
            const text = sourceCode.getText(node);

            for (const {
                start,
                original,
                suggestion,
            } of findDestructiveTextUtilities(text)) {
                const from = node.range[0] + start;
                const to = from + original.length;

                context.report({
                    node,
                    messageId: 'preferDestructiveText',
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
