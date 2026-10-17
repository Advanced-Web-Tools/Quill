# Editor visual refinement

The redesign keeps the existing editor structure, control factories, command handlers,
and extension lifecycle. AWT Quill retains its green action accent; blue identifies
selection/editing context, purple identifies shared components, and amber distinguishes
conditions and pending document changes. Theme defaults to the operating system and
can be overridden in Editor settings.

## Architecture audit and implementation boundary

The audit covered the editor template, editor stylesheet, shell wiring, all editor UI
collaborators and property modules/conditions, built-in definitions and extension
registries, and the state/API boundaries used by those controls. Existing documentation
and PHP/Chrome fixtures establish the persistence, conversion and extension contracts.

| Area | Implementation and configuration | Preserved boundary |
| --- | --- | --- |
| Shell | `Quill/views/editor.awt.php`, `quill-editor.js` | Existing IDs, canvas/sidebar structure and command wiring |
| Blocks | `DefaultEditorDefinitions`, PHP `BlockRegistry`, JS `quill-editor-registry.js`, block picker | Dynamic add/replace/remove, categories, search, external definitions |
| Inspector | PHP `PropertyRegistry` / `PropertyDefinition` / `PropertyElement`, `quill-editor-properties.js`, module loader | Conditions, asynchronous mounting, update/destroy, abort handling, errors, dynamic tabs/order |
| Position | `display.js`, `position-controls.js` | Placement, pins, centering, offsets, linked scale, rotation, 3×3 origin, advanced transforms |
| Layout (default) | `layout.js`, spacing/dimension/segmented/visual controls | Arrangement, size, linked spacing, gaps, flex/grid, item settings, overflow and size limits |
| Appearance | `style.js`, spacing/background/paint/shadow controls | Spatial corner preview, background position grid/manual offsets, gradients/stops, palettes, borders, shadows and opacity |
| Motion | `motion.js`, `motion-control.js` | State transitions, duration, timing, delay and reset |
| Typography | `text.js`, `font-control.js`, `heading.js` | Font catalog/search/variants, text formatting and heading levels |
| Content | `list.js`, `table.js`, `structure-controls.js`, image/attribute controls | Native structures, row/item settings, attributes and accessible labels |
| Interaction | `clickable.js`, `link.js`, `link-destinations.js`, actions UI | Trigger assignment, route/system/app destinations, window/download controls |
| Icons | `icon.js` | Font Awesome search/filter/preview, style/size/color and supported attributes |
| Advanced | `advanced.js` | Identity, classes, HTML ID, custom attributes/props and renderer |
| Document controls | Page settings, fonts and palette collaborators | Title, description, page styling, document palette and actions |
| Canvas | Canvas, viewport, tree, inserter and block toolbar collaborators | Nested DnD, orientation detection, insertion lock, selection, resize, zoom, toolbar/menu positioning and keyboard commands |
| State/runtime | State/API/history/save/components/actions runtime, PHP mapper/saver and converters | Schema, scope semantics, undo units, snapshots, component resolution, action execution and URPC conversion |

The implementation plan was to consolidate tokens, restyle existing primitives, share
tokens with iframe overlays, and expose existing editing context. No registries, control
factories or runtime systems were replaced. No dependencies were added.

## Visual changes

- Shared surface, text, semantic color, spacing, typography, radius, elevation, control
  height, icon size and panel-width tokens live in `quill-editor-tokens.css`.
- Light/dark tokens apply to the shell and editor overlays. Loading the token stylesheet
  inside the iframe does not set the page's color-scheme, font, foreground or background.
- The canvas remains flush with the shell; narrower sidebars and restrained panel chrome
  leave the document visually dominant. Compact desktop widths retain the three columns.
- Toolbar buttons, segmented controls, tabs, dialogs, block cards and inspector sections
  use consistent surfaces, corner radii, hierarchy and focus treatment.
