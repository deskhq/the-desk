/**
 * A DM participant surfaced to the sidebar / masthead for the avatar stack and
 * participant-based name.
 */
export type DmParticipant = App.Data.UserData;

/** How loudly a channel talks to the viewer. Backed by the PHP enum. */
export type NotificationLevel = App.Enums.NotificationLevel;

/** One selectable level in the channel's notification preference menu. */
export type NotificationLevelOption = {
    value: NotificationLevel;
    label: string;
};

/**
 * A conversation space in a workspace, as the sidebar and the channel page
 * receive it: the channel itself plus the viewer's own relationship to it
 * (membership state, badges, mute, level, draft, star, placement).
 */
export type Channel = App.Data.ChannelData;

/**
 * A user-created sidebar section, rendered between "Starred" and the default
 * "Channels" group.
 */
export type ChannelSection = App.Data.ChannelSectionData;
