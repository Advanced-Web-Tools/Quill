# Quill editor

The editor combines a top bar, a narrow navigation rail, a block tree, an iframe canvas and a Properties inspector. All editing surfaces share one JavaScript state: a transport document containing sections and complete block trees.

## Navigation and page settings

The left rail opens Add block, Components, Page Styling, Page Actions and Color palettes. Page settings and Editor settings sit together at the bottom. Opening a destination closes the other left-side panel.

Page settings contains the page title, description and connected routes. Title changes appear in the top bar and are saved as Dashboard metadata. Styling controls page spacing, background and default font through `settings.styles`; blocks inherit the page font unless they define their own. Title and description use `settings.title` and `settings.description`. These edits preserve other settings and participate in dirty tracking, Undo/Redo, saving and snapshots. Root page styles generate body CSS; nested component page styles do not replace host-page styling.

The route list can connect an existing database route or create one without leaving the editor. URL segment editing is shared with the dashboard through `quill-route-builder.js`; existing endpoints handle validation, CSRF and reassignment. Moving another page's route requires confirmation. Route changes apply immediately, outside document JSON, page undo history and publication state. The updated routes and parameters become available to link and data controls.

## Blocks and canvas editing

Double-click text on the canvas to edit it directly. Input updates shared state and tree labels. Escape finishes editing. Enter and Shift+Enter insert `br` line breaks rather than new paragraphs. **Insert line break (Enter)** in the toolbar works at the cursor or replaces selected text. Empty lines and inline formatting survive Save and Undo/Redo; the temporary trailing caret helper is excluded from document data.

Add block displays categorized cards with Font Awesome icons and search. Category headings expand/collapse; search opens matching categories and clearing it restores their previous state. New blocks are inserted inside the selected element or at the end of the selected section. Selecting a section name clears block selection. Components uses the same dynamic picker filtered to component resources.

Layout presets include Vertical stack, Flexible row, Two columns, Three columns and Sidebar layout, with empty containers and a 24px gap. Flexible row wraps; grid presets use fixed column counts. Empty-container outlines and plus indicators belong only to the editor and disappear when content is added.

### Insertion, movement and deletion

Hover between blocks to show an insertion line with a plus button. Its orientation follows the actual flex/grid arrangement, wrapping and zoom. Clicking locks that location and opens Add block. Insertion preserves siblings, selects the new instance and creates one Undo step. Selection changes preserve the lock; closing the picker or pressing Escape releases it. Document changes cancel stale insertion locations.

Drag from the tree or canvas handle to reorder or nest blocks. In tree rows, the top/bottom quarter inserts before/after and the middle inserts as a child. Section labels accept root-level drops. Reorder and indent/outdent controls are also available. Void elements cannot contain children, and a parent cannot move into its own subtree.

During dragging, **Drop into main**, empty canvas space and the workspace background move a block to the end of the `main` section, or the first section if `main` is absent. Drop indicators never become document content.

The floating toolbar provides movement, parent selection, duplication and contextual text/layout/image/action controls. Its More menu offers Insert before/after, Move out of parent, Convert to component, Copy/Paste style and Delete block. Duplication refreshes HTML IDs and action-target identities. Deletion selects the parent or nearest remaining block. Properties also provides **Delete block** outside the settings form.

The toolbar stays within visible canvas bounds, retains its physical size at different zoom levels and moves below a block when needed. Tab, arrow keys and Escape support keyboard navigation.

### Style clipboard and history

Copy style stores authored inline and base/desktop/tablet/mobile declarations in memory for the current editor session. Copying leaves the document unchanged. Paste style replaces target styles in one Undo step while preserving content, attributes and action bindings. It is disabled until styles are copied. Clipboard data is not written to system clipboard, localStorage, network requests or document JSON.

Undo/Redo retains up to 100 entries. Use Ctrl/Cmd+Z and Ctrl/Cmd+Shift+Z; text inputs retain native undo. Continuous typing and input/range changes are grouped while they affect the same field, block and editing source within 800ms. Pauses, focus/selection changes, Save, structural commands and Undo/Redo end the group. Dirty tracking and revisions still record each committed change.

## Properties and responsive styles

Layout is the initial category and first built-in fallback. A user-selected category remains active while it is applicable to the next block. Controls apply on input; invalid values retain the previous valid state.

