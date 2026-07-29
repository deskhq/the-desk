/**
 * The two surfaces the one user menu renders on: the popover the rail's avatar
 * opens on desktop, and the "You" destination panel the tab bar opens below
 * `md`. The rows are the same component either way — only their density
 * changes, which is what this module owns.
 */
export type UserMenuVariant = 'popover' | 'panel';

/**
 * The shared look of one menu row. The popover is drawn at the dock's own
 * scale (32px rows, 13px labels); the panel is a thumb surface, so its rows
 * take the 52px height the design draws and clear the 44px hit target.
 */
export function menuRowClass(variant: UserMenuVariant): string {
    return variant === 'popover'
        ? 'flex h-8 w-full cursor-pointer items-center gap-2.25 rounded-[9px] px-2.5 text-left text-[13px] font-normal text-foreground transition-colors hover:bg-secondary focus-visible:bg-secondary'
        : 'flex h-13 w-full cursor-pointer items-center gap-3 rounded-[13px] px-3.5 text-left text-base font-normal text-foreground transition-colors hover:bg-muted/50 active:bg-muted';
}

/** The leading glyph of a row, sized to its surface. */
export function menuIconClass(variant: UserMenuVariant): string {
    return variant === 'popover'
        ? 'size-3.75 shrink-0 text-muted-foreground'
        : 'size-4.75 shrink-0 text-muted-foreground';
}

/** The uppercase eyebrow that opens each group of rows. */
export function menuSectionLabelClass(variant: UserMenuVariant): string {
    return variant === 'popover'
        ? 'px-2.5 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase'
        : 'px-3.5 pb-1.5 text-[11px] font-semibold tracking-[0.12em] text-muted-foreground uppercase';
}

/** The gutter each group of rows sits in. */
export function menuSectionClass(variant: UserMenuVariant): string {
    return variant === 'popover' ? 'px-1.5 pt-2.5' : 'px-2 pt-3';
}

/** The hairline separating two groups of rows. */
export function menuSeparatorClass(variant: UserMenuVariant): string {
    return variant === 'popover'
        ? 'mx-2.5 mt-2.5 h-px bg-border'
        : 'mx-3.5 mt-3 h-px bg-border';
}

/**
 * The trailing chevron of a row that leads somewhere else. Muted rather than
 * foreground: it is an affordance, not a second label.
 */
export function menuChevronClass(variant: UserMenuVariant): string {
    return variant === 'popover'
        ? 'size-3 shrink-0 text-muted-foreground'
        : 'size-4 shrink-0 text-muted-foreground';
}
