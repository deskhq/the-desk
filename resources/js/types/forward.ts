/**
 * A chosen forward destination: either a channel the author is in, or a person
 * whose 1:1 DM is opened-or-created on forward. `name` is the display label used
 * for the success toast.
 */
export type ForwardTarget =
    | { kind: 'channel'; id: string; name: string }
    | { kind: 'user'; id: string; name: string };
