---
paths:
  - "app/Modules/**/Livewire/**"
  - "app/Modules/**/resources/views/**"
  - "resources/views/**"
  - "resources/js/**"
---

# Livewire 4 + admin UI

- Livewire is pinned at 4 (`^4.3`): check idioms against the v4 docs (`search-docs`) — Livewire 3 habits have caused real bugs.
- Public actions are endpoints: authorize + validate in each; for new actions prefer `#[Authorize]` over a per-method `abort_unless(Gate::allows(...))`.
- Before naming a public action, check `Livewire\Component` has no method of that name (`transition` → FatalException; renamed `applyTransition`).
- A gate that decides on a field the same request can rewrite (a public property such as `categoryId`) evaluates the STORED entity, or refuses the change — never the field after persisting it.
- A per-request memo read from PHP → a private/protected property, not `#[Computed]` access via `$this->x` (Larastan `property.notFound`).
- New code may use Livewire 4 tools: `#[Computed]` for expensive derived data, `wire:show`, `wire:intersect`, form objects for new multi-step forms, islands only for a measured slowdown. Refactoring green code needs evidence and is its own gated change.
- JS ↔ Livewire:
  - `Livewire.find(id)` returns `$wire` itself — never chain `.$wire`.
  - `$wire.$set(name, value)` is live by default (one request per keystroke) — never use it for editors.
  - Third-party JS talks to Livewire only through the DOM: content out = a hidden `<input data-rich-sync wire:model>` outside `wire:ignore` + dispatch `input`; action in = `.click()` on a hidden `wire:click` button (`[data-rich-action]`). Reference: `resources/js/admin-rich-editor.js` + `rich-editor.blade.php` (the host component needs `WithMediaLibraryPicker`).
  - A PHP `dispatch()` is one bubbling DOM event — listen through ONE path (`Livewire.on` or a DOM listener), never both.
- Confirm dialogs use SweetAlert2 through the Alpine magic `$bsConfirm` (`resources/js/sweet-confirm.js`), never `wire:confirm`: `x-on:click="$bsConfirm({ text: @js(__('…')), confirm: @js(__('…')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.method(@js($arg)))"` — `danger` defaults to true.
- Tests: after `->call()`, assert rendered HTML with `assertSee`/`assertSeeHtml`, not `assertSeeInOrder` (it falls through to the JSON response with non-ASCII escaped).
- Design fidelity: port handoffs (`docs/design_handoff_*/`) 1:1 — exact icons, labels, tokens, spacing and states; never substitute an icon. Admin icons are inline SVG in `app/Modules/Admin/resources/views/components/icon.blade.php` (Remix fills live in `$fills` as `'ri-<name>' => '<d>'`); take the exact path from `https://cdn.jsdelivr.net/npm/remixicon@4.9.1/icons/<Category>/<name>.svg` (a wrong category 404s).
- Jodit:
  - `import 'jodit'` loads only the 21 core plugins; import any other from `jodit/esm/plugins/<x>/<x>.js`. A button whose plugin is missing still renders but falls back to native `execCommand` or does nothing.
  - Table cell menus need `select-cells`.
  - Always set `sourceEditor: 'area'` (the default loads ACE from a CDN at runtime — CSP/white-label).
  - `askBeforePasteHTML`/`defaultActionOnPaste` need the `paste` plugin.
  - The sanitizer must allow what resizer/indent write (`hr`, `table[style]`, `margin-left`, `padding-left`, `width`, `height`).
