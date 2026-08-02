/** The sound a new message plays, or `off`. Backed by the PHP enum. */
export type ChimeSound = App.Enums.ChimeSound;

export type ChimeSoundOption = {
    value: ChimeSound;
    label: string;
};
