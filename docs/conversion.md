# Quill and URPC document conversion

Conversion uses Quill models and the installed URPC PHP API. It does not render documents, run processors, execute code from JSON or assign URPC UIDs.

| Namespace | Responsibility |
| --- | --- |
| `Quill\services\conversion\quillToUrpc` | Quill → URPC conversion and transport serialization |
| `Quill\services\conversion\urpcToQuill` | Structural URPC → Quill import |
| `Quill\services\conversion\shared` | Shared JSON validation and `ConversionException` |

## Quill → URPC

```php
use Quill\models\QuillPage;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;
use Quill\services\conversion\quillToUrpc\UrpcJsonSerializer;

$page = new QuillPage();
$page->selectByID(1);

// Example application configuration, not Quill defaults.
$converter = new QuillToUrpcConverter(['tablet' => 900, 'mobile' => 600]);
$result = $converter->convert(new WorkingQuillContentSource($page), 'Quill/page/1');

$document = $result->document; // IURPCDocument for the caller's URPC renderer.
$assets = $result->assets;     // ['styles' => [...], 'scripts' => [...]]
$css = $result->scopedCss;     // CSS to include separately.

$serializer = new UrpcJsonSerializer();
$transport = $serializer->serializeDocument($document);
$loaderJson = $serializer->serializeSection($document, 'main');
```

For a `main` section referencing block `[101]`, where block `101` is an `h1` element containing `Heading`, the transport is:

```json
{"schema_version":1,"sections":[{"name":"main","blocks":[{"descriptor":{"name":"h1","props":{},"renderer":""},"attributes":{},"content":"Heading","children":[]}]}]}
```

Serializing only `main` produces the format accepted by the existing URPC loader:

```json
{"blocks":[{"descriptor":{"name":"h1","props":{},"renderer":""},"attributes":{},"content":"Heading","children":[]}]}
```

The `sections` wrapper is Quill transport, not URPC loader input. PHP documents are built directly without passing through this transport. Empty props/attributes serialize as objects; nested props objects remain `stdClass` and arrays remain arrays.

The source key is assigned through `setSourceKey()`. Its default is `Quill/` followed by the source identity. Supply a stable page key, as above, to retain a namespace across revisions.

### Working content and snapshots

Snapshot conversion takes an already loaded, specific snapshot, not a current revision number:

```php
use Quill\models\QuillPageSnapshot;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;

$snapshot = new QuillPageSnapshot();
$snapshot->selectByID(42);
$result = $converter->convert(new SnapshotQuillContentSource($snapshot), 'Quill/page/1');
```

Both sources also accept their corresponding component model as the root document. The snapshot source follows `quill_*_snapshot_block` and `quill_*_snapshot_component` manifest links and verifies ownership. Nested components receive their own snapshot scope. Missing or ambiguous links are errors, with no fallback to working content.

The working source resolves current, nondeleted blocks and components through filtered model queries. Decoded content and resolved components are cached for one conversion call; each instance receives an independent tree. Public rendering adds a separate latest-published-component policy, described in [Public pages](public-pages.md). Ordinary snapshot conversion retains the recorded manifests.

### Input, assets and styles

Input requires `schema_version: 1`, ordered `sections` and integer ID references. The initial `{}` stored by the creation controller is incomplete and fails direct conversion because it lacks `schema_version`; the editor and public page provider handle new empty documents separately. Section names must be unique and nonnumeric because URPC stores them as PHP array keys.

Assets are ordered lists of nonempty strings in `styles` and `scripts`. Component assets are appended in first-use order without duplicates. Page settings remain metadata; supported page styles generate CSS without adding page elements.

Style maps use CSS property names and string/number declaration values. The converter supports responsive and state scopes used by the editor. Each styled block instance receives a deterministic class based on its namespace and full instance path, independently of its URPC UID. Responsive CSS needs breakpoint configuration; the standalone converter supplies no default widths. Unknown scopes and invalid declarations are rejected. See [Editor](editor.md) for viewport/state semantics and detached component breakpoint metadata.

