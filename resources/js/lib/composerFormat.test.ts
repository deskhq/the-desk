import { describe, expect, it } from 'vitest';
import { toggleInlineMark } from '@/lib/composerFormat';

describe('toggleInlineMark', () => {
    it('wraps the selection and reselects the inner text', () => {
        // "needs a [final pass] today" → bold, inner text kept selected.
        const value = 'needs a final pass today';
        const start = value.indexOf('final pass');
        const end = start + 'final pass'.length;

        expect(toggleInlineMark(value, start, end, '**')).toEqual({
            value: 'needs a **final pass** today',
            selectionStart: start + 2,
            selectionEnd: end + 2,
        });
    });

    it('inserts empty markers with the caret between them on an empty selection', () => {
        const value = 'needs a  today';
        const caret = 'needs a '.length;

        expect(toggleInlineMark(value, caret, caret, '**')).toEqual({
            value: 'needs a **** today',
            selectionStart: caret + 2,
            selectionEnd: caret + 2,
        });
    });

    it('toggles the wrap back off when the selection is already wrapped', () => {
        const value = 'needs a **final pass** today';
        const start = value.indexOf('final pass');
        const end = start + 'final pass'.length;

        expect(toggleInlineMark(value, start, end, '**')).toEqual({
            value: 'needs a final pass today',
            selectionStart: start - 2,
            selectionEnd: end - 2,
        });
    });

    it('uses single-character markers for italic and inline code', () => {
        expect(toggleInlineMark('a b c', 2, 3, '*')).toEqual({
            value: 'a *b* c',
            selectionStart: 3,
            selectionEnd: 4,
        });

        expect(toggleInlineMark('a b c', 2, 3, '`')).toEqual({
            value: 'a `b` c',
            selectionStart: 3,
            selectionEnd: 4,
        });
    });

    it('uses the two-character strikethrough marker', () => {
        expect(toggleInlineMark('a b c', 2, 3, '~~')).toEqual({
            value: 'a ~~b~~ c',
            selectionStart: 4,
            selectionEnd: 5,
        });
    });

    it('wraps with italic inside bold instead of stripping the bold markers', () => {
        // "**[bold]**" italicized: the inner `*` of each `**` must not be
        // mistaken for the italic marker and unwrapped.
        expect(toggleInlineMark('**bold**', 2, 6, '*')).toEqual({
            value: '***bold***',
            selectionStart: 3,
            selectionEnd: 7,
        });
    });
});
