import { describe, expect, it } from 'vitest';
import { channelCacheTag, prefetchTrigger } from '@/lib/prefetch';

describe('the prefetch cache tag', () => {
    it('names the channel it caches, so an arrival can flush exactly that entry', () => {
        expect(channelCacheTag('9f1c-abcd')).toBe('channel:9f1c-abcd');
    });

    it('gives two channels two different tags', () => {
        expect(channelCacheTag('one')).not.toBe(channelCacheTag('two'));
    });
});

describe('the prefetch trigger', () => {
    it('buys the travel-to-click time on a device that can hover', () => {
        expect(prefetchTrigger(true)).toBe('hover');
    });

    it('falls back to the mousedown-to-mouseup window where nothing hovers', () => {
        expect(prefetchTrigger(false)).toBe('click');
    });

    /**
     * `['hover', 'click']` renders as `if hover → hoverEvents; else if click →
     * clickEvents`, so the click half is silently dropped. The mode is chosen
     * once, and it is never an array.
     */
    it('never asks for both modes at once', () => {
        expect(prefetchTrigger(true)).not.toBeInstanceOf(Array);
        expect(prefetchTrigger(false)).not.toBeInstanceOf(Array);
    });
});
