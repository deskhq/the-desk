/**
 * Flags `<InputError>` placed directly by a page or component, steering every
 * field onto `<FormField>` (label + control + error decided in one place) or,
 * where a field cannot take that shape, onto `<FieldError>`.
 *
 * The two placements are not interchangeable: `<FieldError>` reserves the
 * message's space up front and draws the message out of flow inside it, so an
 * error cannot grow the form around it (#883). An `<InputError>` dropped
 * straight into a column joins the flow instead, so the same field behaves
 * differently depending on which page it is on (#894) — and that is the
 * difference that gets copied into the next form somebody builds.
 *
 * `FieldError.vue` is exempt: it is the one place `<InputError>` is positioned,
 * which is precisely what the rule exists to guarantee everywhere else.
 */

const OWNER_FILE = 'components/FieldError.vue';

/**
 * Whether a file is exempt from the rule: the reserved-slot wrapper that owns
 * the app's only direct `<InputError>` placement.
 *
 * @param {string} filename
 * @returns {boolean}
 */
export function isErrorSlotOwner(filename) {
    return filename.replaceAll('\\', '/').endsWith(OWNER_FILE);
}

/**
 * Whether a tag name refers to the `<InputError>` component. Vue resolves a
 * PascalCase component from a kebab-case tag too, so `<input-error>` is the
 * same placement written differently and has to be caught the same way.
 *
 * @param {string} rawName
 * @returns {boolean}
 */
export function isInputErrorTag(rawName) {
    return rawName.replaceAll('-', '').toLowerCase() === 'inputerror';
}

/** @type {import('eslint').Rule.RuleModule} */
const rule = {
    meta: {
        type: 'suggestion',
        docs: {
            description:
                'Place a field error through `<FormField>` or `<FieldError>` rather than dropping `<InputError>` into the page.',
        },
        schema: [],
        messages: {
            preferFieldError:
                'Render this error through `<FormField>` (`@/components/FormField.vue`), or `<FieldError>` (`@/components/FieldError.vue`) where the field cannot take that shape. Both reserve the space for the message and draw it out of flow, so an error cannot shift the form; a bare `<InputError>` joins the flow instead.',
        },
    },
    create(context) {
        if (isErrorSlotOwner(context.filename)) {
            return {};
        }

        const defineTemplateBodyVisitor =
            context.sourceCode.parserServices?.defineTemplateBodyVisitor;

        if (!defineTemplateBodyVisitor) {
            return {};
        }

        return defineTemplateBodyVisitor({
            VElement(node) {
                if (!isInputErrorTag(node.rawName)) {
                    return;
                }

                context.report({
                    node: node.startTag,
                    messageId: 'preferFieldError',
                });
            },
        });
    },
};

export default rule;
