# Quill 1.0.0

Quill is a visual JSON page and component editor for AWT 27, with reusable components, revision snapshots, draft/publish workflows, database routes, animations and color palettes. Public pages render through URPC.

Open `/dashboard/quill` to create and manage pages, components, routes and personal templates. Edit an existing document at `/quill/editor?type=page&id=1` or `/quill/editor?type=component&id=1`. Saving requires an existing page or component.

The package uses Dashboard's existing login and framework services. Installation and updates run `services/Migration.php` through the AWT package hooks, creating missing tables and adding missing columns with the framework's schema APIs.

Shared component instances are edited in their source component editor. Publishing a component updates its published appearance on host pages; component drafts remain private. Local blocks support nested editing, drag and drop, Undo/Redo, responsive styles, CSS states, actions and connected data.

Coloris 0.25.0 and its MIT license are bundled in `views/assets/vendor`. Google Fonts loads only the families and variants used by the document, with system-font fallback.

For complete guides and development checks, use the source checkout: start with the top-level `readme.md` and its documentation index. The `docs/` directory belongs to the source repository and is not included in this runtime package.
