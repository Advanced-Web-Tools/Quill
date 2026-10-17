# Quill editor extensions

Each editor request creates fresh registries and dispatches two events through the existing AWT event dispatcher. Built-in definitions are registered before extensions on a dispatcher copy scoped to that request.

| Event | `bundle()` entry | Type |
| --- | --- | --- |
| `quill.editor.blocks.request` | `blockRegistry` | `Quill\api\editor\BlockRegistry` |
| `quill.editor.properties.request` | `propertyRegistry` | `Quill\api\editor\PropertyRegistry` |

Listeners mutate the registry object supplied by the event; their returned arrays are not catalogs. Both registries expose `add($definition, $replace = false)`, `get($key)`, `remove($key)` and `all()`. Duplicate keys require explicit replacement. Extensions may also replace or remove built-in definitions.

## Registering blocks

Register a listener in your package runtime, where `$this->eventDispatcher` is the application dispatcher:

```php
use event\interfaces\IEvent;
use event\interfaces\IEventListener;
use Quill\api\editor\BlockDefinition;
use Quill\api\events\EditorBlocksRequestEvent;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;

$this->eventDispatcher->addListener(EditorBlocksRequestEvent::NAME, new class implements IEventListener {
    public function handle(IEvent $event): array
    {
        $card = new URPCBlock(new URPCBlockDescriptor('article'));
        $heading = new URPCBlock(new URPCBlockDescriptor('h2'));
        $heading->setBlockContent('Card heading');
        $card->addBlockChild($heading);
        $event->bundle()['blockRegistry']->add(new BlockDefinition(
            'MyTheme:card', 'Card', 'fa-solid fa-address-card', 'A card with a heading', $card, category: 'Content'
        ));
        return [];
    }
});
```

`content` accepts an `IURPCBlock`, an `IURPCDocument`, or JSON in the existing `{"blocks":[...]}` format or section transport. The real `UrpcToQuillConverter` imports the definition without rendering, running processors or changing source objects/UIDs. Multiple roots are inserted in order into the selected element or section. Every instance receives independent tree and props copies.

The picker groups block cards by category. `icon` contains Font Awesome classes, such as `fa-solid fa-address-card`; empty or unsupported values fall back to `fa-solid fa-cube`. The description appears as a tooltip. `category` defaults to `Basic`; search includes descriptions and categories. The definition key becomes Quill's `definition_key`, and the name becomes the editor label.

## Registering property controls

```php
use event\interfaces\IEvent;
use event\interfaces\IEventListener;
use Quill\api\editor\PropertyDefinition;
use Quill\api\editor\PropertyElement;
use Quill\api\events\EditorPropertiesRequestEvent;

$this->eventDispatcher->addListener(EditorPropertiesRequestEvent::NAME, new class implements IEventListener {
    public function handle(IEvent $event): array
    {
        $event->bundle()['propertyRegistry']->add(new PropertyDefinition(
            key: 'MyTheme:card-properties',
            category: 'appearance',
            elements: [
                '/awt_packages/MyTheme/assets/card-spacing.js',
                new PropertyElement(
                    '/awt_packages/MyTheme/assets/card-detail.js',
                    '/awt_packages/MyTheme/assets/has-detail.js'
                ),
            ],
            condition: '/awt_packages/MyTheme/assets/is-card.js',
            categoryLabel: 'Appearance',
            icon: 'fa-solid fa-palette'
        ));
        return [];
    }
});
```

Categories become inspector tabs, and several definitions may share one category. `elements` is an ordered list of module URLs or `PropertyElement` objects. The optional category icon uses Font Awesome classes and falls back to `fa-solid fa-sliders`.

A definition condition applies to its whole group; an element condition applies only to that element. A `null` condition matches every selected block. No selection means no property controls.

Module URLs must identify `.js`/`.mjs` ES modules, optionally with a query string. Supply browser URLs such as `/awt_packages/...`, not filesystem paths. Cross-origin modules require CORS support. The catalog comes from trusted PHP listeners, not saved client documents; it does not evaluate code or instantiate class names from JSON.

Example `is-card.js`:

```js
export default block => block?.definition_key === 'MyTheme:card';
```

Conditions receive a copied Quill block with `schema_version`, `kind`, optional `definition_key`, descriptor, attributes, content, children, styles, bindings, custom settings and editor metadata. Children are full canvas-state blocks, not database IDs. The second argument is the shared editor API. Return a boolean or `Promise<boolean>`; other results are errors. Conditions should be pure functions.

## JavaScript control lifecycle

Example `card-spacing.js`:

