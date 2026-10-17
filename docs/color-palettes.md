# Named local and global color palettes

**Page settings → Styling → Color palettes** manages named collections. A page
can have several local palettes and can choose colors from the global library.
New palettes are **local**. Each collection has a name and named solid/alpha
colors or gradients. Color names must be unique within that collection; the same
color name can exist in different palettes.

**Make global** explicitly saves a local palette to the shared database library.
After success, it replaces the local collection with the global resource in the
picker. If it was the page default, that choice follows the global resource.
Other pages can then browse it. If the local collection changes while the network
request is pending, the changed local version is preserved.

**Make default** chooses the collection that opens first for this page. The setting
belongs to the page and participates in Undo/Redo, draft/save/publish and snapshots.
A default may be local or global. Defaults missing from the library fall back to
Explore without breaking the picker or page rendering.

## Color picker

All existing palette buttons (text, backgrounds, shadows, gradient stops and page
styling) share one popup. Without a default, it lists **Local palettes** and
**Global palettes**, with names, counts and preview swatches. Choosing a palette
slides/fades its colors into the same popup. **Explore** returns to the collection
list. Navigation respects reduced-motion preferences. With a default, the popup
opens its colors directly and keeps Explore available.

Choosing a swatch copies its value through the existing property callback, so
breakpoint/state editing, alpha, solid-only shadow/gradient-stop restrictions,
and graphical gradient editing remain intact. Changing or deleting a palette
never recolors values that have already been applied to elements.

Local names/colors are document edits. Global edits offer **Save as new**,
**Replace**, or Cancel before any shared write. Replacement preserves database
identity, original creator and creation date and checks the loaded version.
Deletion asks **Are you sure?**; missing remote defaults fall back to Explore.
Global library writes take effect immediately. Page-local changes/defaults follow
the usual page Save lifecycle; undoing a promotion does not erase the shared copy.

Animation Studio browses local/global swatches through its isolated adapter.
Palette editing and Make default/Make global are unavailable there; choosing a
color records animation keyframes without modifying the page or library.

## Data

`Migration` adds `quill_color_palette` with `name`, `palette_json`, creator
`admin_id`, creation/update dates, optimistic `lock_version`, and soft-delete date.
The JSON stores `{ "name": "Brand", "colors": [...] }`. Authenticated endpoints
use `/dashboard/quill/editor-color-palette` (GET list, POST create), `/{id}`
(GET load, POST replace), and `/{id}/delete` (POST). Writes require CSRF; replace
and delete require the current `lock_version`.

Page settings add:

```json
{
  "color_palettes": [
    {
      "id": "qpal-brand", "name": "Page brand",
      "colors": [{ "id": "qp-primary", "name": "Primary", "value": "#ff0066" }]
    }
  ],
  "default_color_palette": { "scope": "local", "id": "qpal-brand" }
}
```

A global default is `{ "scope": "global", "id": 12 }`; no default is `null`.
Pages do not embed global definitions. Limits: 20 local collections, 100 saved
colors per collection. Client/server validation rejects duplicate identities and
names, malformed references and unsafe paint values.

Existing `settings.color_palette` remains readable/writable through the legacy
API and appears as **Page colors** (`local:qpal-legacy`). No conversion occurs
merely by opening an old document. Explicit rename/promotion can move that legacy
collection into the new model while preserving saved color identities.

## Extension API and checks

`editor.palette.button(...)` and the flat `list()`, `save(name,value,id?)`,
`remove(id)` contract remain available. Named collections add `createLocal(name,
colors?)`, `locals()`, `collections()`, `refresh()`, `globals()`, `colors(reference?)`,
`defaultPalette()`, `makeDefault({scope,id})`, and async `makeGlobal({scope,id})`.
Load the global library with `refresh()` before choosing a global default by API.
`change` signals collection/default/library updates. Applying a color still uses
the callback supplied by its property control; no block mutation API is replaced.

`php tests/color_palette_library.php` checks real CRUD, validation, authorship,
local/global defaults, snapshots, old flat palettes and applied-value preservation.
`python3 tests/color_palette_library_browser.py` checks creation, default opening,
Explore/navigation, promotion, shared Save as new/Replace/cancel, deletion,
missing-default fallback, reduced motion and Animation Studio isolation.