There is no bindings resolver or trusted QuillSetting validate/apply registry in this pipeline. `QuillBlockFeatures` accepts empty `bindings` and `custom_settings` objects and reports missing integration, including the block path and identity, for nonempty values. `definition_key` is metadata, never a class name to instantiate. `kind=override` and page `source_type=urpc` require a separate override pipeline and produce explicit errors here.

## URPC → Quill

`UrpcToQuillConverter` reads `IURPCDocument` through its getters and returns an in-memory result containing a Quill document, separate block documents and original URPC identities. It does not access the database, load models or invoke `saveModel()`, loaders, renderers or processors.

```php
use Quill\services\conversion\urpcToQuill\UrpcToQuillConverter;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;
use URPC\api\URPCDocument;

$heading = new URPCBlock(new URPCBlockDescriptor('h1', [], '', 'Application/page/1'));
$heading->setBlockContent('Heading');
$document = new URPCDocument();
$document->addBlock('main', $heading);

$result = (new UrpcToQuillConverter())->convert($document);
$pageJson = $result->serializeDocument();
$blockJson = $result->serializeBlock(1);
```

`$pageJson`:

```json
{"schema_version":1,"sections":[{"name":"main","blocks":[1]}],"assets":{"styles":[],"scripts":[]},"settings":{}}
```

`$blockJson`:

```json
{"schema_version":1,"kind":"element","descriptor":{"name":"h1","props":{},"renderer":""},"attributes":{},"content":"Heading","children":[],"styles":{"base":{},"tablet":{},"mobile":{}},"bindings":{},"custom_settings":{},"editor":{"label":"h1","locked":false}}
```

`$result->blocks` maps local IDs to `stdClass` blocks. `$result->identities` maps local IDs to `['uid' => ..., 'source_key' => ...]`. Original UIDs, including empty UIDs, remain unchanged. Local IDs start at 1 on each call and identify references only within that result. **They are not database IDs.** A persistence service must allocate real IDs and remap section and child references; it may then populate the model's `urpc_uid` from the identity map.

Traversal follows section, root and child order. Repeated uses of one URPC object receive separate local IDs and deeply copied JSON data. Cycle detection follows the active object path; reuse in a different branch is allowed. Errors identify the section, path and existing UID, or `unassigned`. The default maximum block depth is 128 and can be configured in the constructor.

Descriptors, props, attributes and text remain unescaped structural data. Props do not become HTML attributes, renderer strings are not executed, and no new UIDs are calculated. Empty objects and arrays remain distinct. Input must be JSON-compatible; resources, arbitrary PHP objects, cyclic props and invalid UTF-8 are rejected.

URPC does not contain Quill registry definitions, component references, bindings, structured responsive styles, assets or page settings. Import creates ordinary `kind=element` blocks, leaves the corresponding maps empty and does not invent a `definition_key`. Existing classes and inline styles remain attributes; CSS is not parsed into Quill style maps. Import does not reconstruct components or an override pipeline.

### JSON input

The same converter accepts either a URPC loader wrapper or Quill transport with full blocks:

```php
$converter = new UrpcToQuillConverter();
$result = $converter->convertJson('{"blocks":[{"descriptor":{"name":"p"},"content":"Text"}]}', 'main');
$result = $converter->convertJson('{"schema_version":1,"sections":[{"name":"main","blocks":[]}]}');
```

The second format is the transport exported by `UrpcJsonSerializer`, not a stored Quill document containing ID references. Unknown transport fields are rejected to avoid silent data loss. Neither JSON format carries original URPC UIDs/source keys; pass an `IURPCDocument` to preserve them. The JSON reader preserves nested types without using a loader that would assign UIDs.

## Verification

```sh
php tests/conversion.php
php tests/urpc_to_quill.php
php tests/save.php
```

The conversion suite uses real models, SQLite and the URPC API/loader. `AWT_FRAMEWORK_CLASSES` and `URPC_PACKAGE_ROOT` override checkout paths. It covers recursion, ordering, sections, components, CSS instances, cycles/depth, snapshot manifests, JSON types, assets, unsupported integrations and invalid input. The section export is loaded by the real URPC loader without rendering.

The reverse-conversion suite needs neither a database nor framework bootstrap. It checks local references, original identities, processor isolation, deep copies, both JSON formats, validation and round trips through the real URPC classes and converters.