| Category | Controls |
| --- | --- |
| Layout | Arrangement, dimensions, linked margin/padding, gaps, flex/grid, item behavior, overflow and size limits |
| Appearance | Background, borders/corners, shadows and opacity |
| Typography | Font, paragraph, emphasis, letter case and wrapping |
| Interactions | Events/actions, cursor, pointer events and text selection |
| Position | Placement, pins, offsets, centering and transforms |
| Motion | Transitions and shared keyframe animations |
| Advanced | Identity, classes, HTML ID, attributes, props and renderer |

Heading, List, Table, Image, Icon and Link categories appear when relevant. Grid column/row counts create equal tracks; More options accepts custom arrangements. Opacity displays 0–100% and stores 0–1. Control labels describe visual results while APIs retain CSS property names and values.

### Device and CSS state scopes

**Apply on device** chooses All devices, Desktop, Tablet or Mobile. **When** independently chooses Default, Hover, Focused, Focus visible, Active, Disabled or Checked. Changing device preserves state and vice versa. Content and HTML attributes remain shared across scopes.

All devices edits common styles. The editor's default Desktop scope applies above 1024px, Tablet at or below 1024px and Mobile at or below 767px. Tablet styles inherit onto mobile unless overridden. Selecting a device also changes its preview. Reset removes a declaration only from the active scope.

The first responsive edit atomically moves inline styles into `styles.base` and records boundaries in `settings.breakpoints`. Responsive declarations use `styles.desktop`, `styles.tablet` and `styles.mobile`. State groups use `styles.hover`, `styles.focus`, `styles.focus-visible`, `styles.active`, `styles.disabled` and `styles.checked`; combined groups use keys such as `styles["mobile:hover"]`.

Export generates the matching media queries and pseudo-classes. The canvas previews a selected state without changing device width. Assigned values have a blue dot; overrides have a diamond. These markers describe assignment in the active scope, not full CSS cascade provenance.

**Start hidden** sets the shared HTML `hidden` attribute. The block remains accessible through Layers; a Show action reveals it.

### Spacing, positioning and transforms

Spacing controls arrange four sides around a diagram. The lock starts enabled on selection and updates all sides atomically. Supported dimensions and margins offer `auto`, hiding numeric input. Reset removes authored declarations so stylesheet values can apply again.

Position offers placement behaviors such as Pin to container, Pin to window and Stay in view. Absolute and Fixed positioning expose edge pins and independent horizontal/vertical centering. Clicking an edge toggles its offset between `0` and Auto. Pin all edges sets all offsets to `0` and width/height to Auto. Absolute uses the nearest positioned ancestor; Fixed uses the viewport. Relative and Sticky expose offsets.

Transforms include Move X/Y, Rotate, linked Scale X/Y, a 3×3 origin grid and advanced Skew X/Y. Bare move numbers imply pixels; other CSS units are accepted. Movement composes with centering. Edge pins disable centering on that axis. Local `--quill-center-x/y` and `--quill-move-x/y` declarations preserve that distinction. Rotate and Scale use independent CSS declarations; Skew retains other transform functions. Reset transform removes manual effects while keeping pin-based centering.

### Paint, images, gradients and shadows

Color controls share Coloris, palette buttons and theme styling. Empty/transparent paint has a decorative droplet-slash indicator and accessible description; it does not assign a fallback color. Palette choices update swatches immediately. Coloris is mounted in the active dialog so it remains interactive above the modal backdrop.

Background supports a paint swatch, an image URL dialog, Auto/Cover/Contain and position presets or manual offsets. Image controls appear when an image is present; URL changes update the canvas. This workflow does not include a media upload API.

Corner radius has four spatial corner buttons, a central lock and a value input. Its preview reflects current corner values. Gradient controls support Linear/Radial/Conic, angles, ordered color stops, alpha, preview and direct CSS entry. Text and background gradients are separate layers and can combine with background images. Use solid color restores ordinary paint; Reset removes the active-scope override. Border gradients use `border-image`, whose square edges do not follow corner radius.

Box and text/icon shadows support multiple layers, X/Y offsets, blur and color; box shadows also offer spread and inset. Add, remove, reset and custom CSS use the normal style/history flow. Shadow, text-decoration and gradient-stop colors require solid CSS colors rather than gradient images.

### Fonts and icons

Browse Google Fonts searches the local catalog by family, category and script, previews selected text and offers available weights and normal/italic styles. Choosing a font applies family, weight and style atomically in the active scope. Manual family input and system fonts remain available. Closing the dialog or changing block/scope removes temporary preview stylesheets. Offline previews use fallback fonts and offer Retry.

