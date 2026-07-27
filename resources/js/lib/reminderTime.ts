/**
 * Pure time math for the "remind me about this" picker. Mirrors {@see ./scheduleTime}
 * but with the reminder-specific quick presets from the design: three fixed
 * offsets (20 min / 1 hour / 3 hours) plus two wall-clock anchors (tomorrow &
 * next Monday, both at 9am in the viewer's zone). Kept free of Vue and DOM so it
 * can be unit-tested in isolation; `now` is always injectable for determinism.
 */

import { hourCycleFor } from './clock';
import { formatTimeOfDay } from './datetime';
import { i18n, translate } from './i18n';
import { wallTimeToInstant, zonedDayDiff, zonedWallTime } from './scheduleTime';
import type { WallTime } from './scheduleTime';

/** A quick-pick option offered in the reminder popover. */
export type ReminderPreset = {
    key: string;
    /** Translatable label, e.g. "In 20 minutes" or "Tomorrow". */
    label: string;
    /** Localized secondary time, e.g. "9:00 AM"; absent for the fixed offsets. */
    detail?: string;
    /** The chosen instant as a UTC ISO 8601 string. */
    remindAt: string;
};

const MS_PER_MINUTE = 60_000;
const MS_PER_HOUR = 3_600_000;

/**
 * Shift a civil date (calendar day, no zone) by whole days, wrapping months and
 * years correctly. Used to name "tomorrow" and "next Monday" as calendar days.
 */
function addCivilDays(
    date: Pick<WallTime, 'year' | 'month' | 'day'>,
    days: number,
): Pick<WallTime, 'year' | 'month' | 'day'> {
    const shifted = new Date(Date.UTC(date.year, date.month - 1, date.day));
    shifted.setUTCDate(shifted.getUTCDate() + days);

    return {
        year: shifted.getUTCFullYear(),
        month: shifted.getUTCMonth() + 1,
        day: shifted.getUTCDate(),
    };
}

/**
 * The day-of-week (0 = Sunday … 6 = Saturday) of a civil date.
 */
function civilWeekday(date: Pick<WallTime, 'year' | 'month' | 'day'>): number {
    return new Date(Date.UTC(date.year, date.month - 1, date.day)).getUTCDay();
}

/**
 * The 9am-on-a-day instant, formatted as its localized time (e.g. "9:00 AM").
 */
function timeDetail(iso: string, timeZone: string): string {
    return new Date(iso).toLocaleTimeString(i18n.locale, {
        hour: 'numeric',
        minute: '2-digit',
        hourCycle: hourCycleFor(),
        timeZone,
    });
}

/**
 * The weekday + time of an instant, formatted for the "Next week" detail
 * (e.g. "Mon, 9:00 AM").
 */
function weekdayTimeDetail(iso: string, timeZone: string): string {
    const target = new Date(iso);
    const weekday = target.toLocaleDateString(i18n.locale, {
        weekday: 'short',
        timeZone,
    });

    return `${weekday}, ${timeDetail(iso, timeZone)}`;
}

/**
 * The quick-pick presets to offer, computed against the viewer's zone and the
 * current instant. The first three are pure offsets; "Tomorrow" and "Next week"
 * are wall-clock 9am anchors resolved to instants in the viewer's zone.
 */
export function reminderPresets(
    timeZone: string,
    now: Date = new Date(),
): ReminderPreset[] {
    const wall = zonedWallTime(timeZone, now);

    const tomorrow = addCivilDays(wall, 1);
    const tomorrowAt9 = wallTimeToInstant(
        { ...tomorrow, hour: 9, minute: 0 },
        timeZone,
    ).toISOString();

    // Next Monday (1). `|| 7` makes "on a Monday" resolve to the following one.
    const daysUntilMonday = (((1 - civilWeekday(wall)) % 7) + 7) % 7 || 7;
    const nextMonday = addCivilDays(wall, daysUntilMonday);
    const nextMondayAt9 = wallTimeToInstant(
        { ...nextMonday, hour: 9, minute: 0 },
        timeZone,
    ).toISOString();

    return [
        {
            key: 'in-20-minutes',
            label: 'In 20 minutes',
            remindAt: new Date(
                now.getTime() + 20 * MS_PER_MINUTE,
            ).toISOString(),
        },
        {
            key: 'in-1-hour',
            label: 'In 1 hour',
            remindAt: new Date(now.getTime() + MS_PER_HOUR).toISOString(),
        },
        {
            key: 'in-3-hours',
            label: 'In 3 hours',
            remindAt: new Date(now.getTime() + 3 * MS_PER_HOUR).toISOString(),
        },
        {
            key: 'tomorrow',
            label: 'Tomorrow',
            detail: timeDetail(tomorrowAt9, timeZone),
            remindAt: tomorrowAt9,
        },
        {
            key: 'next-week',
            label: 'Next week',
            detail: weekdayTimeDetail(nextMondayAt9, timeZone),
            remindAt: nextMondayAt9,
        },
    ];
}

