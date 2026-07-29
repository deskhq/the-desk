import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import type { AuthorOverride } from '@/types';

/**
 * The one rule these compact author surfaces exist to keep: *an overridden name
 * may only render where its bot marker renders with it*. Each of them hands the
 * client a bare name string with nothing else marking it non-human, so a webhook
 * putting a human's name into a quote or an attribution is exactly the hole this
 * pins shut.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { customEmojis: {}, userGroups: [] } }),
}));

const { default: MessageQuote } = await import('./MessageQuote.vue');
const { default: MessageForward } = await import('./MessageForward.vue');

const OVERRIDE: AuthorOverride = { name: 'Ada Lovelace', avatar: null };

async function render(
    component: unknown,
    props: Record<string, unknown>,
): Promise<string> {
    const app = createSSRApp({
        render: () => h(component as never, props),
    });

    app.config.globalProperties.$t = (key: string) => key;

    return renderToString(app);
}

function quote(props: Record<string, unknown> = {}): Promise<string> {
    return render(MessageQuote, {
        authorName: 'Deploy Bot',
        body: 'shipped',
        isDeleted: false,
        ...props,
    });
}

function forward(props: Record<string, unknown> = {}): Promise<string> {
    return render(MessageForward, {
        authorName: 'Deploy Bot',
        channelName: 'deploys',
        body: 'shipped',
        isDeleted: false,
        mentions: [],
        ...props,
    });
}

describe('a reply quote', () => {
    it('shows the true author name unbadged for a human', async () => {
        const html = await quote({ authorName: 'Ada Lovelace' });

        expect(html).toContain('Ada Lovelace');
        expect(html).not.toContain('data-test="author-bot-badge"');
    });

    it('badges a bot author', async () => {
        const html = await quote({ authorIsBot: true });

        expect(html).toContain('data-test="author-bot-badge"');
    });

    it('badges an overridden name even when the bot flag never arrived', async () => {
        const html = await quote({ authorOverride: OVERRIDE });

        // The override replaces the name rather than sitting beside it.
        expect(html).toContain('Ada Lovelace');
        expect(html).not.toContain('Deploy Bot');
        expect(html).toContain('data-test="author-bot-badge"');
    });

    it('never names the author of a deleted parent', async () => {
        const html = await quote({ authorOverride: OVERRIDE, isDeleted: true });

        expect(html).not.toContain('Ada Lovelace');
        expect(html).not.toContain('Deploy Bot');
    });
});

describe('a forward attribution', () => {
    it('shows the true author name unbadged for a human', async () => {
        const html = await forward({ authorName: 'Ada Lovelace' });

        expect(html).toContain('Ada Lovelace');
        expect(html).not.toContain('data-test="author-bot-badge"');
    });

    it('badges an overridden name even when the bot flag never arrived', async () => {
        const html = await forward({ authorOverride: OVERRIDE });

        expect(html).toContain('Ada Lovelace');
        expect(html).not.toContain('Deploy Bot');
        expect(html).toContain('data-test="author-bot-badge"');
    });
});
