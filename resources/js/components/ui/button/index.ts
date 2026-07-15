import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

// Universal interaction/a11y utilities every button-like control shares —
// transition, focus ring, disabled handling, and svg sizing. Kept in the cva
// base so even the `unstyled` escape hatch (card/row containers) inherits the
// focus ring.
const interaction =
  "transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-hidden focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive"

// The default button silhouette — inline, centered, medium text. Prepended to
// every styled variant so `unstyled` can drop it while still inheriting the
// focus ring above. `cn` runs tailwind-merge, so a later variant/size class
// (e.g. `rounded-full` on `pill`) cleanly overrides the matching token here.
const shape =
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium shrink-0"

export const buttonVariants = cva(interaction, {
  variants: {
    variant: {
      default: `${shape} bg-primary text-primary-foreground hover:bg-primary/90`,
      destructive: `${shape} bg-destructive text-white hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/60`,
      outline: `${shape} border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50`,
      secondary: `${shape} bg-secondary text-secondary-foreground hover:bg-secondary/80`,
      ghost: `${shape} hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50`,
      link: `${shape} text-primary underline-offset-4 hover:underline`,
      // Same underline treatment as `link` but carrying the destructive token,
      // for inline "Remove"/"Clear all"/"Discard" actions that read as text.
      linkDestructive: `${shape} text-destructive underline-offset-4 hover:underline`,
      // Radio-like toggle in a segmented control. The selected option is driven
      // by `aria-pressed`, so call-sites bind `:aria-pressed` and drop their
      // hand-rolled `selected ? … : …` class ternary.
      segmented: `${shape} rounded-full text-muted-foreground hover:text-foreground aria-pressed:bg-card aria-pressed:text-foreground aria-pressed:shadow-sm`,
      // No silhouette at all — only the shared focus ring. For full-width card
      // and list-row controls that own their layout, so the container can be a
      // real button without inheriting inline padding/height. Pair with
      // `size="none"`.
      unstyled: "",
    },
    size: {
      "default": "h-9 px-4 py-2 has-[>svg]:px-3",
      "sm": "h-8 rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
      "lg": "h-10 rounded-md px-6 has-[>svg]:px-4",
      "icon": "size-9",
      "icon-sm": "size-8",
      "icon-lg": "size-10",
      // Rounded-full geometry for chips/pills; height stays overridable via class.
      "pill": "h-7.5 rounded-full gap-1.5 px-3.5 text-xs",
      // Opts out of sizing entirely — pairs with `variant="unstyled"`.
      "none": "",
    },
  },
  defaultVariants: {
    variant: "default",
    size: "default",
  },
})
export type ButtonVariants = VariantProps<typeof buttonVariants>
