# External pages, palettes and components

Quill asks packages for resources through three request-scoped events:

| Event | Registry property | Definition |
| --- | --- | --- |
| `Quill\api\events\ExternalPagesRequestEvent` | `pageRegistry` | `Quill\api\resources\ExternalDocument` |
| `Quill\api\events\ExternalColorPalettesRequestEvent` | `colorPaletteRegistry` | `Quill\api\resources\ColorPaletteDefinition` |
| `Quill\api\events\ExternalComponentsRequestEvent` | `componentRegistry` | `Quill\api\resources\ExternalDocument` |

Register an `IEventListener` in the provider package's `main()`, using the framework dispatcher. Registration is read-only and can happen more than once across requests; listeners must not insert database records. Each definition requires a name, provider name and a stable, provider-qualified key. Registries support `add($definition, replace: true)`, `get($key)`, `remove($key)` and `all()`; accidental duplicate keys throw.

```php
use event\interfaces\{IEvent, IEventListener};
use Quill\api\events\{ExternalPagesRequestEvent, ExternalComponentsRequestEvent, ExternalColorPalettesRequestEvent};
use Quill\api\resources\{ExternalDocument, ColorPaletteDefinition};
use URPC\api\{URPCDocument, URPCBlock, URPCBlockDescriptor};

$listener = new class implements IEventListener {
    public function handle(IEvent $event): array
    {
        if ($event instanceof ExternalPagesRequestEvent) {
            $document = new URPCDocument();
            $heading = new URPCBlock(new URPCBlockDescriptor('h1'));
            $heading->setBlockContent('Product catalogue');
            $document->addBlock('main', $heading);
            (new \URPC\service\BlockIdentity())->assignDocument($document, 'Shop/catalogue');
            $event->pageRegistry->add(new ExternalDocument(
                key: 'Shop:catalogue', name: 'Product catalogue', provider: 'Shop',
                document: $document, description: 'Catalogue supplied by Shop.', url: '/products',
            ));
        }
        if ($event instanceof ExternalComponentsRequestEvent) {
            $document = new URPCDocument();
            $document->addBlock('main', new URPCBlock(new URPCBlockDescriptor('article')));
            (new \URPC\service\BlockIdentity())->assignDocument($document, 'Shop/product-card');
            $event->componentRegistry->add(new ExternalDocument(
                key: 'Shop:product-card', name: 'Product card', provider: 'Shop', document: $document,
            ));
        }
        if ($event instanceof ExternalColorPalettesRequestEvent) {
            $event->colorPaletteRegistry->add(new ColorPaletteDefinition(
                key: 'Shop:brand', name: 'Shop brand', provider: 'Shop', colors: [
                    ['id' => 'qp-brand', 'name' => 'Brand', 'value' => '#2277ee'],
                    ['id' => 'qp-hero', 'name' => 'Hero', 'value' => 'linear-gradient(90deg, #2277ee, #6633cc)'],
                ],
            ));
        }
        return [];
    }
};
foreach ([ExternalPagesRequestEvent::NAME, ExternalComponentsRequestEvent::NAME, ExternalColorPalettesRequestEvent::NAME] as $name) {
    $this->eventDispatcher->addListener($name, $listener);
}
```

Pages and components require an actual `IURPCDocument`, not a file path or rendered HTML. Components require exactly one root block in one section. Documents use the existing URPC → Quill converter and Quill validation; processors are never executed while importing or editing. The authenticated preview uses the normal URPC renderer, where processors execute normally. An optional `url` is an existing local path; registration does not create routes or publish pages.

## Where resources appear

- Dashboard **Pages → Other** shows registered pages and their providers. **Edit in Quill** opens a source-bound editor. It creates only a lightweight source owner when needed, never a copy of provider blocks. Save records UID property patches and sparse structural operations; publish commits that change set. Reopening reuses the owner.
- Dashboard **Components → External** lists provider components with Edit in Quill. The Components picker contains insertion choices only. Select an instance to use Edit in Quill in Properties, above its removal action and separator. New instances persist a registry-key reference, with no provider child copies. The editor shows a shared preview; its content is edited through the source editor. Public pages expand references using the latest published external component overrides, so a component publish does not require republishing host pages.
- The palette popup has an **External** section. Provider names appear under definitions. External palettes support solid colors, gradients, Explore and Make default, including Animation Studio's isolated picker. They are provider-owned: rename, edit and delete are unavailable. Choosing a color copies its value, as with other palettes.