The metadata snapshot lives in `Quill/resources/google-fonts.json`; catalog search needs no API key and does not download every font. Refresh it with `python3 build/sync-google-fonts.py`. Only the preview candidate and document-used fonts load remote stylesheets.

Canvas and public export collect used families/variants across inheritance, bold/italic elements, responsive/state scopes and components. Requests are merged by family with `display=swap`; variable fonts support weights within their declared range. Automatic font dependencies are derived rather than stored in document assets, so removing a font removes its dependency. Manually added assets remain intact.

The Icon block uses Font Awesome Free 6.7.2. Its lazily loaded catalog supports name/keyword search, Solid/Regular/Brands filters, more results and Retry. Selection preserves unrelated classes. Accessible label distinguishes semantic icons (`role=img`, `aria-label`) from decorative ones (`aria-hidden=true`). The configured CDN stylesheet is included in preview and public rendering, including shared-component icons.

## Shared components

Inserting a saved, nondeleted component stores `kind: component` and `component_id`. Expanded content is preview data; page saves retain the reference rather than copying component content, styles or actions.

**Convert to component** saves the selected block and descendants under a name/description, then replaces the selection with a reference in one Undo step. The component immediately appears in the picker. Existing page actions targeting the original block retain their links through `externalTargets` aliases.

Shared instances have a purple outline, one tree entry and a toolbar notice linking to the source component editor. Their inner content and styles are edited at the source. Page editing moves the entire instance; inserting beside a selected component places the new block after it. Nested references remain independently shared components. The backend ignores preview subtrees when saving and rejects direct or indirect cycles before writing.

Saving/publishing components notifies other editors through BroadcastChannel. Previews also refresh on tab activation and every 30 seconds while visible. Refreshing preserves page edits, dirty/revision state and history; Undo/Redo does not restore an older shared-component preview. Instances retain independent identities and runtime action targets.

Published pages use the latest **published** component independently of page publication, including styles, assets and nested references. Component drafts remain private. A legacy component without a published pointer falls back to the snapshot captured with the host page. Historical/direct snapshot conversion retains its original manifests.

**Remove component instance** removes only the current document reference and supports Undo/Redo; the source remains in the library.

### Turn into block

**Turn into block** prepares a local copy through authenticated read-only `GET /dashboard/quill/editor-component/{id}/block`, then atomically replaces only the selected instance. Multiple roots become adjacent blocks without an HTML wrapper, and nested references also become local blocks.

`DetachedComponentDocument` uses the existing converter and editor metadata mapping. It preserves actions and target links, instance/block animation references, remapped legacy animations and dependent style/script assets. `editor.styleBreakpoints` preserves source responsive thresholds when the host page uses different widths. Generated preview classes are removed and recalculated during rendering.

Source version, page revision, selection and preview checks prevent a stale response from replacing newer local edits. Detachment is one Undo/Redo step and uses normal Save/Publish. The source and other instances remain unchanged.

## Connected data and links

Connect data in Properties, or the toolbar database shortcut, configures Single value, From route or Collection selection. Collection defaults to five records and supports Show 1, All records and sorting. The canvas retains authored fallback content; public rendering reads current data. Filter records adds typed conditions and nested All/Any groups with Apply/Cancel and history support. See [Connect data](connect-data.md) for validation and descriptor formats.

Rich-text selection tools also offer **Connect text to database**. A temporary adapter mounts the same controls; Cancel leaves the document unchanged. Apply connects only the selected fragment or updates its existing connection. Selecting a fragment within already connected text edits that connection. Formatting preserves inline descriptor metadata.

Content mappings support ordered fields plus optional Before/After text. `set.content.content` stores the first column and `set.content.additional` stores extra column names. Runtime joins nonempty scalar values with a space and applies `set.content.before`/`after` around resolved text. Missing records and invalid values retain authored fallback; empty affixes are omitted. See [Connect data](connect-data.md).

Link destinations include Web address, Page on this site, Email, Phone call, Text message, Calendar, Open an app and Section on this page. Email fields are URL-encoded and preserve existing headers such as cc. Calendar supports webcal subscriptions and `.ics`; app destinations include WhatsApp, Telegram, Google Maps, FaceTime and custom app links. Preview does not launch apps.

Open in offers browser default, `_self`, `_blank`, `_parent` and `_top`, with explanatory text. `_blank` adds `noopener` while preserving other relationship values. System/app destinations hide browser-window choices. More options includes relationship and file download with an optional filename.

