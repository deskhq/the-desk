import { describe, expect, it, vi } from "vitest"
import { computed, createSSRApp, h, ref } from "vue"
import { renderToString } from "vue/server-renderer"

/**
 * The sidebar context the trigger reads, rewritten per test. `state` is a
 * computed over `open` so the desktop open/close pair can be exercised.
 */
const sidebar = {
  isMobile: ref(true),
  open: ref(true),
  toggleSidebar: vi.fn(),
}

const unread = ref({ count: 0, hasUnread: false })

vi.mock("./utils", () => ({
  useSidebar: () => ({
    isMobile: sidebar.isMobile,
    open: sidebar.open,
    state: computed(() => (sidebar.open.value ? "expanded" : "collapsed")),
    toggleSidebar: sidebar.toggleSidebar,
  }),
}))

vi.mock("@lucide/vue", () => ({
  PanelLeftOpen: { name: "PanelLeftOpen", template: '<svg data-test="panel-left-open" />' },
  PanelLeftClose: { name: "PanelLeftClose", template: '<svg data-test="panel-left-close" />' },
}))

vi.mock("@/composables/useUnreadElsewhere", () => ({
  useUnreadElsewhere: () => unread,
}))

const { default: SidebarTrigger } = await import("./SidebarTrigger.vue")

type Scenario = {
  isMobile?: boolean
  open?: boolean
  count?: number
  hasUnread?: boolean
}

async function render({
  isMobile = true,
  open = true,
  count = 0,
  hasUnread = false,
}: Scenario = {}): Promise<string> {
  sidebar.isMobile.value = isMobile
  sidebar.open.value = open
  unread.value = { count, hasUnread }

  const app = createSSRApp({
    render: () => h(SidebarTrigger, { class: "size-9" }),
  })

  app.config.globalProperties.$t = (key: string) => key

  return renderToString(app)
}

describe("SidebarTrigger glyph", () => {
  it("draws the filled rail on mobile instead of the desktop panel/chevron pair", async () => {
    const html = await render()

    expect(html).toContain('data-test="sidebar-panel-rail"')
    expect(html).not.toContain('data-test="panel-left-open"')
    expect(html).not.toContain('data-test="panel-left-close"')
  })

  it("inks the rail brass as soon as anything is unread elsewhere", async () => {
    expect(await render({ hasUnread: false })).toContain('data-unread="false"')
    expect(await render({ hasUnread: true })).toContain('data-unread="true"')
  })

  it("keeps the Lucide open/close pair on the desktop trigger", async () => {
    const collapsed = await render({ isMobile: false, open: false })
    const expanded = await render({ isMobile: false, open: true })

    expect(collapsed).toContain('data-test="panel-left-open"')
    expect(expanded).toContain('data-test="panel-left-close"')
    expect(collapsed).not.toContain('data-test="sidebar-panel-rail"')
    expect(expanded).not.toContain('data-test="sidebar-panel-rail"')
  })
})

describe("SidebarTrigger unread badge", () => {
  it("renders a brass numeral for mentions and DM unread", async () => {
    const html = await render({ count: 4, hasUnread: true })

    expect(html).toContain('data-test="sidebar-toggle-unread-count"')
    expect(html).toContain(">4<")
    expect(html).toContain("bg-brass")
    // Tabular figures and a ring in the surface colour, so the numeral reads
    // clear of the glyph it overlaps.
    expect(html).toContain("tabular-nums")
    expect(html).toContain("ring-card")
  })

  it("caps the numeral at 99+", async () => {
    const html = await render({ count: 132, hasUnread: true })

    expect(html).toContain(">99+<")
  })

  it("renders no numeral when only plain unread is waiting", async () => {
    const html = await render({ count: 0, hasUnread: true })

    expect(html).not.toContain('data-test="sidebar-toggle-unread-count"')
  })

  it("renders nothing at all when everything is read", async () => {
    const html = await render()

    expect(html).not.toContain('data-test="sidebar-toggle-unread-count"')
    expect(html).toContain('data-unread="false"')
  })

  it("stays silent on the desktop trigger, where the sidebar owns unread", async () => {
    const html = await render({ isMobile: false, count: 4, hasUnread: true })

    expect(html).not.toContain('data-test="sidebar-toggle-unread-count"')
  })
})

describe("SidebarTrigger accessible name", () => {
  it("names the count, so the state is never colour-only", async () => {
    const html = await render({ count: 4, hasUnread: true })

    expect(html).toContain("Toggle sidebar, 4 unread elsewhere")
  })

  it("names the true count, not the capped numeral", async () => {
    const html = await render({ count: 132, hasUnread: true })

    expect(html).toContain("Toggle sidebar, 132 unread elsewhere")
  })

  it("names the rail-only state too", async () => {
    const html = await render({ count: 0, hasUnread: true })

    expect(html).toContain("Toggle sidebar, unread elsewhere")
  })

  it("falls back to the plain name when nothing is unread", async () => {
    const html = await render()

    expect(html).toContain(">Toggle sidebar<")
  })

  it("keeps the plain name on desktop even with unread elsewhere", async () => {
    const html = await render({ isMobile: false, count: 4, hasUnread: true })

    expect(html).toContain(">Toggle sidebar<")
  })

  it("hides the numeral from assistive tech, since the name already carries it", async () => {
    const html = await render({ count: 4, hasUnread: true })

    expect(html).toMatch(/data-test="sidebar-toggle-unread-count"[^>]*aria-hidden="true"/)
  })
})

describe("SidebarTrigger hit target", () => {
  it("floors the mobile target at 44px without touching the desktop size", async () => {
    const html = await render()

    expect(html).toContain("max-md:min-h-11")
    expect(html).toContain("max-md:min-w-11")
    // The call-site size survives tailwind-merge, so desktop geometry is unchanged.
    expect(html).toContain("size-9")
  })
})