External palette defaults use the existing `default_color_palette` field with `{ "scope": "external", "id": "Shop:brand" }`. Existing local/global defaults remain supported. An unavailable provider default falls back to Explore; existing element colors remain intact.

## Replacing a Quill page's runtime document

`Quill\api\events\PageDocumentRequestEvent` (`quill.page.document.request`) is dispatched after selecting the draft/published Quill source and before URPC's normal copy, identity assignment, connected-data processing and render events. A listener can inspect `$event->page` and `$event->draft`, read `getDocument()`, or call `setDocument($replacement)` with an `IURPCDocument`.

```php
if ($event instanceof \Quill\api\events\PageDocumentRequestEvent
    && $event->page->getKey() === $configuredPageId) {
    $event->setDocument($replacementDocument);
}
```

This is a runtime replacement: no database writes, document edits, history entries or publication changes occur. Original-tree generated CSS, scripts and actions are omitted for a full replacement. When wrapping/reusing the converted tree, call `setDocument($replacement, retainPageAssets: true)` to retain its styles and actions. Editing and returning the same document instance retains these automatically. Direct `PublicPageController::setDocument()` and custom providers retain their existing precedence. Replacing the document through URPC's `BeforeRenderEvent` also omits stale Quill assets; prefer the Quill event so URPC can copy and assign identities to the replacement normally.

The replacement is applied strictly after Quill → URPC conversion and before final rendering. The runtime replacement does not modify `page_json` or the saved Quill tree. Source-bound editing uses the separate UID override pipeline described below. Save/publish and snapshots continue to operate on the original Quill document.

No migrations are required. These registries do not replace the existing editor block/property registries or database libraries.

## Connect data layout

The Connect data panel now gives grid tracks and controls explicit shrinkable bounds, wraps long labels/routes and constrains radio fieldsets. The same rules apply to the rich-text connection popup; content composition and query behavior are unchanged.

## Source-bound UID editing

`Quill\services\editor\ExternalPageOverrides` implements the external page/component save and render adapter. Existing Quill-owned documents retain their normal persistence path.

- `open($definition, $adminId, $kind = 'page')`: reuse/create a lightweight `QuillPage` owner with `source_type=urpc` and unique `source_key`. Component owners use a separate reserved namespace and are excluded from page route assignment. Opening creates no `quill_block` rows.
- `load($owner)`: temporary full editor transport assembled from the current provider document and draft overrides. Original UIDs travel in `_quill.editor.urpcUid`; `sourceRevision` is a digest of the provider source.
- `save($owner, $document, $version, $mode, $adminId, $sourceRevision)`: validate source revision, unique identities, provider-owned section names and all ordinary Quill property/style data. Persist changed UID records with leaf-level `changes`, insertions, removals and placements; unchanged provider children are never copied. Repeated unchanged saves reuse records. The page manifest references only change records.
- `render($definition, $owner = null, $draft = false, $kind = 'page')`: a `ConversionResult` containing an isolated provider tree with the chosen override set applied, including CSS/assets/actions from normal Quill conversion. Public mode never falls back to a working draft. Missing target UIDs are skipped and their override records are retained for provider recovery.
- `publishedComponentDocument($definition)` and `componentSource($definition)`: published-only component preview/conversion. The latter has a unique source scope for each registry key. Component instances are scoped through the existing Quill component conversion context.
- `restore(...)`: restore an override snapshot into draft without changing live state.

Use `render($definition, kind: 'component')` for a bridge rendering a component outside a Quill-owned page, and consume its conversion metadata through the existing render integration. Register the **original provider document**, not a document already patched by this same adapter.

The existing `/dashboard/quill/import-external-page` POST endpoint now opens the source-bound editor rather than making a copy. `/dashboard/quill/open-external-component` does the same for a component. Both require CSRF. Existing saves to `/dashboard/quill/document/page/{id}` require `source_revision` for a source-bound owner, alongside lock version. Invalid/stale source revisions reject the request before writes. The editor sends it automatically.

Provider documents must already have unique, nonempty original URPC UIDs. Use the identities established by the provider/URPC loader; the simple registration example uses URPC's own identity API. Quill does not invent matching UIDs from element order or HTML IDs. A provider with missing/duplicate IDs cannot be edited safely and receives a clear validation error.

