/** A language the interface is translated into. Backed by the PHP enum. */
export type AppLocale = App.Enums.AppLocale;

export type LocaleOption = {
    value: AppLocale;
    label: string;
};