- Device/state selectors retain their current meaning. A compact context line names the
  current scope. Assigned regular properties use a blue dot; overrides use a diamond;
  unmarked fields use inherited/default appearance. Reset tooltips name the active scope.
- Existing linked controls retain the lock and spatial layout, with a clearer linked
  state. Corner, anchor, origin and background controls retain their original geometry.
- Action Builder preserves the trigger/step/condition/target/operation model. Target,
  condition and action sections now have headings; conditions have an amber inset border.
- Saved/unsaved feedback keeps its existing text and gains a small state indicator derived
  from `state.dirty`. Manual save behavior is preserved; optional automatic draft saves reuse that path.
- Keyboard outlines remain visible in both themes, including text inputs and Coloris.
  Reduced-motion rules remain; no new editing animation was added.

## Changed components / files

All paths below are relative to the package root:

- `Quill/views/assets/css/quill-editor-tokens.css` (new): shared tokens for both documents.
- `Quill/views/assets/css/quill-editor.css`: shell, panels, controls, dialogs and feedback.
- `Quill/views/editor.awt.php`: token stylesheet and accessible inspector context/help.
- `Quill/views/assets/js/quill-editor.js`: presentation-only dirty-state indicator.
- `Quill/views/assets/js/quill-editor-canvas.js`: token stylesheet and overlay colors only.
- `Quill/views/assets/js/quill-editor-block-toolbar.js`: toolbar styling only.
- `Quill/views/assets/js/quill-editor-inserter.js`: inserter styling only.
- `Quill/views/assets/js/quill-editor-properties.js`: scope description only.
- `Quill/views/assets/js/editor/properties/controls.js`: value-source markers/reset tooltips only.
- `Quill/views/assets/js/quill-editor-actions.js`: visual section labels only.
- `tests/editor_browser.py`: adaptive visual assertions and combined-document theme checks.
- `docs/editor.md`, this document: updated visual-system documentation.

## Validation

Run the existing local suite with:

```sh
python3 tests/editor_browser.py --screenshot /tmp/quill-editor-redesign.png
php tests/editor_extensions.php
php tests/editor_actions.php
php tests/editor_components.php
php tests/editor_save.php
php tests/conversion.php
php tests/urpc_to_quill.php
```

The Chrome suite exercises the existing block/property catalog, extension lifecycle,
linked values/corners, constraints/transforms, background/gradient/palette/icon editors,
nested/component DnD, contextual insertion orientation, toolbar positioning across zoom,
responsive states, actions/animation, keyboard behavior, undo/redo, and mocked save/publish.
The added combined document contains nested columns, text, a button, an icon, a shared
component, responsive/state overrides, a gradient, a transform and an animated action.
Light/dark switching checks overlay consistency, text contrast, visible focus, document
and revision stability, compact layout, and Action Builder sections. PHP tests cover
real conversion, validation, model/controller persistence and extension registration.

Completed validation (2026-10-16): **416 Chrome checks passed**, including the combined
light/dark document checks. PHP extension, action, component, editor-save, link,
shared-component, save, Quill-to-URPC and URPC-to-Quill suites passed. The editor template
passed PHP syntax validation. Light/dark editor and Action Builder screenshots were
visually reviewed.

## Remaining limits and separate work

- Value-source markers describe assignment in the **active scope**, using the existing
  style API. They do not claim full CSS cascade provenance. Graphical compound controls
  retain their existing resets/locks; they do not gain per-subproperty provenance badges.
- External packages using ordinary inputs and shared classes inherit the styling. Controls
  with their own hardcoded CSS or isolated shadow roots need their package to adopt tokens.
- The existing editor minimum width remains 760px. Touch/mobile shell navigation would
  require a separate interaction design; document mobile preview remains fully available.
- Theme defaults to system preferences. Editor settings also provides explicit Light and Dark modes.
- Browser save/publish/component network responses are fixture responses; production auth,
  network failures and deployment/CDN behavior still need an application smoke test.