Changed rows use the existing `quill_block.urpc_uid`, `kind=override` / `kind=insert` JSON records and `quill_block_snapshot.urpc_uid`. Published manifests snapshot the override set, not the original tree. Parent changes do not freeze unchanged descendants. Reset removes the draft reference; publishing that reset removes the live override. Historical rows remain available to older snapshots.

Native Quill block/property extension mechanisms remain active, including insertion, duplication, drag-and-drop, deletion, rich-text line breaks and inline formatting. Properties, responsive/state block styles, actions and animation bindings retain the ordinary controls. External components keep exactly one root: add children inside its container. Page-wide body styling and page route assignment remain unavailable for external components. Providers changing while the editor is open require reload before save; unsaved local edits are retained on the rejected save.

### Structural operations

`ExternalStructureOverrides` implements the structural part of the adapter; bridges must reuse `ExternalPageOverrides::render()` rather than interpreting database rows themselves.

- Original blocks keep the provider UID. New blocks and duplicates receive stable `quill-added:<UUID>` identities in the editor, including new rich-text inline nodes. Duplicating never reuses the original UID.
- A `kind=insert` record contains only that new block's canonical data, `children: []`, and a `placement`. Descendants have their own insertion records; shared components store references, never their preview children.
- A placement contains `section`, nullable `parent_uid`, and ordered `after_uids` / `before_uids` fallback anchors. Section names/order remain provider-owned. Only actual moves are recorded; deleting a sibling does not create moves for every following original block.
- Existing blocks can carry a placement alongside their property `changes`. An explicit `operation=remove` hides that provider UID and its remaining descendants. A child moved out of a removed container survives. Absence of a property patch alone never means deletion.
- Rendering starts from a fresh provider tree, applies property patches, creates Quill additions, resolves placements parent-first, and applies removals. Unmodified provider descendants and newly introduced provider blocks remain live.
- A missing neighbor falls back to another saved neighbor, then the end of the destination container. Missing parents/sections hide affected additions and moves while retaining their records. The editor displays a warning. Saving while a target is unavailable preserves recoverable hidden records. Cyclic placements are withheld.
- Draft/publish, optimistic locks, snapshots and restore cover the complete operation set. Unchanged saves reuse records. Publishing a removal does not delete provider data; resetting/restoring operations changes only the chosen Quill version.

Example insertion record (internal adapter storage, not a standalone Quill document):

```json
{
  "schema_version": 1,
  "kind": "insert",
  "urpc_uid": "quill-added:example-uuid",
  "placement": {
    "section": "main",
    "parent_uid": "hero",
    "after_uids": ["hero-title"],
    "before_uids": ["hero-description"]
  },
  "block": {
    "schema_version": 1,
    "kind": "element",
    "descriptor": {"name": "p", "props": {}, "renderer": ""},
    "attributes": {},
    "content": "Added in Quill",
    "children": [],
    "editor": {"urpcUid": "quill-added:example-uuid"}
  }
}
```

`ExternalComponentReference` is a Quill conversion marker in an ordinary element descriptor, with its registry key in props. It is expanded before final URPC rendering; no editor preview children are serialized as source data. Missing component providers render no replacement content but preserve a recoverable reference in the editor. Legacy instances inserted as plain local blocks before this change remain independent because they have no trustworthy source reference to migrate automatically.

No migration is required for an up-to-date Quill schema: the UID, source and snapshot columns already exist. These operation records are consumed by the source-bound adapter before normal conversion; they are not a new structural document schema.

Validation: `php tests/external_page_overrides.php`, `python3 tests/external_resource_editor_browser.py`, and dashboard/normal-editor regression checks cover sparse UID/structural persistence, all 720 mixed sibling permutations, nested moves, draft/published separation, provider refresh, rollback, references, source isolation, editor entry points and normal editing.

Structural-edit validation: 83 external resource checks (including 720 sibling permutations and 150 nested layouts), 19 external-editor Chrome checks, and 49 native line-break/component Chrome checks passed. Existing native component persistence and public rendering suites also pass. The broader rich-text suite still fails its selection color/highlight assertion; the failure was reproduced with the unchanged baseline toolbar as well.
