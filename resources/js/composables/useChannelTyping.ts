import { echo } from '@laravel/echo-vue';
import { typing as postTypingSignal } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { useTypingIndicator } from '@/composables/useTypingIndicator';
import { parseXsrfToken } from '@/lib/uploadAttachment';

export interface ChannelTypingOptions {
    teamSlug: () => string;
    channelSlug: () => string;
}

/**
 * Peers currently composing on this channel, driven by the server-broadcast
 * `UserTyping` event on the same private channel as the message events. The
 * outbound signal is a plain fire-and-forget POST — the server derives the
 * typist identity from the authenticated session, so a member cannot spoof
 * another member's indicator — carrying the Echo socket id so the resulting
 * broadcast skips this tab.
 */
export function useChannelTyping(
    options: ChannelTypingOptions,
): ReturnType<typeof useTypingIndicator> {
    return useTypingIndicator(() => {
        const headers: Record<string, string> = {
            'X-Requested-With': 'XMLHttpRequest',
        };

        const xsrfToken = parseXsrfToken(document.cookie);

        if (xsrfToken) {
            headers['X-XSRF-TOKEN'] = xsrfToken;
        }

        const socketId = echo().socketId();

        if (socketId) {
            headers['X-Socket-ID'] = socketId;
        }

        void fetch(
            postTypingSignal({
                team: options.teamSlug(),
                channel: options.channelSlug(),
            }).url,
            { method: 'POST', headers },
        ).catch(() => {
            // A lost typing beat is invisible; the next keystroke sends another.
        });
    });
}
