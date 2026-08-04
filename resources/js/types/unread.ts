/**
 * What is waiting in one conversation or one workspace: ordinary unread traffic
 * and unread mentions, already suppressed server-side for muting and the
 * notification level.
 */
export type UnreadCounts = App.Data.UnreadCountsData;

/**
 * Everything unread the shell draws, in one shared prop.
 *
 * The rosters — `channels`, `teams`, `teamMembers` — say what a workspace *is*;
 * this says what has happened in it since the viewer last looked, which is the
 * one thing that changes on every message anyone sends. Both maps are sparse: a
 * conversation or workspace with nothing waiting is simply absent.
 */
export type UnreadDigest = App.Data.UnreadDigestData;
