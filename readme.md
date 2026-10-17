# Quill for AWT CMS

Quill is a visual page and component editor for AWT 27. It stores structured JSON documents and renders public pages through URPC. The package includes a standalone editor, reusable components, revision snapshots, draft/publish workflows, database routes, shared animations and color palettes.

## Getting started

Install Quill through the AWT package installer, then open **Dashboard → Quill** at `/dashboard/quill`. Create a page or component there before editing and saving it.

- Page editor: `/quill/editor?type=page&id=1`
- Component editor: `/quill/editor?type=component&id=1`

Omitting `id` opens an empty editor; saving requires an existing page or component. The dashboard also provides route management, personal templates, version history and access to resources supplied by other packages.

Quill uses the existing Dashboard login and framework authentication, routing, database and event services. Dashboard is the dependency declared in the package manifest; public rendering and document conversion use the URPC API available in the AWT installation.

## Documentation

| Guide | Contents |
| --- | --- |
| [Editor](docs/editor.md) | Blocks, responsive styles, components, actions, saving and preferences |
| [Connect data](docs/connect-data.md) | Database mappings, route parameters, collections and visual query filters |
| [Animation Studio](docs/animation-studio.md) | Isolated keyframe editing and the shared animation library |
| [Color palettes](docs/color-palettes.md) | Named local/global palettes, defaults and extension APIs |
| [Editor extensions](docs/editor-extensions.md) | PHP registries, JavaScript controls and the editor API |
| [Document conversion](docs/conversion.md) | Quill ↔ URPC conversion, JSON formats and snapshot resolution |
| [Public pages](docs/public-pages.md) | URPC rendering, published content and custom document providers |
| [External resources](docs/external-resources.md) | Package-provided pages, components, palettes and runtime document replacement |
| [Editor design](docs/editor-redesign.md) | Theme tokens, control organization and design validation history |
| [Dashboard design](docs/dashboard-design.md) | Dashboard styling, navigation and search |

## Installation and updates

The AWT installer invokes `Quill/install.php`; updates invoke `Quill/update.php`. Both run `Quill\services\Migration` with the package ID. The migration uses framework `TableWizard` and `ColumnCreator` APIs to create missing tables and add missing columns, including snapshot manifests, `quill_animation` and `quill_color_palette`.

Runtime integration lives in `Quill/runtime`, controllers in `Quill/controllers`, and application services in `Quill/services`. Public pages render through `PublicPageController`; see the [public rendering contract](docs/public-pages.md).

## Development checks

PHP checks require PHP 8.2+, PDO SQLite, an AWT 27 framework checkout, Dashboard and URPC. The test scripts use the conventional sibling checkout layout. Set `AWT_FRAMEWORK_CLASSES` to the framework's `awt_src/classes` directory and `URPC_PACKAGE_ROOT` to the directory containing `URPC/api` when those checkouts are elsewhere.

Run focused integration checks from the repository root:

```sh
php tests/save.php
php tests/editor_save.php
php tests/conversion.php
php tests/urpc_to_quill.php
php tests/public_page.php
```

For example, override the framework location with:

```sh
AWT_FRAMEWORK_CLASSES=/path/to/Advanced-Web-Tools-Framework/awt_src/classes php tests/save.php
```

Browser checks require Chrome, Python 3 and the Python `websockets` package. They start a local fixture server and use a temporary browser profile. Set `CHROME_BIN` if Chrome is not found automatically.

```sh
python3 tests/editor_browser.py --screenshot /tmp/quill-editor.png
python3 tests/editor_save_controls_browser.py
```

PHP integration checks use isolated test databases. Browser save responses use fixtures; the PHP suites verify actual persistence, conflicts and rollback. Each feature guide lists its focused checks. Check PHP syntax with:

```sh
find Quill tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Building

With a local `build/dev_env.json` configured for the existing build tool, create a package archive without installing it:

```sh
bash build.sh --no-install
```

Coloris 0.25.0 and its MIT license are bundled in `Quill/views/assets/vendor`; the editor requires no npm build. Font Awesome uses the configured CDN stylesheet. Google Fonts loads only the families and variants used by the document, with system-font fallback. Refresh the local font metadata catalog with `python3 build/sync-google-fonts.py` when network access is available.
