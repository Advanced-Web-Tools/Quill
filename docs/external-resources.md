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
            $event->pageRegistry->add(new ExternalDocument(
                key: 'Shop:catalogue', name: 'Product catalogue', provider: 'Shop',
                document: $document, description: 'Catalogue supplied by Shop.', url: '/products',
            ));
        }
        if ($event instanceof ExternalComponentsRequestEvent) {
            $document = new URPCDocument();
            $document->addBlock('main', new URPCBlock(new URPCBlockDescriptor('article')));
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

- Dashboard **Pages → Other** shows registered pages and their providers. **Create local copy** creates a normal Quill draft, then opens its editor. It does not overwrite a provider's page, copy route assignments, or publish anything.
- Dashboard **Components → External** lists provider components. They are also available in the editor's Components picker. Inserting creates editable local blocks using the normal insertion/history path; these copies do not track subsequent provider changes.
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

The replacement is applied strictly after Quill → URPC conversion and before final rendering. It does not replace `page_json`, change the Quill schema, or introduce a per-block override save pipeline. Save/publish and snapshots continue to operate on the original Quill document.

No migrations are required. These registries do not replace the existing editor block/property registries or database libraries.

## Connect data layout

The Connect data panel now gives grid tracks and controls explicit shrinkable bounds, wraps long labels/routes and constrains radio fieldsets. The same rules apply to the rich-text connection popup; content composition and query behavior are unchanged.

Validation: tests were not run, as requested.
