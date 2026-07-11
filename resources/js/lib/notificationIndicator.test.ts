import { AtSign, BellMinus, BellOff } from '@lucide/vue';
import { describe, expect, it } from 'vitest';
import { notificationIndicator } from '@/lib/notificationIndicator';

describe('notificationIndicator', () => {
    it('shows nothing for an unmuted channel at the default "all" level', () => {
        expect(notificationIndicator(false, 'all')).toBeNull();
    });

    it('flags the "mentions only" level with the at-sign icon', () => {
        expect(notificationIndicator(false, 'mentions')).toEqual({
            icon: AtSign,
            label: 'Mentions only',
            status: 'mentions',
        });
    });

    it('flags the "nothing" level with the muted-bell icon', () => {
        expect(notificationIndicator(false, 'nothing')).toEqual({
            icon: BellMinus,
            label: 'Notifications off',
            status: 'nothing',
        });
    });

    it('flags a muted channel with the bell-off icon', () => {
        expect(notificationIndicator(true, 'all')).toEqual({
            icon: BellOff,
            label: 'Muted',
            status: 'muted',
        });
    });

    it('lets mute win over the notification level', () => {
        expect(notificationIndicator(true, 'mentions')).toEqual({
            icon: BellOff,
            label: 'Muted',
            status: 'muted',
        });
    });
});