```js
export default function ({ container, editor, signal }) {
    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = 'e.g. 24px';
    input.setAttribute('aria-label', 'Card padding');
    input.addEventListener('input', () => {
        try {
            editor.setStyle('padding', input.value);
            editor.reportError(null);
        } catch (error) {
            editor.reportError(error);
        }
    }, { signal });
    container.append(input);

    return {
        update(block, { selectionChanged }) {
            if (input === document.activeElement && !selectionChanged) return;
            const style = document.createElement('div').style;
            style.cssText = block.attributes.style || '';
            input.value = style.padding;
        },
        destroy() {
            // Dispose library instances or other resources, if the control created them.
        },
    };
}
```

The default export is a mount function and may be asynchronous. It receives `container`, `editor` and an `AbortSignal`. Return `{update, destroy}`, a cleanup function, or nothing. `update` receives the new block snapshot and `{path, selectionChanged}`. Register listeners with `signal` and release other resources in `destroy`. Updates reflect state in the UI; user events perform mutations.

Conditions are checked when selection, blocks or registries change. Controls remain mounted during ordinary input. When a condition becomes false, a definition is removed/replaced or selection disappears, the host aborts listeners and calls `destroy`. Late asynchronous results cannot restore a previous block's panel. Module errors identify their definition key without disabling unrelated controls.

## Shared editor API

`window.QuillEditor` is the `QuillEditorApi` instance supplied to modules as `editor`. The browser event `quill.editor.ready` exposes it as `event.detail.editor`. Legacy `window.quillEditor` remains the state object for compatibility.

| API | Purpose |
| --- | --- |
| `editor.state`, `editor.selectedPath` | State, selection and local revision |
| `editor.getDocument()` | Copy of the transport document for saving |
| `editor.getBlock(path)`, `getSelectedBlock()` | Copied block for controls and conditions |
| `editor.select(path)` | Select a block |
| `editor.updateBlock(patch, path?)` | Validated block/metadata mutation |
| `editor.setContent(text, path?)` | Update text |
| `editor.setAttribute(name, value, path?)` | Set an attribute; `null` removes it |
| `editor.styleViewport`, `setStyleViewport(viewport)` | `all`, `desktop`, `tablet` or `mobile` scope |
| `editor.styleState`, `setStyleState(state)` | `default`, `hover`, `focus`, `focus-visible`, `active`, `disabled` or `checked` |
| `editor.styleScope` | Active declaration group, such as `base` or `mobile:hover` |
| `editor.getStyleDeclarations(path?)`, `setStyleDeclarations(cssText, path?)` | Read/replace the active declaration group atomically |
| `editor.setStyle(property, value, path?)` | Update/remove a CSS declaration and refresh preview |
| `editor.setProps(object, path?)` | Replace descriptor props |
| `editor.insertBlock(key, sectionIndex, parentPath?, index?)` | Insert a definition copy at an optional index; default is the end |
| `editor.blocks`, `editor.properties` | Runtime `add/get/remove/all` registries; the second `add` argument enables replacement |
| `editor.on(event, listener, options?)` | Subscribe and receive an unsubscribe function |
| `editor.copyStyle(path?)`, `pasteStyle(path?)`, `hasCopiedStyle` | Session style clipboard; paste is one atomic edit |
| `editor.duplicateBlock(path?)`, `removeBlock(path?)` | Structural edits with selection, Undo and canvas refresh |
| `editor.save('draft' \| 'publish')` | Existing CSRF/version-aware save flow; returns a Promise |
| `editor.canvas`, `editor.ui` | Canvas and UI collaborators |

Mutations default to the selected block. They validate central state, mark it dirty and use the ordinary preview/save flow. Mutating a returned copy or canvas DOM alone is not a saved edit. Props remain descriptor data and do not automatically become HTML attributes.

For custom props previews, register a renderer:

```js
const unregister = editor.registerRenderer('MyTheme:card', ({ element, block }) => {
    element.setAttribute('data-layout', block.descriptor.props.layout || 'default');
    // Optionally return a cleanup function for DOM resources created by this renderer.
});
```

Renderers run synchronously after normal DOM mapping and may return cleanup functions. They rerun on state changes. `unregister()` removes the hook and refreshes the preview. `editor.canvas.getElement(path)` returns the current element, which later rendering may replace. Subscribe to `change`, `selectionchange`, `saved` and `canvasrendered` with `editor.on`, optionally using a signal.

## Built-in modules and categories

Built-in controls use the same registration and lifecycle as package extensions. Their keys include `Quill:layout`, `Quill:style`, `Quill:text`, `Quill:heading`, `Quill:list`, `Quill:table`, `Quill:clickable`, `Quill:link`, `Quill:image`, `Quill:icon`, `Quill:display`, `Quill:motion` and `Quill:advanced`.

Layout is the first/default category, followed by Appearance and conditional content categories. Position, Motion and Advanced complete the built-in order. An available user-selected category remains active across selection changes. Custom registry order still applies.

