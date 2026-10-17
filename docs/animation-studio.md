# Animation Studio and shared animation library

Select an element, open **Motion → Keyframe animations**, and use **+** to open
the adjacent animation selector. The list shows shared database animations,
search, **New animation**, Edit, and Delete. Choosing an animation adds its
reference to the active list. Select an active row to enable **−**, Edit and its
trigger selector. Minus removes the element reference; it does not delete the
library resource. Arrow keys navigate the active list; Enter edits the selection.
Page-level shared component instances expose the same controls in their notice.

**New animation** opens an isolated preview. A red **Recording property changes**
badge stays visible. Visual property changes create/update keyframes at the
playhead. Linked controls, origin grid, radius diagram, colors, gradients,
background positioning, shadows and extension controls use the shared inspector
through a dedicated adapter. Unsupported CSS properties and normal content,
attribute, structure, action and scope changes are excluded. Discrete CSS values
such as gradients switch rather than interpolate.

Each track shows its keyframe count. A single-keyframe track displays
**1 keyframe · Add another**, with guidance to move the playhead and change the
property. Missing endpoints use the element's original appearance. The hint
updates after adding, removing, undoing or redoing keyframes. Select a diamond
to edit its time/value/easing; drag it or use Arrow keys (10ms; Shift: 100ms).
Delete removes the selection. Studio has separate temporary Undo/Redo. Duration
changes rescale keyframe times; settings include delay, iterations, direction,
fill and easing. Translate/scale axes have independent typed CSS tracks.

## Save, replace and discard

New animations save to the shared library and optionally bind to the selection.
Editing an existing shared animation offers **Save as new**, **Replace**, or
Cancel. Save as new preserves the original resource and swaps the edited element's
reference to the copy. Replace keeps the database ID and affects all references,
including published pages; the choice dialog explains this. Optimistic version
checks reject stale replacement/deletion. Creator and creation date are retained.

**Save Animation** persists the library definition immediately, then commits only
the selected element's ID/trigger references to page editing state. Save draft or
Publish persists those references using the usual page lifecycle. A library
resource remains reusable even if a page reference is later undone or discarded.
**Discard Animation** rejects the temporary session and closes without writes.
Closing a dirty workspace offers Save, Discard, or Continue Editing. Network or
version errors retain the session for retry, copying, or discard.

Deleting a library item requires **Are you sure?** confirmation. Existing element
references may remain and appear as Unavailable; they can be removed with minus.
A missing/deleted animation is silently skipped at render time. Other animations
and the element itself continue rendering.

## Persistence and rendering

`Migration` adds `quill_animation` on package install/update with `name`,
`animation_json`, creator `admin_id`, `created_at`, `updated_at`, plus optimistic
`lock_version` and soft-delete `deleted_at`. The JSON stores the validated existing
Animation Studio definition: identity/name/timing/settings and property tracks.

Canonical block metadata contains references only:

```json
{
  "animations": [
    { "animation_id": 12, "trigger": "load" },
    { "animation_id": 25, "trigger": "hover" }
  ]
}
```

Editor transport carries this optional list under `_quill.animations`. Definitions
are never embedded in block styles, responsive overrides, state overrides or
component data. Shared instances may carry independent references. Limits are 20
references per block, one per resource ID. Triggers: load, click, hover, focus.

`AnimationLibrary`, `QuillAnimation` and authenticated CSRF-protected editor
endpoints manage resources at `/dashboard/quill/editor-animation`: GET lists,
POST creates, GET/POST `/{id}` loads/replaces, POST `/{id}/delete` deletes.
Replacement/deletion require `lock_version`; creator identity comes from the
authenticated admin. New resources and ordinary page saves remain separate.

`AnimationContentSource` is an optional conversion source capability for resolving
global IDs. Working, snapshot, editor-validation and archived sources implement
it. `BlockAnimations` skips missing records, compiles independent per-property
CSS keyframes and composes multiple resources for the same trigger into animation
lists with their own timings. Generated classes/attributes are render-only and
stripped from editor transport. Export honors reduced motion. Published pages get
CSS and a tiny click runtime only; Studio, timeline and picker code stay editor-only.

Existing `settings.animations`/`animation_bindings` remain compatible and appear
in the selector as legacy page animations. Editing and saving one creates a shared
resource and replaces the selected legacy binding; other legacy uses are retained.

## Isolation

`QuillAnimationStudio` acquires a central editing lease and makes the mounted main
editor inert. Computed appearance, ancestor dimensions, fonts, variables, pseudo
content and viewport context are copied to a sandboxed preview iframe without
page scripts/actions. Preview changes never reach the normal property mutation
system. The original canvas remains mounted and is not rebuilt by session edits.
The controlled lease commits references only after successful library persistence.

`QuillAnimationSession` owns temporary values, playback and history.
`QuillAnimationAdapter` supplies copied block context and preview-only callbacks
to `QuillEditorProperties`, with `editingContext: {type: 'animation', isolated: true}`.
Modules retain their registration and mount/update/destroy contract. Palette
browsing is available; global palette editing and page defaults are read-only in
Studio. Snapshot previews support up to 3000 nodes; live media/canvas state is not
reproduced. Component definition editors do not open page Animation Studio.

## Checks

- `php tests/animation_library.php`: real API/database CRUD, validation, optimistic
  conflicts, reference persistence, components, snapshots, multiple animations,
  missing/deleted resources, canonical block separation.
- `python3 tests/animation_library_browser.py`: selector and active controls,
  Save as new/Replace/cancel, deletion confirmation and unavailable references.
- `python3 tests/animation_studio_browser.py`: preview/document isolation, shared
  graphical controls, keyframes, local history, single-keyframe hints, recording
  badge, property filtering, extension callbacks and slider pointer interaction.