`EditorRouteCatalog` reads live `IRouterManager::getRoutes()` data, including package and Quill routes, and exposes unique local GET destinations as `{path, name, parameters}` in `editor.routes`. It does not scan files or call controllers. Without a registry, manual URLs remain available. Parameters follow `SegmentPathMatcher` whole-segment syntax, such as `{slug}`. The picker requires parameter values and saves a concrete URL while preserving query/fragment data. `href`, `target`, `rel` and `download` use normal persistence.

## Actions and motion

Page Actions manages named actions with Edit, Duplicate and Delete. Each action has an entry trigger and ordered steps. Each step chooses a target (triggering element, parent, chosen element or an advanced CSS selector), an optional condition and an operation.

Operations include setting/resetting styles, adding/removing/toggling classes, Show, Hide, Toggle visibility and Remove element. Conditions check class, visibility or viewport and apply only to their own step. Steps can be reordered, duplicated and removed. Toggle visibility preserves previous display behavior, including flex/grid and `!important`; Show removes Start hidden.

Choose on canvas temporarily closes the dialog for target selection; Escape/Cancel returns to it. Form edits remain temporary until Save action, which commits the catalog and binding atomically. Cancel makes no document edit. Deleting an action removes its bindings; Undo restores both. Page Save/Publish persists the result.

The catalog is `settings.actions`, containing `{id,name,trigger,key?,steps}`. Steps use `{id,target:{type,value?},condition:null|{type,value?},operation:{type,property?,value?}}`. Bindings use `block.editor.onClick`, `onDoubleClick`, `onMouseEnter`, `onMouseLeave`, `onFocus`, `onBlur`, `onChange`, `onSubmit` and `onKeyDown`. Stable targets use `block.editor.actionTargetId`.

Supported triggers are `click`, `dblclick`, `mouseenter`, `mouseleave`, `focus`, `blur`, `change`, `submit` and `keydown`. Interactions offers matching action selectors. Keydown supports any key or named keys; repeat events are skipped. Focus/keyboard targets should be focusable. Enter/leave ignore movement within descendants. An assigned submit action prevents ordinary form submission. Changing an action trigger moves bindings atomically; a conflicting binding rejects the change without overwriting existing assignments.

The server validates definitions, references and operations before saving. Public rendering includes JSON configuration and local `quill-actions-runtime.js` only when needed. The runtime uses delegated events without eval or inline JavaScript. Generated `data-quill-action`/`data-quill-target` attributes are render-only. Canvas clicks select blocks; actions run on public pages. Missing targets are skipped; Remove element affects only the live DOM.

### Transitions and visibility effects

Motion → Transitions offers Animate changes, Duration, Motion and Delay. Configuring Default animates changes to/from Hover or Focus. Controls store ordinary responsive transition declarations. Reset animation preserves state appearances. Canvas/public CSS respects reduced motion.

Show, Hide and Toggle visibility offer None, Fade, Slide and Scale effects. Duration/delay range from 0–10000ms; Slide distance is 0–500px with direction. Timing presets are Smooth, Gentle, Spring and Steady. Toggle may share an effect or use a separate Disappear effect. Preview animation uses a dialog sample without editing the document.

`operation.animation` optionally stores `{effect,duration?,delay?,easing?,direction?,distance?,exit?}`. `exit` uses the same shape without another `exit`; absent exit settings reuse the main effect. Existing actions without animation remain immediate. Client/server validation checks values, limits and supported operations.

The runtime uses Web Animations. Show reveals before animating; Hide applies hidden after completion. Effects preserve authored opacity/transforms and layout. Rapid toggles use the latest requested state and current appearance without repeating delay. Later action steps do not wait for effects. Cleanup cancels effects and applies the last requested state. Reduced motion, zero duration or missing API support produces immediate visibility changes.

Shared keyframe animations are separate database resources selected through Motion's active list and +/− controls. See [Animation Studio](animation-studio.md) for isolated preview, timeline, library replacement and Save/Discard behavior. Missing animation references do not prevent page rendering.

## Saving and publication

Save/Publish is a split button. Choosing Save draft or Publish from its arrow menu immediately runs that action once; the main button repeats the chosen method. The arrow itself has no selected state. Editor settings controls the default method, initially Save draft. Changing that preference does not save the document. Autosave always saves drafts.

