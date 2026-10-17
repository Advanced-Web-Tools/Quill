# Public Quill pages and URPC

`PublicPageController` extends the URPC renderer. A dynamic route passes a Quill page model to `setPage()`, and the controller installs `QuillPageDocumentProvider` through `setDocumentProvider()`. The document is created during rendering, using the framework Context supplied by the runtime. Registering routes does not convert every page in advance.

## Selecting page content

The provider reloads the current page through the same database connection on each render. When `published_snapshot_id` is set, it uses that snapshot and its manifests and checks that the snapshot belongs to the page. Missing snapshots or blocks do not fall back to working content. Later page drafts therefore leave the published page content unchanged.

Published pages resolve shared components to their latest published versions through `PublishedComponentContentSource`. A component draft remains private. If a legacy component has no published pointer, rendering retains the component snapshot captured when the host page was published. Direct snapshot conversion, history and archives retain their original manifests.

When the page has no `published_snapshot_id`, an explicitly connected route renders its working content. Route registration requires a connected, nondeleted page and applies no additional status filter. A new page whose initial `page_json` is `{}` renders empty. Invalid documents and missing data produce explicit errors rather than silently rendering empty content.

## Rendering lifecycle

Conversion creates an `IURPCDocument` with source namespace `Quill/page/{id}`. URPC then handles document copying, UID assignment, `BeforeRenderEvent`, processors and escaping. The controller includes stylesheet assets and generated scoped CSS before the content and script assets after it. SEO and description metadata do not create additional page elements.

```php
use context\Context;
use Quill\controllers\PublicPageController;
use Quill\models\QuillPage;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;

$page = new QuillPage();
$page->selectByID(1);
$controller = new PublicPageController();
$controller->setContext(new Context('/path/to/Quill', 'Quill', '1'));
$controller->setPage($page, new QuillToUrpcConverter(['tablet' => 900, 'mobile' => 600]));
$html = $controller->index()->render();
```

The breakpoint widths above are application configuration. A default converter has no responsive breakpoint values; supply a configured converter when those widths are needed. The application runtime supplies the global AWT event dispatcher and Context required by URPC.

## Custom documents and providers

Inherited `setDocument()`, `setDocumentProvider()`, `clearDocument()`, `getDocument()`, `setRendererFactory()` and `setSourceNamespace()` remain available. A directly assigned document takes precedence over a provider; `clearDocument()` reactivates the selected provider. A direct document or external provider does not inherit assets from the previous Quill page. Calling `setPage()` selects a new page provider and clears any previously assigned direct document.

Use `PageDocumentRequestEvent` to replace the complete URPC document after Quill → URPC conversion and before rendering. See [External resources](external-resources.md) for the event, asset retention and resource registries. This runtime replacement is separate from saving per-block overrides.

## Verification

`php tests/public_page.php` exercises the real renderer, events, processors and dynamic route against models in an isolated SQLite database. Coverage includes snapshots and working content, assets/CSS, escaping, stable UIDs, repeated rendering, custom documents/providers, page switching, refreshed data and errors without fallback.

`php tests/published_components.php` covers independent component publication, nested/repeated instances, draft isolation and historical snapshot preservation.