- The follow-up request adds browser preferences and optional automatic draft saves. No schema,
  conversion, action, backend persistence, history or component-resolution contract changes are included.

## Editor settings and typography follow-up

The toolbar gear opens a focused settings dialog with default Desktop/Tablet/Mobile
preview, System/Light/Dark appearance, and an opt-in auto-save switch. The interval field
appears only while enabled (10–3600 seconds, default 60). Preferences live in browser
localStorage, outside document JSON. Preview defaults do not change responsive editing
scope; explicit appearance reaches the shell and iframe editor overlays.

`quill-editor-settings.js` owns preference validation, UI events and the timer. It invokes
the existing save collaborator in draft mode only for dirty, visible documents while no
save or editing dialog is active. A small `automatic` option in `quill-editor-save.js`
keeps the user's Publish selection and suppresses automatic success popups; errors,
CSRF, optimistic locks and edits during requests retain existing behavior. Automatic
saving is cancelled when disabled or on page hide and rescheduled on page show.

Editor headings and emphasis use shared medium weight (500), with normal control text
at 400 and a consistent system sans-serif fallback stack. Authored page typography and
Font Awesome glyph weights remain independent. Shared checkbox/radio geometry and
inline-label rules fix full-width input and block-label overrides in inspector controls,
including animations, links, shadows, table/list settings, and package-provided labels.

Additional changed files: `Quill/views/assets/js/quill-editor-settings.js` (new) and
`Quill/views/assets/js/quill-editor-save.js`. Existing template, token stylesheet,
editor stylesheet, shell wiring, iframe theme propagation and browser tests are updated.
The browser tests cover preference persistence, explicit theme precedence, default-view
isolation, interval validation, auto-save concurrency/failures and checkbox/text alignment.

Follow-up validation: **435 Chrome browser checks passed**, including 19 settings,
auto-save and checkbox-alignment checks. PHP editor-save, action and extension suites
also passed. Light/dark settings screenshots were visually reviewed.

## Toast placement follow-up

Notifications share an absolute canvas dock with the viewport/zoom controls. Toasts
stack above the controls with an 8px gap and align to their right edge; they shrink to
fit compact canvas widths without changing canvas layout. Neutral surfaces, shared
radius/type tokens, subtle elevation, small semantic icons and aligned dismiss buttons
match the editor chrome. Existing alert/status roles, dismissal and save timers remain.
Targeted Chrome verification passed 37 checks, including wide/compact positioning in
both themes and the existing editor redesign interactions.

## Inspector category follow-up

Layout is now the default built-in category. It groups arrangement, dimensions,
linked margin/padding, gaps, flex/grid alignment, individual item behavior, overflow,
and size limits. Appearance contains Background, Border and corners, Shadows and Opacity.
Position retains placement, pins and visual transforms; Motion is a new category for
state transitions. Text, Clickable and Details are labeled Typography, Interactions and
Advanced. Contextual image/icon/link/heading/list/table categories remain available.
Interactions presents events/actions before pointer preferences. All fields retain their
existing factories and style/data semantics. Registry keys, category IDs and external
registration/conditions/lifecycle remain compatible; no document data is changed by
navigation. The existing default-selection algorithm uses registry order and keeps an
available user-selected category across block selections.

Changed files: `DefaultEditorDefinitions.php`, `layout.js`, `style.js`, `display.js`,
`clickable.js`, new `motion.js` and `conditions/motion.js`, browser/extension test
expectations, and editor documentation. No state, save, conversion, action execution or
component-resolution code is changed for this reorganization.

Category reorganization validation: **438 Chrome checks passed**, including default
selection, category persistence, property ownership, specialized controls, responsive
editing, extension lifecycle and unchanged document data. PHP editor extension, save
and action checks passed; light/dark inspector screenshots were reviewed.
