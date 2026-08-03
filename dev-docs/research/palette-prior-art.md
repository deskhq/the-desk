# Prior art: palette contribution, ranking and argument-taking verbs

Research for [#1207](https://github.com/deskhq/the-desk/issues/1207), feeding the map
[wayfinder: the Cmd-K palette becomes a command palette](https://github.com/deskhq/the-desk/issues/1205).

## What this answers

Five comparable systems, read for three questions the map has to settle:

1. **Contribution.** How does a feature add a verb, and is that declaration separate from the code that runs it?
2. **Ranking.** How do verbs order against navigational results when both live in one list?
3. **Arguments.** How does a verb that needs a target before it can run enter and leave its second stage?

This is evidence, not a recommendation. Each section states what the system actually does, the trade-off it
appears to be buying, and where its constraints differ from ours. Ours are: self-hosted, team-scoped, Vue 3 +
Inertia v3, a server-side `App\SlashCommands\SlashCommandRegistry` that stays out of the palette, a
client-side `SHORTCUTS` keyboard registry that stays the binding table, and a `Command.vue` built on
**reka-ui's Listbox**, not on cmdk.

The decision already locked by
[one verb registry or three?](https://github.com/deskhq/the-desk/issues/1206) is taken as given throughout:
the palette registry is client-side, a command may claim a `ShortcutId` and inherit its copy, slash commands
never appear, and there is one availability predicate rather than an `authorize()`.

Where a finding bears on a downstream ticket, it is called out by title.

---

## VS Code

### Contribution is a two-part contract, and both halves are required

VS Code splits a command into a **declaration** in `package.json` and a **registration** in code. The
manifest entry carries the user-facing identity:

```json
{
  "contributes": {
    "commands": [
      {
        "command": "extension.sayHello",
        "title": "Hello World",
        "category": "Hello",
        "icon": { "light": "…", "dark": "…" }
      }
    ]
  }
}
```

The fields are `command` (id), `title`, optional `category` (the grouping label rendered as a prefix in the
palette), optional `icon`, and optional `enablement` (a `when` clause controlling enabled state)
([contribution points](https://code.visualstudio.com/api/references/contribution-points)). The runtime half is
`vscode.commands.registerCommand`, which "binds a command ID to a handler function in your extension", while
the manifest entry "tells VS Code that your extension provides a given command and should be activated when
that command is invoked" ([commands guide](https://code.visualstudio.com/api/extension-guides/command)).

The failure modes are symmetric and both are silent:

- **Declared, not registered.** The extension activates, the palette shows the command, invoking it does
  nothing. This is precisely the half-wired failure `keyboardShortcuts.ts` documents itself as existing to
  prevent, and VS Code does **not** prevent it structurally. It is caught by testing, not by the type system.
- **Registered, not declared.** The command exists internally but "remains hidden from users; it won't appear
  in the Command Palette and won't be discoverable."

**Trade-off bought.** The split lets the palette be populated from a static manifest without loading any
extension code, which is what makes activation lazy. The price is that the two halves can drift.

**Where our constraints differ.** We pay nothing for lazy activation: our registry is a client bundle that is
already loaded. So the argument that forces VS Code's split does not apply to us, which makes the "closed
declarative array vs imperative registration" fork in
[the command definition contract](https://github.com/deskhq/the-desk/issues/1209) genuinely open rather than
settled by precedent. Note, though, that #1206 has already introduced a partial split of its own: `SHORTCUTS`
holds the copy and the keys, and the command holds the `run`. That is the same drift surface in miniature, and
VS Code is the evidence that it does not self-heal.

### Applicability is a `when` clause, and it is a visibility mechanism

Contributed commands are visible in the palette **by default**. To narrow that, you add a `commandPalette`
entry under `contributes.menus`:

```json
{
  "menus": {
    "commandPalette": [
      { "command": "extension.sayHello", "when": "editorHasSelection" }
    ]
  }
}
```

"It allows you to define a `when` condition to control if a command should be visible in the Command Palette
or not" ([contribution points](https://code.visualstudio.com/api/references/contribution-points)).

Two things are worth pinning down:

- **`when` and `enablement` are deliberately distinct but overlapping.** The docs say so explicitly: "There is
  semantic overlap between `enablement` and the `when` condition of menu items. The latter is used to prevent
  menus full of disabled items." So VS Code's answer to *hide or disable* is: hide in the palette, disable in
  contextual menus.
- **The context expression language is rich and entirely client-side.** Operators include `!`, `&&`, `||`,
  `==`, `!=`, `>`/`<`, a regex match `=~`, and `in`/`not in`; the built-in key set spans editor, OS, resource,
  explorer, debugger, terminal and workspace state; extensions add their own keys with
  `vscode.commands.executeCommand('setContext', 'myExtension.showMyCommand', true)`
  ([when clause contexts](https://code.visualstudio.com/api/references/when-clause-contexts)).
  Notably the documentation frames `when` purely as a UI visibility tool. It never presents it as a security
  boundary, and there is no mechanism by which it could be one.

**Bearing on our tickets.** This is direct corroboration for the seam #1206 already drew: one predicate,
covering permission and applicability alike, deciding availability rather than enforcement. VS Code's whole
apparatus is a *visibility* apparatus. What VS Code adds is the observation that visibility and enabled-ness
are separately expressible, which is exactly the fork
[how commands blend into the list](https://github.com/deskhq/the-desk/issues/1211) has to resolve, and VS Code
answers it per-surface rather than per-command.

### Ranking: alphabetical stability, deliberately chosen over relevance

VS Code sorts palette entries by name. The reasoning is stated by a maintainer on
[microsoft/vscode#1964](https://github.com/microsoft/vscode/issues/1964):

> The issue with fuzzy matching is that you also need to do fuzzy sorting: more relevant results to the top.
> However for the command palette we chose to sort entries by name to keep the result list stable and
> memorable.

Two mitigations sit on top of that stable base: recently used commands are lifted to the top of the filtered
list (kept as a labelled "recently used" section, whose labelling was challenged in
[microsoft/vscode#89816](https://github.com/microsoft/vscode/issues/89816) and closed as-designed), and each
entry renders its keybinding beside it, so the palette doubles as the shortcut discovery surface: "You can see
the default keyboard shortcut alongside the command in the Command Palette"
([tips and tricks](https://code.visualstudio.com/docs/editing/tips-and-tricks)).

**Bearing on our tickets.** The keybinding-beside-the-entry pattern is what #1206's `ShortcutId` claim exists
to make possible, and it is the strongest argument that the palette can absorb part of
`KeyboardShortcutsModal`'s job (currently listed under *Not yet specified* on the map). The stable-sort
finding cuts against score interleaving in #1211: VS Code has vastly more commands than we will, and still
concluded that a list whose order moves under the user's fingers is worse than one that does not.

### Verbs and navigation are one widget with mode prefixes, not one blended list

This is the finding most likely to be misread. VS Code has two shortcuts, `Ctrl+Shift+P` for the Command
Palette and `Ctrl+P` for Quick Open, but they are **the same widget in different modes**. Typing `>` in Quick
Open switches to commands; `@` goes to a symbol in the file, `@:` groups symbols by category, `#` searches
workspace symbols, `:` jumps to a line, `?` lists the available modes, and word prefixes such as `edt` and
`term` open editor and terminal pickers ([tips and tricks](https://code.visualstudio.com/docs/editing/tips-and-tricks),
[user interface](https://code.visualstudio.com/docs/getstarted/userinterface)).

So VS Code never solved the blending problem. It **avoided** it: files and commands never compete for rank
because they are never in the same result set. The map has explicitly parked mode prefixes as out of scope
(alongside frecency), which means #1211 is taking on a problem VS Code declined to take on. That is a
legitimate choice, but the precedent should not be read as support.

---

## Linear

Linear is the closest product analogue and the one with the least public documentation, so the evidence here
is thinner and leans on first-party changelog posts.

### One menu, contextual by view and by selection

`Cmd/Ctrl+K` opens a menu that "gives you access to all actions applicable to your view or selection". With
issues selected, the same shortcut surfaces the actions applicable to that selection, and right-clicking the
selection opens the equivalent contextual menu
([Select issues](https://linear.app/docs/select-issues)).

The [Contextual command menu](https://linear.app/changelog/2019-10-07-contextual-command-menu) changelog adds
that the menu also opens automatically when clicking UI elements that have a corresponding command (assigning
an issue, setting priority), and that it is now anchored near its trigger rather than always centred, so it
behaves "almost like a drop-down element but still retains its searchability and keyboard controllability".

That last point is a genuine architectural claim: Linear's command menu is not only a palette, it is also the
implementation of its dropdowns. One component, many mount points. That is the surface-proliferation question
the map lists under *Out of scope* ("command surfaces beyond the palette"), and Linear is the evidence that
the registry, if it exists, tends to get consumed by more than the palette.

### Ranking is by group, and group order is context-dependent

The [New command menu](https://linear.app/changelog/2019-12-18-new-command-menu) changelog is the most direct
statement of Linear's ranking model:

> Groups are prioritized based on what you are focusing on, or the view you're currently in.

with the worked example that "if you are looking at cycles, the command menu will first display commands that
are related to cycles". Groups are further subdivided by command type "to facilitate scanning through large
command sets", and icons were added for the same reason. The stated motivation is that "the number of commands
[is] set to increase with new functionality".

**Trade-off bought.** Linear gets relevance without a per-item score fight: items keep a stable order *inside*
a group, and only the group order moves. That is a materially cheaper thing to build and to reason about than
interleaving by score, and it degrades gracefully as the catalogue grows.

**Bearing on our tickets.** This is the sharpest available answer to the first bullet of
[how commands blend into the list](https://github.com/deskhq/the-desk/issues/1211), and it is a *third* option
beyond the two that ticket names. Not "a group among the existing ones" with fixed order, and not "interleave
by score", but **groups whose ordering is query- and context-dependent while item order within a group stays
put**. Our palette already has four groups (Actions, Channels, People, Messages) with per-group rankers
(`rankChannels`, `rankPeople`), so the machinery for "stable inside, reorderable outside" already exists; what
does not exist is anything that reorders the groups.

### Arguments: the verb opens a picker, and the picker is the same component

Linear's docs describe assigning as: "open the command menu (`⌘K`) and search for 'Assign to...' to make
updates via keyboard", and separately that pressing `A` on an issue will "open the assignment menu"
([Assign and delegate issues](https://linear.app/docs/assigning-issues)). The naming convention itself is
evidence: the trailing ellipsis on "Assign to..." is the classic signal that selecting the item does not
complete the action.

The two routes converge on one picker, which is consistent with the contextual-menu changelog's claim that
the command menu *is* the dropdown implementation. So Linear's second stage is not a bespoke palette mode; it
is the same searchable list re-rooted on a different item set.

**Caveat.** Linear does not publish how the second stage is entered and escaped at the keyboard level, and
this was not verifiable from public documentation. Treat the mechanism as inferred, not established.

**Bearing on our tickets.** The *Argument-taking verbs* fog entry on the map, and the arguments bullet in
[the command definition contract](https://github.com/deskhq/the-desk/issues/1209), get a concrete shape here:
an argument stage is a re-rooted list, not a form. That matters because a re-rooted list reuses the existing
listbox, whereas a form does not, and the accessibility cost differs sharply between the two (see the primitive
section below).

---

## Raycast and Spotlight

These two are grouped because they answer the same question, arguments as a first-class interaction, from
opposite ends: Raycast declares arguments in a manifest, Spotlight derives them from an existing system
framework.

### Raycast: arguments are declared, typed, capped at three, and entered before the command opens

Arguments are declared per command in `package.json` under an `arguments` array, each with `name`, `type`,
`placeholder`, `required`, and for dropdowns a `data` array of `{ title, value }`
([Arguments](https://developers.raycast.com/information/lifecycle/arguments)). The types are exactly three:
`text`, `password`, `dropdown`. So is the cap: "Maximum number of arguments: 3".

The interaction is the notable part. "Users can enter values right from Root Search before opening the
command." Selecting a command with arguments causes "input fields [to] appear right in the search bar area",
navigated with the Left and Right arrow keys, and "the order of the arguments specified in the manifest is
important and is reflected by the fields shown in Root Search"
([Search bar](https://manual.raycast.com/search-bar)). Guidance is to "put the required arguments before the
optional ones". At runtime the command receives them via the `arguments` prop on `LaunchProps`.

**Trade-off bought.** The second stage costs no navigation: the user never leaves the root list, the command
never renders its own UI, and a fully-specified invocation is a single uninterrupted typing run. The price is
a hard ceiling (three), a closed type set, and arguments that must be expressible as strings.

### Raycast ranks by learned frecency, with aliases as a deterministic override

Root search is fuzzy ("typing `msg` finds Messages"), and ranking is learned: the more often and recently a
result is chosen for a given query, the higher it ranks next time, with a per-command **Reset Ranking** action
to clear that learned state. Sitting above the learned layer is a deterministic one: an **alias** is a short
string that "uses strict matching in Root Search, which makes [it] more predictable than regular fuzzy
search", and typing the full alias "places the command at the very top of results". Priority is stated as
exact alias match, then alias prefix match, then the learned ordering
([Command Aliases & Hotkeys](https://manual.raycast.com/command-aliases-and-hotkeys),
[Search bar](https://manual.raycast.com/search-bar)). When nothing matches, **fallback commands** occupy the
empty state and can take the typed text as their single argument
([v1.23.0](https://www.raycast.com/changelog/1-23-0)).

This is the direct counter-position to VS Code: Raycast bought relevance and paid for the instability with an
explicit escape hatch (aliases) for users who want determinism. Both systems recognised the same tension and
resolved it in opposite directions.

**Bearing on our tickets.** Frecency is out of scope on the map, so the learned half is not available to us.
But the *alias* half is separable from it and is a plain string-matching feature. That is relevant to #1211's
"how does a command match: title only, or description and aliases too", and to #1209's "keywords/aliases for
matching" bullet: Raycast's evidence is that an exact-match alias is worth having precisely *because* the rest
of the ranking is fuzzy, which weakens the case for aliases if the rest of our ranking stays deterministic.

### Spotlight (macOS 26 Tahoe): actions contributed through an existing framework, parameters filled inline

Apple shipped "hundreds of actions directly from Spotlight, like sending an email, creating a note, or playing
a podcast", contributed by third parties because "any app can provide actions to Spotlight using the App
Intents API". Results are "ranked intelligently based on relevance to the user", and the system "surfaces
personalized actions, such as sending a message to a colleague a user regularly talks to". It also added
**quick keys**, "short strings of characters that get users right to the action they're looking for"
([Apple Newsroom, June 2025](https://www.apple.com/newsroom/2025/06/macos-tahoe-26-makes-the-mac-more-capable-productive-and-intelligent-than-ever/)).

The contribution story is the interesting one: Apple did not invent a palette contribution API. It reused App
Intents, a vocabulary apps had already been adopting for Shortcuts and Siri. The palette became a **new
consumer of an existing registry** rather than a new registry.

The parameter interaction is documented in third-party walkthroughs: selecting an action that needs input
causes Spotlight to prompt for it rather than execute; fields are moved between with `Tab` and `Shift+Tab`;
`Return` executes, and pressing `Return` again repeats the action without re-entering the parameters; `Escape`
leaves the parameter stage and returns to the actions list, and a second `Escape` returns to ordinary search
([MacMost](https://macmost.com/how-to-use-spotlight-actions-in-macos-tahoe.html)). Note that this last point
is a third-party observation rather than Apple documentation.

**The escape story is the transferable finding.** Both Raycast and Spotlight give the argument stage a
**two-level `Escape`**: first press returns to the list, second press dismisses. That is a specific,
implementable contract for the fog entry the map records as "whether the palette grows a second stage for a
verb that needs a target, and what that does to focus management and a11y", and it is the shape #1209 would
have to declare.

**Where our constraints differ, sharply.** Both of these are OS-level surfaces with no DOM, no ARIA, and no
screen-reader contract to satisfy in a listbox. Their `Tab`-between-fields model is not directly transplantable:
inside a `role="listbox"` widget, `Tab` is not a free key, and rendering focusable text inputs inside a
listbox breaks the listbox's own ARIA contract. The Linear-style "re-rooted list" answer avoids that problem;
the Raycast-style "inline fields" answer walks straight into it. That is the real cost the map's fog entry is
pointing at, and it is quantified in the next section.

---

## Slack

Slack matters because it is our nearest domain peer and because it has kept the split that
[one verb registry or three?](https://github.com/deskhq/the-desk/issues/1206) has now locked for us.

### The two surfaces, and what each can do

**The Quick Switcher (`Cmd/Ctrl+K`)** jumps between channels, direct messages and workspaces by typing part of
a name; `#` narrows to public channels and `@` to direct messages
([Navigate using the Quick Switcher](https://slack.com/help/articles/226599368-Navigate-using-the-Quick-Switcher)).
Every result is a destination. Nothing in it performs an action. `Cmd/Ctrl+G` is a separate shortcut that
starts a search ([Slack keyboard shortcuts](https://slack.com/help/articles/201374536-Slack-keyboard-shortcuts)).

**The shortcuts menu** is opened from "the slash icon next to the message field" or by typing a forward slash,
and lists app shortcuts, slash commands and workflows together, showing recently used ones first
([Use shortcuts to take actions in Slack](https://slack.com/help/articles/360057554553-Use-shortcuts-to-take-actions-in-Slack)).
It is a composer surface.

### Why the split appears to exist: the context requirement is structural

Slack's own developer documentation makes the reason legible without ever arguing for the split. Slash commands
are "invoke[d] ... by typing a string into the message composer box", and the payload delivered to the app
always carries `channel_id` and `channel_name` alongside `user_id`, `team_id`, the `command` itself, the
`text` after it, a `response_url` and a `trigger_id`. Responses are either `ephemeral` or `in_channel`, both of
which are things that happen *in a conversation*. Developer-created slash commands "cannot ... be invoked in
message threads" ([Implementing slash commands](https://docs.slack.dev/interactivity/implementing-slash-commands)).

Read together: a slash command's contract is channel-bound in its input (`channel_id` is always present), in
its output (both response types are conversation-scoped), and in its entry point (the composer). The Quick
Switcher, by contrast, is reachable from anywhere, including from places with no conversation open. Merging
them would require either a nullable channel in a contract that currently guarantees one, or a catalogue whose
contents silently halve depending on where the shortcut was pressed.

**Trade-off bought.** Slack keeps two small, coherent surfaces with unambiguous entry points and pays for it in
discoverability. A user who does not know the shortcuts menu exists will not stumble into it from `Cmd+K`, and
the two surfaces are documented in different parts of the help centre with no cross-reference between them.
Slack does not appear to regard this as a problem: the `Cmd+K` surface is described purely as navigation, and
the help article on shortcuts never mentions `Cmd+K` at all.

**Bearing on our tickets.** This is independent, external corroboration of the structural argument #1206 made
from `SlashCommandContext`'s non-nullable `Channel`. Our `SlashCommandContext` is essentially Slack's payload
with the same channel-boundedness, and the same three result types that are all outcomes of a send. Slack, with
far more resources and far more pressure to unify, has not unified. That is worth recording on the map because
the unification instinct will recur.

The discoverability cost, though, is the one Slack chose to eat and we have not yet decided to. It is the same
cost the map's *Discoverability* fog entry names, and it bites us harder: we are proposing that `Cmd+K` grows
verbs, which makes it strictly more plausible to a user that *all* verbs live there, including slash commands
that do not. Slack avoids that confusion by having `Cmd+K` contain no verbs at all. Once ours contains some,
"why is `/giphy` not here" becomes a reasonable user question with a structural rather than intuitive answer.
That is a copy problem, and it lands on
[what is this surface called, and where does its identity live?](https://github.com/deskhq/the-desk/issues/1208)
and on #1211's empty-state and hint-copy bullets.

---

## The primitive layer: cmdk, kbar, and what reka-ui actually gives us

The ticket asks about cmdk and kbar as "the primitive layer beneath `Command.vue`'s lineage". Reading the
repo first changes the conclusion: our `Command.vue` shares cmdk's *shape* but almost none of its *machinery*.
The real constraint is reka-ui's Listbox.

### What is actually in `resources/js/components/ui/command/`

`Command.vue` wraps reka-ui's `ListboxRoot` and re-implements a cmdk-style filter on top of it. It keeps
`allItems: Map<id, string>`, `allGroups: Map<groupId, Set<itemId>>`, and a reactive `filterState` with
`search` plus `filtered: { count, items, groups }`. Its scoring is:

```ts
const { contains } = useFilter({ sensitivity: "base" })
// …
const score = contains(value, filterState.search)
filterState.filtered.items.set(id, score ? 1 : 0)
```

`useFilter` is reka-ui's own shared helper, and `contains` is an `Intl.Collator`-based **substring** test
returning a boolean, not a rank
([reka-ui `useFilter`](https://github.com/unovue/reka-ui/blob/v2/packages/core/src/shared/useFilter.ts)). So
the primitive's filter is binary include/exclude, with every surviving item scoring exactly `1`. There is no
ranking in the primitive at all. `CommandItem` reads `filterState.filtered.items.get(id)` and renders only if
it is `> 0`; `CommandGroup` hides itself unless it holds a surviving item; each item registers its own
`textContent` as the value to match against.

And in `QuickSwitcher.vue` even that is inert. The palette does not use `CommandInput`; it uses
`SwitcherField.vue`, which binds a raw `ListboxFilter` to its own `query` model. Nothing ever writes
`filterState.search`, so `filterItems()` never runs, every item always renders, and ordering is entirely owned
by `rankChannels` / `rankChannelsByActivity` / `rankPeople` and the template's group order. This is what #1211
means by "the `Command` primitive's internal filter is deliberately left empty".

**The consequence for #1211.** "Interleave by score" is not a thing the primitive can be asked to do. There is
no score to interleave on, in either the reka-ui layer or the shadcn wrapper. Any blended ranking is code we
write from scratch, in the same place `rankChannels` already lives. Conversely, "commands are a group" is
nearly free, because it is exactly the shape already in the file.

### What reka-ui's Listbox assumes, from source

Read from [reka-ui v2](https://github.com/unovue/reka-ui/tree/v2/packages/core/src/Listbox):

- **`ListboxContent` renders `role="listbox"`** with `aria-orientation` and `aria-multiselectable`, and owns
  arrow / Home / End navigation, `Enter`, and a typeahead handler.
- **`ListboxItem` renders `role="option"`** with a generated `id`, `aria-selected`, `data-highlighted`,
  `data-state` and `data-disabled`. Its `select` event is cancelable via `preventDefault()`.
- **`ListboxFilter` renders a bare `<input type="text">`.** It sets `aria-activedescendant` from the root's
  `highlightedElement.id`, and forwards Up / Down / Home / End / Enter to the root. It does **not** render
  `role="combobox"`, `aria-expanded`, or `aria-controls`. On mount it sets `rootContext.focusable = false`
  (so the listbox and its options stop being tab stops) and restores it on unmount.
- **Every keystroke in the filter calls `rootContext.highlightFirstItem()`**, which fires a synthetic
  `PageUp` navigation. So the highlight snaps back to the *first rendered item* on every character typed.
  Whatever is first in DOM order is what `Enter` runs. That makes group order not a cosmetic decision but the
  determinant of the default action, which is the sharpest constraint #1211 faces.
- **Groups are `ListboxGroup` + `ListboxGroupLabel`, flat.** There is no nesting, no pages, and no
  second-stage concept anywhere in the Listbox primitives.

**The a11y arithmetic for an argument stage.** Because `ListboxFilter` flips `focusable` off, the options are
not tab stops, and the only association between the input and the list is `aria-activedescendant`. Introducing
a second stage therefore has two shapes with very different costs:

- **Re-root the same list** (the Linear shape). The listbox stays a listbox, `aria-activedescendant` keeps
  working, and the only new work is announcing the mode change (an `aria-live` region or a relabelled
  `CommandList`, which already requires an `ariaLabel` prop by type, per #798) and wiring `Escape` /
  `Backspace`-on-empty to unwind. Nothing in the ARIA contract breaks.
- **Render argument fields inline** (the Raycast shape). This puts focusable inputs inside or beside a
  `role="listbox"` and needs `Tab`, which is not a free key here, and would mean either flipping `focusable`
  back on or unmounting `ListboxFilter` (which restores `focusable` on unmount and would re-enable option tab
  stops mid-interaction). This is the expensive path, and it is expensive for reasons specific to our
  primitive, not to argument-taking verbs in general.

### cmdk: what it assumes that we do not have

[cmdk](https://github.com/pacocoursey/cmdk) filters and ranks by default, via a `filter(value, search,
keywords) => number` prop, with `shouldFilter={false}` to opt out entirely. Its default scorer,
[`command-score`](https://github.com/pacocoursey/cmdk), is a real fuzzy ranker with tuned constants
(`SCORE_CONTINUE_MATCH = 1`, `SCORE_SPACE_WORD_JUMP = 0.9`, `SCORE_NON_SPACE_WORD_JUMP = 0.8`,
`SCORE_CHARACTER_JUMP = 0.17`, `SCORE_TRANSPOSITION = 0.1`, and multiplicative penalties
`PENALTY_SKIPPED = 0.999`, `PENALTY_CASE_MISMATCH = 0.9999`, `PENALTY_DISTANCE_FROM_START = 0.9`,
`PENALTY_NOT_COMPLETE = 0.99`). Items carry a `value` (inferred from text content if absent) and optional
`keywords` that act as aliases and "can also affect the rank of the item"; the alias strings are concatenated
onto the value before scoring. Items sort by score; **groups do not sort**, they render in JSX order.

On nesting, cmdk is explicit that it provides no primitive: "Often selecting one item should navigate deeper,
with a more refined set of items ... We call these sets of items 'pages', and they can be implemented with
simple state." The documented convention is a `pages` array in component state, with `Escape`, or `Backspace`
when the search is empty, popping back a level.

**The gap that matters.** Our shadcn-vue wrapper reproduces cmdk's *structure* (a filter state, an items map,
a groups map, per-item visibility) but replaced its *scorer* with a boolean substring test. Any prior art that
assumes cmdk's ranking, including most "build a Cmd+K like Linear" write-ups, does not describe our situation.

### kbar: the counter-example where contribution is imperative and nesting is first-class

[kbar](https://github.com/timc1/kbar) is worth reading precisely because it made the opposite choices to
everything above. Its `Action` is a single object with no declaration/registration split:

```ts
export type Action = {
  id: ActionId;
  name: string;
  shortcut?: string[];
  keywords?: string;
  section?: ActionSection;
  icon?: string | React.ReactElement | React.ReactNode;
  subtitle?: string;
  perform?: (currentActionImpl: ActionImpl) => any;
  parent?: ActionId;
  priority?: Priority;
};
```

Points worth extracting:

- **The keyboard shortcut lives on the action.** `shortcut: ["b"]` is part of the same object as `perform`.
  kbar is therefore the one-registry answer that #1206 rejected, and it can be because it has no pre-existing
  pure, module-scope, unit-tested binding table to protect. Our `SHORTCUTS` is exactly that, which is the
  constraint that produced the reference-not-merge shape.
- **Nesting is `parent`, a flat list with a pointer.** Actions form a tree by id reference, not by nested data.
  Entering is selecting the parent, leaving is "hit backspace to navigate to the previous action", and the
  active level is tracked as `currentRootActionId` in query state. This is the cheapest possible encoding of
  Linear's re-rooted-list shape, and it means an argument stage is expressible as ordinary registry entries
  rather than as a special mode.
- **Contribution is imperative and lifetime-scoped.** `useRegisterActions` registers from inside a component,
  so an action exists exactly as long as the component that owns it is mounted. Availability is a mount
  question rather than a predicate question. That is a genuinely different answer to #1209's availability
  bullet than the one #1206 locked, and its cost is the one kbar's own issue tracker names: with dynamic
  registration "it's difficult to predict which order items will appear in", which is why `priority` exists.
- **`section` accepts either a string or `{ name, priority }`**, so group ordering is declarable per action.
  That is Linear's group-priority model exposed as data.

`ActionSection = string | { name: string; priority: Priority }` and `Priority = number` are the whole of the
group-ordering vocabulary. It is small, and it is worth noting that it exists *because* imperative
registration destroyed the natural ordering that a static array would have given for free.

---

## Where the systems disagree

These are the genuine forks. Each is stated as the shape of the choice, not as a recommendation.

### 1. Stable order versus relevant order

**VS Code** sorts alphabetically and says so deliberately: "to keep the result list stable and memorable".
**Raycast** ranks by learned frecency and then hands power users an exact-match alias to escape it.
**Linear** splits the difference by moving *groups* around while leaving items stable inside them.

There is no consensus. The axis is: does the user build muscle memory for a *position*, or for a *query*?
VS Code bets on position, Raycast bets on query plus an escape hatch, Linear bets on position-within-a-group.
Because frecency is out of scope on the map, our version of this fork is narrower: fixed group order versus
query-dependent group order, and it lands on
[how commands blend into the list](https://github.com/deskhq/the-desk/issues/1211). Note that our primitive
supplies no score, so "interleave by relevance" is not the cheap option here that it is in cmdk.

### 2. Declarative manifest versus imperative registration

**VS Code** and **Raycast** both declare in a static manifest and bind behaviour separately, and both accept
that the halves can drift (VS Code documents the drift explicitly, in both directions). **kbar** puts identity
and behaviour in one object registered imperatively from a component, and accepts unpredictable ordering as the
price, which is why it grew `priority`.

The real trade is **drift risk versus ordering determinism**, and it is not obvious which is worse. #1206 has
already committed us to a hybrid that inherits *both* risks in miniature: copy and keys in `SHORTCUTS`, `run`
assembled at setup in a composable. That makes "what stops a verb being half-wired", the last bullet of
[the command definition contract](https://github.com/deskhq/the-desk/issues/1209), the load-bearing question,
because no system studied here solves it structurally. VS Code, the system with the most to lose, catches it
with tests.

### 3. Unified surface versus separate surfaces

**Linear** unifies aggressively: one component serves the palette, the contextual menus and the field
dropdowns. **Slack** keeps navigation and verbs apart, with different entry points, different context
requirements, and no cross-reference in the documentation. **VS Code** looks unified but is not: it is one
widget in mutually exclusive modes selected by a prefix character.

Three systems, three answers, and each is coherent with its own domain. Slack's split is forced by
channel-boundedness, which is our situation exactly and is why #1206 locked the same split. Linear's
unification is possible because everything in Linear happens to an issue, so context is always present.
VS Code's mode prefixes are the option the map has explicitly parked. The residue for us is the
discoverability cost: Slack pays it silently because `Cmd+K` promises nothing, and we will not have that
excuse once `Cmd+K` runs verbs. That lands on
[what is this surface called, and where does its identity live?](https://github.com/deskhq/the-desk/issues/1208).

### 4. Argument stage: a re-rooted list versus inline fields

**Linear** and **kbar** re-root the same list on a narrower item set (kbar via `parent` and
`currentRootActionId`, Linear via what appears to be the same mechanism). **Raycast** and **Spotlight** render
typed fields inline and keep the list where it is.

The two agree on the escape contract even where they disagree on everything else: `Escape` unwinds one level
and only then dismisses, and `Backspace` on an empty query does the same in the list-based designs. That
two-level unwind looks like the settled convention.

They diverge on cost, and our primitive makes the divergence lopsided. Re-rooting keeps `role="listbox"` /
`role="option"` / `aria-activedescendant` intact and needs only a mode announcement plus unwind wiring.
Inline fields put tab stops inside a listbox, need `Tab` (which reka-ui's `ListboxFilter` does not forward),
and interact badly with `ListboxFilter` flipping `rootContext.focusable` on mount and back on unmount. Also
worth weighing: Raycast caps arguments at three and permits only three string-ish types, which is evidence
that the inline-field model does not scale, and it is an OS-level surface with no ARIA obligations at all.

### 5. Hide or disable an unavailable verb

**VS Code** answers per-surface rather than per-command: hide it in the palette via a `commandPalette` `when`
clause, disable it in menus via `enablement`, with the docs stating the reason as preventing "menus full of
disabled items". Nobody else studied here documents a position.

#1206 locked the seam (availability is a predicate; rendering is #1211's call) but not the answer, and VS
Code's contribution is the observation that the answer can legitimately differ per surface for the *same*
command. Since the map lists "command surfaces beyond the palette" as out of scope, we get to answer it once,
which is simpler than VS Code's situation but forecloses the split later.

### 6. Whether the palette should absorb the shortcut cheat sheet

**VS Code** renders each command's keybinding beside it in the palette and treats that as the discovery
mechanism. **kbar** puts `shortcut` on the action itself for the same reason. Neither ships a separate
shortcuts modal as the primary surface.

Our `KeyboardShortcutsModal` is that separate surface, and #1206's `ShortcutId` claim already makes rendering
the keys beside a palette entry a lookup rather than a duplication. Whether that makes the modal redundant is
the map's *Discoverability* fog entry, and the prior art leans one way without settling it: two of five systems
studied fold the cheat sheet into the palette, and none of the five keeps a modal as the main answer.

---

## Sources

Primary, first-party unless noted.

- VS Code: [Contribution points](https://code.visualstudio.com/api/references/contribution-points) ·
  [Commands extension guide](https://code.visualstudio.com/api/extension-guides/command) ·
  [When clause contexts](https://code.visualstudio.com/api/references/when-clause-contexts) ·
  [Tips and tricks](https://code.visualstudio.com/docs/editing/tips-and-tricks) ·
  [User interface](https://code.visualstudio.com/docs/getstarted/userinterface) ·
  [Command Palette UX guidelines](https://code.visualstudio.com/api/ux-guidelines/command-palette) ·
  [microsoft/vscode#1964](https://github.com/microsoft/vscode/issues/1964) (ordering rationale) ·
  [microsoft/vscode#89816](https://github.com/microsoft/vscode/issues/89816) ("recently used", closed as-designed)
- Linear: [Contextual command menu changelog](https://linear.app/changelog/2019-10-07-contextual-command-menu) ·
  [New command menu changelog](https://linear.app/changelog/2019-12-18-new-command-menu) ·
  [Select issues](https://linear.app/docs/select-issues) ·
  [Assign and delegate issues](https://linear.app/docs/assigning-issues) ·
  [Invisible details](https://medium.com/linear-app/invisible-details-2ca718b41a44) (first-party blog)
- Raycast: [Arguments](https://developers.raycast.com/information/lifecycle/arguments) ·
  [Search bar](https://manual.raycast.com/search-bar) ·
  [Command aliases and hotkeys](https://manual.raycast.com/command-aliases-and-hotkeys) ·
  [v1.23.0 changelog, fallback commands](https://www.raycast.com/changelog/1-23-0)
- Spotlight: [Apple Newsroom, macOS Tahoe 26](https://www.apple.com/newsroom/2025/06/macos-tahoe-26-makes-the-mac-more-capable-productive-and-intelligent-than-ever/) ·
  [MacMost walkthrough of Spotlight actions](https://macmost.com/how-to-use-spotlight-actions-in-macos-tahoe.html) (third party)
- Slack: [Implementing slash commands](https://docs.slack.dev/interactivity/implementing-slash-commands) ·
  [Use shortcuts to take actions in Slack](https://slack.com/help/articles/360057554553-Use-shortcuts-to-take-actions-in-Slack) ·
  [Navigate using the Quick Switcher](https://slack.com/help/articles/226599368-Navigate-using-the-Quick-Switcher) ·
  [Slack keyboard shortcuts](https://slack.com/help/articles/201374536-Slack-keyboard-shortcuts)
- Primitives: [cmdk](https://github.com/pacocoursey/cmdk) · [kbar](https://github.com/timc1/kbar) ·
  [reka-ui Listbox source, v2](https://github.com/unovue/reka-ui/tree/v2/packages/core/src/Listbox) ·
  [reka-ui `useFilter` source](https://github.com/unovue/reka-ui/blob/v2/packages/core/src/shared/useFilter.ts)
- This repo: `resources/js/components/ui/command/`, `resources/js/components/QuickSwitcher.vue`,
  `resources/js/components/switcher/SwitcherField.vue`

## Gaps and caveats

- **Linear publishes almost nothing.** The grouping and context-priority model is quoted from a 2019 changelog
  and has not been restated since, through at least two UI overhauls (2024 and 2026). The second-stage
  mechanism for "Assign to..." is *inferred* from the naming convention and from the contextual-menu changelog's
  claim that the command menu implements the dropdowns. It is not documented and was not verifiable.
- **Slack never states its reason for the split.** The structural argument above is assembled from the slash
  command payload contract and the Quick Switcher help article. Slack has not published a rationale, and the
  absence of any cross-reference between the two help articles is itself only suggestive.
- **Spotlight's parameter interaction is third-party.** Apple documents that actions can take parameters; the
  `Tab` / `Escape` / repeat-with-`Return` mechanics come from a walkthrough, not from Apple.
- **VS Code's "recently used" retention count** (commonly cited as 50) could not be confirmed in first-party
  documentation and is deliberately not asserted above.
- **cmdk's scoring constants** were read from the published `command-score` source; they are implementation
  detail and may move between versions.
- **reka-ui was read from the `v2` branch** of `unovue/reka-ui`, which is the default branch. This repo pins
  `reka-ui: ^2.10.1`, so the behaviour described should hold, but the exact source was not read at that tag.
- **Nothing here was verified by driving the real products.** Every behavioural claim is documentary.