| Registry/category key | Visible category and controls |
| --- | --- |
| `Quill:layout` / `layout` | Layout: arrangement, dimensions, linked spacing, gaps, flex/grid, item behavior, overflow and limits |
| `Quill:style` / `style` | Appearance: paints, borders, corners, shadows and opacity |
| `Quill:text` / `text` | Typography |
| `Quill:clickable` / `clickable` | Interactions: events/actions and pointer preferences |
| `Quill:display` / `display` | Position: placement, pins and transforms |
| `Quill:motion` / `motion` | Motion: transitions and animation controls |
| `Quill:advanced` / `advanced` | Advanced identity, attributes, props and renderer |

`showCategory('image')` and `showCategory('clickable')` retain their toolbar behavior. Conditions keep controls relevant to the selected block. Layout/Appearance/Position inspect computed element and parent styles; flex and grid options are conditional.

Heading blocks use H1–H6 and the heading control changes their level. Lists use normal `ul`/`ol`/`li` trees; controls change item count, markers, numbering start/direction and individual item values. Tables use `table`/`thead`/`tbody`/`tr`/`th`/`td`; row count includes the header. Reducing counts removes trailing items, rows or columns while preserving remaining content. Structure changes are atomic Undo/Redo edits. Resizing a table with merged cells requires removing the merges first.

State, canvas, selection, saving, registry loading and property lifecycle have separate collaborators. `quill-editor.js` wires them together.

## Actions, fonts, palettes and routes

`editor.ui.actions` exposes `open(id?, bindPath?, duplicate?)`, `assign(id, path?, trigger?)`, `remove(id)` and a read-only `actions` getter. Definitions and bindings are ordinary state edits with Undo/Save/Publish. `QuillActions.mount(root, config)` installs the declarative runtime and returns cleanup; `config` contains `actions` and optional `breakpoints`.

`editor.ui.blockToolbar` owns the floating toolbar. `editor.ui.properties.showCategory(key)` asynchronously opens a category and returns its panel. `styleclipboardchange` signals clipboard availability; copied styles stay out of document JSON and the browser clipboard.

`editor.fonts` exposes the local Google Fonts catalog and `find(cssFamily)`, `weights(font, italic)`, `variant(font, weight, italic)`, `url(family, variants)` and `stylesheets(document)`. Controls that change standard font declarations automatically participate in dependency collection and Save/Publish.

The legacy palette API is `editor.palette.list()`, `save(name, value, id?)` and `remove(id)`. Saving without an ID creates a color; passing an ID updates it. `change` signals palette changes. `editor.palette.button({current, apply, signal, gradients, label})` returns a shared picker button: `current()` returns the CSS value and `apply(value, isGradient)` applies the choice. Abort closes the picker when its control disappears. `gradients: false` restricts choices to solid colors.

Named collection methods and global library behavior are documented in [Color palettes](color-palettes.md). Applying a swatch copies its value through the control callback.

`editor.routes` contains bootstrap route entries `{path, name, parameters}` from the framework registry. They are not document data; links save their final `href` through the attribute API.

## Animation editing context

[Animation Studio](animation-studio.md) reuses visual modules through an isolated adapter. Check `editor.editingContext?.type === 'animation'` for workspace-specific behavior. Read through `getStyleDeclarations()` and write through `setStyle()` or `setStyleDeclarations()`; these callbacks affect only the isolated preview and keyframe session. `editor.supportsProperty(property)` reports supported styles.

Use the supplied context rather than capturing the global editor or mutating external DOM/state. Content, structural edits, ordinary attributes and document saving are unavailable in this adapter. Palette resources and page defaults are read-only; choosing swatches records animated property changes.

Shared animation references use optional `block.animations` metadata, transported as `_quill.animations`, with entries `{animation_id: number, trigger: string}`. In normal editing, use `editor.state.setAnimationBindings(path, references)`. Definitions remain database resources rather than structural styles.

## Connected data

Connect data uses descriptor renderer `Quill\services\rendering\ConnectedDataRenderer`; blocks with their own renderer remain outside this category. `ConnectionRecords` is the query-provider interface, and `ModelConnectionRecords` uses framework Model/ModelCollection.

The visual builder stores a versioned `descriptor.props.query` filter tree. `ConnectionQuery` validates it; `ConnectionQueryBuilder` extends only operations absent from the framework builder. The property UI applies drafts through the same editor adapter. See [Connect data](connect-data.md) for descriptor contracts and URPC integration.

## Verification

`php tests/editor_extensions.php` and `python3 tests/editor_browser.py` exercise real event listeners, URPC conversion, the persistence mapper and Chrome fixture modules, including condition and lifecycle failures.