`POST /dashboard/quill/document/{type}/{id}` accepts `_token`, `document`, `lock_version` and `mode` (`draft` or `publish`, default `draft`). Authentication and CSRF are required, and the page/component must already exist.

`EditorDocumentMapper` validates transport through the real converters. In a transaction, `EditorDocumentSaver` checks the optimistic lock, writes new Quill blocks, remaps local references to database IDs and updates the working document. Existing blocks and published snapshots are preserved; cleanup of unreferenced rows is separate.

Publish creates page/component and block snapshots with manifests, then sets `published_snapshot_id`. Pages use draft/published status; components use the published pointer without a separate status column. Earlier snapshots remain available. Failures roll back the transaction. Version conflicts return 409 and invalid input returns 422. Failed saves retain local edits; edits made during a request remain dirty.

`_quill` transport metadata carries definition keys, structured styles, editor metadata and references separately from HTML attributes. Generated scope classes are recalculated rather than accumulated in storage. Ordinary property styles use inline declarations until responsive/state editing requires structured scopes. Page settings and assets survive the save flow. Shared references remain references; URPC source/override saving requires a separate pipeline.

Save and background library/component requests accept successful redirected JSON. Login redirects and HTTP 401 report expired authentication; unrelated HTML/server failures report their actual failure. CSRF/conflict messages are preserved. A valid returned lock version is required before clearing dirty state.

## Preview, appearance and preferences

Desktop (1440px), Tablet (768px) and Mobile (390px) have real iframe viewport widths. Fit scales to available workspace; 100%/75%/50% affect only visual scale. Smaller previews are centered and larger ones can scroll horizontally. Resizing recomputes Fit without changing document widths, selection, dirty state or breakpoint settings.

`editor.ui.viewport.getViewport()` returns `{width,scale,device}`. `viewportchange` signals dimension changes; computed properties refresh without rebuilding the canvas. Local property edits rebuild only the affected subtree where possible, retaining unrelated DOM listeners and stylesheets. Structural/global edits, changed preview assets, active resizing and subtrees containing shared components use a full-render fallback. Render operations run in order against current state.

Preview does not run descriptor processors or script assets. CSS stays inside the iframe; links/forms do not navigate away. These restrictions do not rewrite saved attributes.

Shared light/dark tokens live in `quill-editor-tokens.css`, including iframe editor overlays without changing authored page appearance. Green identifies actions/insertion, blue selection/context, purple shared components and amber conditions/pending edits. Notifications stack above viewport controls without moving the canvas. Keyboard focus and reduced-motion preferences are supported. See [Editor design](editor-redesign.md) for design details and historical validation.

Editor settings provides default preview device, System/Light/Dark appearance, default save method and optional autosave. Autosave starts disabled; enabled intervals are 10–3600 seconds, default 60. It saves only dirty, visible documents while no save or editing dialog is active. It uses normal CSRF, locks and revision handling, preserves the manual save-method choice and suppresses automatic success popups. Errors retain ordinary manual-save behavior.

Preferences use browser localStorage key `awt.quill.editor.preferences.v1`, outside document JSON, Undo history and server state. Without storage, they apply for the current session. Escape/Close/Done returns focus to the settings control. Default preview does not change responsive editing scope; theme choice affects editor chrome and overlays.

## Implementation and verification

State, canvas, viewport, properties, registry/module loading, tree, picker, API, history and saving live in separate `quill-editor-*.js` collaborators. `quill-editor.js` wires them together. Built-in controls are registered modules under `js/editor/properties/`; package integrations use the same [extension API](editor-extensions.md).

```sh
php tests/editor_save.php
php tests/editor_actions.php
php tests/editor_components.php
php tests/shared_components.php
php tests/published_components.php
php tests/detach_component.php
php tests/editor_links.php
python3 tests/editor_browser.py --screenshot /tmp/quill-editor.png
python3 tests/editor_save_controls_browser.py
python3 tests/editor_interaction_browser.py
python3 tests/detach_component_browser.py
```

PHP suites use real models/controllers with isolated SQLite databases. Chrome suites use a temporary profile and local fixtures; save responses are simulated there, while PHP verifies persistence, CSRF, conflicts and rollback. Configure `CHROME_BIN`, `AWT_FRAMEWORK_CLASSES` and `URPC_PACKAGE_ROOT` when needed. The [data](connect-data.md), [animation](animation-studio.md) and [palette](color-palettes.md) guides list additional focused checks.