/**
 * Whether a reminder's instant falls on the same calendar day (in the viewer's
 * zone) as `now`, so the pending list can split rows into "Today" and "Later".
 */
export function isReminderToday(
    iso: string,
    timeZone: string,
    now: Date = new Date(),
): boolean {
    const target = zonedWallTime(timeZone, new Date(iso));
    const current = zonedWallTime(timeZone, now);

    return (
        target.year === current.year &&
        target.month === current.month &&
        target.day === current.day
    );
}

/**
 * The buckets the reminders panel groups pending rows into, in the order it
 * stacks them.
 */
export const REMINDER_GROUPS = [
    'overdue',
    'today',
    'this-week',
    'later',
] as const;

export type ReminderGroupKey = (typeof REMINDER_GROUPS)[number];

/**
 * The English source string labelling each bucket, translated where it renders.
 */
export const REMINDER_GROUP_LABELS: Record<ReminderGroupKey, string> = {
    overdue: 'Overdue',
    today: 'Today',
    'this-week': 'This week',
    later: 'Later',
};

/**
 * How far ahead "this week" reaches. A rolling six days rather than "before next
 * Monday": a reminder set for Monday afternoon on a Friday belongs with the
 * near-term ones, and a calendar week would file it under "Later".
 */
const THIS_WEEK_DAYS = 6;

/**
 * Which bucket a reminder falls into, in the viewer's zone.
 *
 * Overdue is derived from the instant rather than the calendar day — a reminder
 * set for 09:00 is overdue by lunchtime, on the very day it is due — so the
 * comparison happens before the day arithmetic.
 */
export function reminderGroup(
    iso: string,
    timeZone: string,
    now: Date = new Date(),
): ReminderGroupKey {
    const target = new Date(iso);

    if (target.getTime() < now.getTime()) {
        return 'overdue';
    }

    const dayDiff = zonedDayDiff(now, target, timeZone);

    if (dayDiff === 0) {
        return 'today';
    }

    return dayDiff <= THIS_WEEK_DAYS ? 'this-week' : 'later';
}

/** One rendered bucket: its key, its untranslated label, and its rows. */
export interface ReminderGroupSection<T> {
    key: ReminderGroupKey;
    label: string;
    items: T[];
}

/**
 * Split pending reminders into the four buckets, dropping the empty ones and
 * preserving the soonest-first order the server sent within each.
 */
export function groupReminders<T extends { remindAt: string }>(
    reminders: T[],
    timeZone: string,
    now: Date = new Date(),
): ReminderGroupSection<T>[] {
    return REMINDER_GROUPS.map((key) => ({
        key,
        label: REMINDER_GROUP_LABELS[key],
        items: reminders.filter(
            (reminder) =>
                reminderGroup(reminder.remindAt, timeZone, now) === key,
        ),
    })).filter((group) => group.items.length > 0);
}

/**
 * The due time as a reminder row's context line spells it: the bare time today
 * ("5:00 PM"), the named day either side of it ("Yesterday 9:00 AM", "Friday
 * 10:00 AM"), and a calendar date beyond the week ("Aug 20 10:00 AM"). The
 * bucket heading above the row already carries the coarse "when", so the line
 * only has to disambiguate within it.
 */
export function formatReminderDue(
    iso: string,
    timeZone: string,
    now: Date = new Date(),
): string {
    const target = new Date(iso);
    const time = formatTimeOfDay(iso, timeZone);
    const dayDiff = zonedDayDiff(now, target, timeZone);

    if (dayDiff === 0) {
        return time;
    }

    if (dayDiff === -1) {
        return translate(':weekday :time', {
            weekday: translate('Yesterday'),
            time,
        });
    }

    if (dayDiff >= 1 && dayDiff <= THIS_WEEK_DAYS) {
        const weekday = target.toLocaleDateString(i18n.locale, {
            weekday: 'long',
            timeZone,
        });

        return translate(':weekday :time', { weekday, time });
    }

    const date = target.toLocaleDateString(i18n.locale, {
        month: 'short',
        day: 'numeric',
        timeZone,
    });

    return translate(':date :time', { date, time });
}
