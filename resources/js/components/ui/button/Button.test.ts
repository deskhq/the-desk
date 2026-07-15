import { describe, expect, it } from "vitest"
import { createSSRApp, h } from "vue"
import { renderToString } from "vue/server-renderer"
import { Button, buttonVariants } from "."

/**
 * Renders `<Button>` to an HTML string in the node test environment (no DOM
 * needed). `$t` is stubbed to echo its key so the Spinner's `$t('Loading')`
 * resolves without the full app locale plumbing.
 */
async function renderButton(props: Record<string, unknown>): Promise<string> {
  const app = createSSRApp({
    render: () => h(Button, props, { default: () => "Save changes" }),
  })

  app.config.globalProperties.$t = (key: string) => key

  return renderToString(app)
}

describe("Button loading prop", () => {
  it("renders the spinner and disables the button while loading", async () => {
    const html = await renderButton({ loading: true })

    // The Spinner renders a `role="status"` element (its accessible pending cue).
    expect(html).toContain('role="status"')
    expect(html).toContain("animate-spin")
    // The `disabled` attribute, not the `disabled:` Tailwind utilities.
    expect(html).toMatch(/disabled(?!:)/)
    expect(html).toContain('aria-busy="true"')
    expect(html).toContain("Save changes")
  })

  it("renders no spinner and stays enabled when not loading", async () => {
    const html = await renderButton({ loading: false })

    expect(html).not.toContain('role="status"')
    expect(html).not.toContain("animate-spin")
    expect(html).not.toMatch(/disabled(?!:)/)
    expect(html).not.toContain("aria-busy")
  })
})

describe("Button variants and sizes", () => {
  it("drives the segmented selected state off aria-pressed, not a class ternary", async () => {
    const html = await renderButton({
      variant: "segmented",
      "aria-pressed": true,
    })

    // The selected treatment ships as `aria-pressed:` utilities, so the CSS
    // (not the call-site) reacts to the bound `aria-pressed` attribute.
    expect(html).toContain("aria-pressed:bg-card")
    expect(html).toContain("aria-pressed:text-foreground")
    expect(html).toContain('aria-pressed="true"')
    // Unselected resting state.
    expect(html).toContain("text-muted-foreground")
  })

  it("gives the pill size rounded-full geometry", () => {
    expect(buttonVariants({ size: "pill" })).toContain("rounded-full")
  })

  it("carries the destructive token on the link-destructive variant", () => {
    const classes = buttonVariants({ variant: "linkDestructive" })

    expect(classes).toContain("text-destructive")
    expect(classes).toContain("hover:underline")
    // Not the default primary link colour.
    expect(classes).not.toContain("text-primary")
  })

  it("drops the button silhouette for the unstyled escape hatch but keeps the focus ring", () => {
    const classes = buttonVariants({ variant: "unstyled", size: "none" })

    // Owns no inline layout — the card/row container supplies its own.
    expect(classes).not.toContain("inline-flex")
    expect(classes).not.toContain("h-9")
    // Still inherits the shared focus ring.
    expect(classes).toContain("focus-visible:ring-ring/50")
  })
})
