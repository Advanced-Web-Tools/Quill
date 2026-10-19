<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editing {{ $editorName }}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/mdbassit/Coloris@v0.25.0/dist/coloris.min.css">
    <script src="https://cdn.jsdelivr.net/gh/mdbassit/Coloris@v0.25.0/dist/coloris.min.js" defer></script>
    <link rel="stylesheet" id="editor-design-tokens" href="@asset('css/quill-editor-tokens.css')">
    <link rel="stylesheet" href="@asset('css/quill-editor.css')">
    <script src="@asset('js/quill-editor-library.js')" defer></script>
    <script src="@asset('js/quill-animations.js')" defer></script>
    <script src="@asset('js/quill-animation-session.js')" defer></script>
    <script src="@asset('js/quill-animation-preview.js')" defer></script>
    <script src="@asset('js/quill-animation-library.js')" defer></script>
    <script src="@asset('js/quill-animation-studio.js')" defer></script>
    <script src="@asset('js/quill-editor-state.js')" defer></script>
    <script src="@asset('js/quill-editor-canvas.js')" defer></script>
    <script src="@asset('js/quill-editor-registry.js')" defer></script>
    <script src="@asset('js/quill-editor-modules.js')" defer></script>
    <script src="@asset('js/quill-editor-palette.js')" defer></script>
    <script src="@asset('js/quill-editor-fonts.js')" defer></script>
    <script src="@asset('js/quill-editor-api.js')" defer></script>
    <script src="@asset('js/quill-editor-properties.js')" defer></script>
    <script src="@asset('js/quill-editor-block-picker.js')" defer></script>
    <script src="@asset('js/quill-editor-inserter.js')" defer></script>
    <script src="@asset('js/quill-editor-tree.js')" defer></script>
    <script src="@asset('js/quill-editor-save.js')" defer></script>
    <script src="@asset('js/quill-editor-history.js')" defer></script>
    <script src="@asset('js/quill-editor-viewport.js')" defer></script>
    <script src="@asset('js/quill-route-builder.js')" defer></script>
    <script src="@asset('js/quill-editor-page-routes.js')" defer></script>
    <script src="@asset('js/quill-editor-page-settings.js')" defer></script>
    <script src="@asset('js/quill-actions-runtime.js')" defer></script>
    <script src="@asset('js/quill-editor-actions.js')" defer></script>
    <script src="@asset('js/quill-editor-components.js')" defer></script>
    <script src="@asset('js/quill-editor-block-history.js')" defer></script>
    <script src="@asset('js/quill-editor-block-toolbar.js')" defer></script>
    <script src="@asset('js/quill-editor-settings.js')" defer></script>
    <script src="@asset('js/quill-editor-shortcuts.js')" defer></script>
    <script src="@asset('js/quill-editor-media-library.js')" defer></script>
    <script src="@asset('js/quill-editor.js')" defer></script>
</head>
<body>
@php
    $bootstrap = [
        'document' => isset($editorError) ? null : json_decode($editorDocumentJson ?? '{"schema_version":1,"sections":[{"name":"main","blocks":[]}]}', false, 512, JSON_THROW_ON_ERROR),
        'editorType' => $editorType ?? 'page',
        'editorId' => $editorId ?? null,
        'name' => $editorName ?? 'Untitled document',
        'description' => $editorDescription ?? '',
        'propertyAssetBase' => $editorPropertyAssetBase ?? '/awt_packages/Quill/views/assets/js/editor/properties/',
        'css' => $editorCss ?? '',
        'googleFonts' => \Quill\services\fonts\GoogleFonts::catalog(),
        'assets' => $editorAssets ?? ['styles' => [], 'scripts' => []],
        'error' => $editorError ?? null,
        'saveUrl' => isset($editorId) ? '/dashboard/quill/document/' . ($editorType ?? 'page') . '/' . $editorId : null,
        'routes' => $editorRoutes ?? [],
        'dataSources' => $editorDataSources ?? [],
        'pageRoutes' => $editorPageRoutes ?? [],
        'dataRoutes' => $editorDataRoutes ?? [],
        'externalSourceRevision' => $editorExternalSourceRevision ?? null,
        'externalSourceKey' => $editorExternalSourceKey ?? null,
        'externalSourceKind' => $editorExternalSourceKind ?? null,
        'components' => $editorComponents ?? [],
        'externalComponents' => $editorExternalComponents ?? [],
        'externalComponentUrl' => '/dashboard/quill/external-component',
        'componentUrl' => '/dashboard/quill/editor-component',
        'animationUrl' => '/dashboard/quill/editor-animation',
        'paletteUrl' => '/dashboard/quill/editor-color-palette',
        'lockVersion' => $editorVersion ?? 1,
        'mediaLibraries' => $editorMediaLibraries ?? [],
        'extensions' => $editorExtensions ?? ['blocks' => [], 'properties' => []],
    ];
@endphp
    <script type="application/json" id="quill-editor-data">{!! json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
    <div class="quill-editor" id="quill-editor">
        <header class="editor-topbar">
            <div class="editor-document-heading">
                <div class="editor-document-name">
                    <h1>{{ $editorName ?? 'Untitled document' }}</h1>
                    @if(!empty($editorExternalSourceRevision))<span class="editor-viewport-note">External {{ $editorExternalSourceKind ?? 'page' }} · Changes and added blocks are saved separately</span>@endif
                    @if(!empty($editorExternalWarnings))<span class="editor-viewport-note" role="status"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> {{ implode(' ', $editorExternalWarnings) }}</span>@endif
                    <span id="editor-state-status" role="status">Loading document…</span>
                </div>
            </div>
            <div class="editor-add-controls">
                <button type="button" id="editor-undo" title="Undo (Ctrl+Z)" aria-label="Undo"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>
                <button type="button" id="editor-redo" title="Redo (Ctrl+Shift+Z)" aria-label="Redo"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i></button>
                <div class="editor-save-group">
                    <label class="visually-hidden" for="editor-save-mode">Save mode</label>
                    <span class="editor-save-menu"><select id="editor-save-mode" title="Save actions" aria-label="Choose and run a save action"><option value="" disabled selected>Save actions</option><option value="draft">Save draft</option><option value="publish">Publish</option></select><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></span>
                    <button type="button" class="button-primary" id="editor-save" disabled>Save draft</button>
                </div>
            </div>
        </header>
        <aside id="editor-page-settings" class="editor-page-panel" aria-labelledby="editor-page-settings-title" hidden>
            <header class="editor-panel-heading"><h2 id="editor-page-settings-title">Page settings</h2><button type="button" id="editor-page-settings-close" aria-label="Close page settings"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
            <div class="editor-page-settings-content"><section id="editor-page-general">
                <label for="editor-page-title">Title</label><input id="editor-page-title" type="text" maxlength="255" required>
                <label for="editor-page-description">Description</label><textarea id="editor-page-description" rows="5" maxlength="65535"></textarea>
                <div id="editor-page-routes" class="editor-page-routes" hidden>
                    <h3>Page addresses</h3><p class="editor-group-description">Choose an existing route or create an address for this page.</p>
                    <div id="editor-page-route-list" aria-label="Routes opening this page"></div>
                    <label for="editor-page-route-select">Connect a route</label>
                    <div class="editor-page-route-connect"><select id="editor-page-route-select"><option value="">Choose a route…</option></select><button type="button" id="editor-page-route-new" aria-haspopup="dialog" aria-controls="editor-route-dialog"><i class="fa-solid fa-plus" aria-hidden="true"></i> New route</button></div>
                    <p class="editor-group-description">Route changes apply immediately. Page content uses Save draft or Publish.</p><p id="editor-page-route-status" role="status"></p>
                </div>
                <div id="editor-page-snapshots" class="editor-page-routes" hidden>
                    <h3>Snapshots</h3><p class="editor-group-description">Browse published versions and restore a snapshot into the editor. Save or publish to keep the restored version.</p>
                    <button type="button" id="editor-page-snapshots-open" aria-haspopup="dialog" aria-controls="editor-page-history"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Version history</button>
                </div>
            </section></div>
            <footer><a href="{{ (($editorType ?? 'page') === 'component' || ($editorExternalSourceKind ?? '') === 'component') ? '/dashboard/quill/components' : '/dashboard/quill' }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to dashboard</a></footer>
        </aside>
        <aside id="editor-page-styling-panel" class="editor-page-panel" aria-labelledby="editor-page-styling-title" hidden>
            <header class="editor-panel-heading"><h2 id="editor-page-styling-title">Page styling</h2><button type="button" data-close-page-panel aria-label="Close page styling"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
            <div class="editor-page-settings-content"><section id="editor-page-styling" aria-labelledby="editor-page-styling-title" hidden></section></div>
        </aside>
        <aside id="editor-page-actions-panel" class="editor-page-panel" aria-labelledby="editor-page-actions-title" hidden>
            <header class="editor-panel-heading"><h2 id="editor-page-actions-title">Page actions</h2><button type="button" data-close-page-panel aria-label="Close page actions"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
            <div class="editor-page-settings-content"><section id="editor-page-actions" aria-labelledby="editor-page-actions-title" hidden><div id="editor-actions-list"></div><button type="button" id="editor-actions-new" class="editor-actions-new"><i class="fa-solid fa-plus" aria-hidden="true"></i> New action</button></section></div>
        </aside>
        <nav class="editor-rail" aria-label="Editor tools">
            <button type="button" id="editor-add-block" title="Add block" aria-label="Add block" aria-expanded="false" aria-controls="editor-block-dialog" disabled><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
            <button type="button" id="editor-add-component" title="Components" aria-label="Components" aria-expanded="false" aria-controls="editor-block-dialog" disabled><i class="fa-solid fa-cubes" aria-hidden="true"></i></button>
            <button type="button" id="editor-page-tab-styling" data-page-tab="styling" title="Page styling" aria-label="Page styling" aria-expanded="false" aria-controls="editor-page-styling-panel"><i class="fa-solid fa-paintbrush" aria-hidden="true"></i></button>
            <button type="button" id="editor-page-tab-actions" data-page-tab="actions" title="Page actions" aria-label="Page actions" aria-expanded="false" aria-controls="editor-page-actions-panel"><i class="fa-solid fa-bolt" aria-hidden="true"></i></button>
            <button type="button" id="editor-palettes-open" title="Color palettes" aria-label="Color palettes" aria-haspopup="dialog" aria-expanded="false"><i class="fa-solid fa-swatchbook" aria-hidden="true"></i></button>
            <div class="editor-rail-bottom"><hr><button type="button" id="editor-page-menu" title="Page settings" aria-label="Page settings" aria-expanded="false" aria-controls="editor-page-settings"><i class="fa-solid fa-sliders" aria-hidden="true"></i></button>
                <button type="button" id="editor-settings-open" title="Editor settings" aria-label="Editor settings" aria-haspopup="dialog" aria-expanded="false" aria-controls="editor-settings-dialog"><i class="fa-solid fa-gear" aria-hidden="true"></i></button>
            </div>
        </nav>
        <aside class="editor-leftbar" aria-labelledby="editor-layers-title">
            <div id="editor-layers"><div class="editor-panel-heading"><h2 id="editor-layers-title"><i class="fa-solid fa-layer-group" aria-hidden="true"></i>Layers</h2><span id="editor-block-count" class="editor-count">0</span></div>
            <p class="editor-panel-description">Your document, block by block.</p>
            <div class="editor-tree-actions" aria-label="Move selected block">
                <button type="button" data-move="up" title="Move up" aria-label="Move up"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button>
                <button type="button" data-move="down" title="Move down" aria-label="Move down"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button>
                <button type="button" data-move="outdent" title="Move out of parent" aria-label="Move out of parent"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button>
                <button type="button" data-move="indent" title="Move into previous block" aria-label="Move into previous block"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            </div>
            <nav id="editor-tree" aria-label="Block hierarchy"></nav></div>
            <section id="editor-block-dialog" aria-labelledby="editor-block-dialog-title" hidden>
                <header class="editor-panel-heading"><h2 id="editor-block-dialog-title"><i class="fa-solid fa-cubes" aria-hidden="true"></i>Add block</h2><button type="button" id="editor-block-close" aria-label="Back to layers"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button></header>
                <p id="editor-insert-target" class="editor-panel-description"></p>
                <div class="editor-block-search-wrap"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><label class="visually-hidden" for="editor-block-search">Search blocks</label><input id="editor-block-search" type="search" placeholder="Search blocks…"></div>
                <div class="editor-block-picker"></div>
            </section>
        </aside>
        <main class="editor-workspace" aria-label="Document canvas">
            <div id="editor-main-drop" hidden>Drop into main</div>
            <div class="editor-canvas-dock">
                <div class="editor-toasts" aria-live="polite"><div id="editor-error" class="editor-toast" role="alert" hidden></div><div id="editor-property-error" class="editor-toast" role="alert" hidden></div><div id="editor-success" class="editor-toast editor-toast-success" role="status" hidden></div></div>
                <div class="editor-canvas-tools"><span id="editor-canvas-label" class="visually-hidden">Canvas</span><label for="editor-viewport" title="Preview width"><i class="fa-solid fa-display" aria-hidden="true"></i><span class="visually-hidden">Preview width</span></label><select id="editor-viewport"><option value="1440" data-icon="fa-desktop">Desktop</option><option value="768" data-icon="fa-tablet-screen-button">Tablet</option><option value="390" data-icon="fa-mobile-screen-button">Mobile</option></select><label for="editor-zoom" title="Preview zoom"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span class="visually-hidden">Zoom</span></label><output id="editor-viewport-size" aria-live="polite">1440px</output><select id="editor-zoom"><option value="fit" selected>Fit</option><option value="1">100%</option><option value="0.75">75%</option><option value="0.5">50%</option></select></div>
            </div>

            <div class="editor-canvas-area">
                <div id="editor-canvas-stage"><iframe id="editor-canvas" title="Document preview" sandbox="allow-same-origin"></iframe></div>
                <div class="editor-empty-canvas" id="editor-empty-canvas" hidden>
                    <span class="editor-empty-symbol" aria-hidden="true"><i class="fa-solid fa-plus"></i></span>
                    <h2>A blank canvas</h2><p>Add your first block to start building.</p>
                    <button type="button" class="button-primary" id="editor-empty-add">Add a block</button>
                </div>
            </div>
        </main>
        <aside class="editor-rightbar" aria-labelledby="editor-properties-title">
            <div class="editor-panel-heading"><h2 id="editor-properties-title"><i class="fa-solid fa-sliders" aria-hidden="true"></i>Properties</h2><code id="editor-selected-tag" hidden></code></div>
            <nav id="editor-breadcrumb" aria-label="Selected block ancestors" hidden></nav>
            <div class="editor-properties-empty" id="editor-properties-empty"><span aria-hidden="true"><i class="fa-solid fa-arrow-pointer"></i></span><h3>Select a block</h3><p>Choose a layer or click a block on the canvas to edit its properties.</p></div>
            <div id="editor-selection-actions" class="editor-selection-actions" hidden>
                <button type="button" id="editor-detach-component" aria-describedby="editor-detach-component-note" hidden><i class="fa-solid fa-cube" aria-hidden="true"></i><span>Turn into block</span></button>
                <p id="editor-detach-component-note" hidden>Make an editable local copy. Changes here will no longer update the shared component.</p>
                <div id="editor-component-edit" hidden></div>
                <hr id="editor-component-edit-divider" hidden>
                <button type="button" id="editor-remove-selection" aria-describedby="editor-remove-selection-note"><i class="fa-solid fa-trash-can" aria-hidden="true"></i><span>Delete block</span></button>
                <p id="editor-remove-selection-note" hidden>Removes only this instance from the document. The saved component stays in your library.</p>
            </div>
            <form id="editor-properties" hidden>
                <div class="editor-style-scope">
                    <span id="editor-style-viewport-label">Apply on device</span>
                    <div id="editor-style-viewport" class="editor-viewport-buttons" role="group" aria-labelledby="editor-style-viewport-label">
                        <button type="button" data-style-viewport="all" aria-label="All devices" title="All devices" aria-pressed="true"><i class="fa-solid fa-globe" aria-hidden="true"></i></button>
                        <button type="button" data-style-viewport="desktop" aria-label="Desktop" title="Desktop" aria-pressed="false"><i class="fa-solid fa-desktop" aria-hidden="true"></i></button>
                        <button type="button" data-style-viewport="tablet" aria-label="Tablet" title="Tablet and smaller screens" aria-pressed="false"><i class="fa-solid fa-tablet-screen-button" aria-hidden="true"></i></button>
                        <button type="button" data-style-viewport="mobile" aria-label="Mobile" title="Mobile" aria-pressed="false"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="editor-style-viewport"><label for="editor-style-state">When</label><select id="editor-style-state" aria-describedby="editor-shared-content-note"><option value="default">Default</option><option value="hover">Hover</option><option value="focus">Focused</option><option value="focus-visible">Focus visible</option><option value="active">Active</option><option value="disabled">Disabled</option><option value="checked">Checked</option></select></div>
                <p id="editor-shared-content-note" class="visually-hidden">Content and attributes apply to all viewports and states.</p>
                <label class="editor-start-hidden"><input type="checkbox" id="editor-start-hidden" role="switch" aria-describedby="editor-hidden-note" title="Hidden blocks remain available in Layers"> Start hidden</label>
                <p id="editor-hidden-note" class="visually-hidden">Hidden blocks remain available in Layers.</p>
                <p id="editor-style-context" class="editor-style-context" role="status"></p>
                <p class="editor-value-key" title="Unmarked values use inherited or default appearance. Reset removes only the active scope value."><span><i class="fa-solid fa-circle" aria-hidden="true"></i></span> Assigned · <span><i class="fa-solid fa-diamond" aria-hidden="true"></i></span> Override</p>
                <div class="editor-property-tabs" role="tablist" aria-label="Property categories"></div>
                <div id="editor-property-panels"></div>
            </form>
        </aside>
    </div>
    <dialog id="animation-studio" class="animation-studio" aria-labelledby="studio-title" aria-describedby="studio-context">
        <header class="editor-action-dialog-header"><div><div class="studio-heading"><h2 id="studio-title">Animation Studio</h2><span id="studio-recording" class="studio-recording-badge" title="Property changes create keyframes at the current playhead in the isolated preview.">Recording property changes</span></div><p id="studio-context" class="studio-hint">Isolated animation preview</p></div><button type="button" class="editor-action-icon" data-studio-close aria-label="Close Animation Studio"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <div class="studio-workspace">
            <section class="studio-scene" aria-label="Animation preview and settings">
                <label class="studio-name" for="studio-name">Animation name<input id="studio-name" type="text" maxlength="255" required></label>
                <div id="studio-preview" class="studio-preview"></div>
                <details class="studio-settings" open><summary>Animation settings</summary><div class="studio-settings-grid">
                    <label>Duration (ms)<input id="studio-duration" type="number" min="1" max="600000" step="1"></label>
                    <label>Delay (ms)<input id="studio-delay" type="number" min="0" max="600000" step="1"></label>
                    <label>Iterations<input id="studio-iterations" type="number" min="1" max="1000" step="1"></label>
                    <label class="studio-toggle"><input id="studio-infinite" type="checkbox"> Infinite</label>
                    <label>Direction<select id="studio-direction"><option value="normal">Normal</option><option value="reverse">Reverse</option><option value="alternate">Alternate</option><option value="alternate-reverse">Alternate reverse</option></select></label>
                    <label>Fill mode<select id="studio-fill"><option value="none">None</option><option value="forwards">Forwards</option><option value="backwards">Backwards</option><option value="both">Both</option></select></label>
                    <label class="studio-wide">Easing<input id="studio-easing" list="studio-easings" type="text"></label>
                    <label class="studio-toggle studio-wide"><input id="studio-bind" type="checkbox"> Use on the selected element</label>
                    <label class="studio-wide">Trigger<select id="studio-trigger"><option value="load">Page load</option><option value="click">Click</option><option value="hover">Hover</option><option value="focus">Focus</option></select></label>
                </div></details>
            </section>
            <form id="studio-properties" class="studio-properties" aria-label="Animation property controls"><p class="studio-hint">Only visual styles are recorded. Content, attributes and layout mode are unavailable. Gradients and other discrete values switch at keyframes.</p><div role="tablist"></div><div id="studio-property-panels"></div></form>
            <section class="studio-timeline" aria-label="Animation timeline">
                <div class="studio-playback"><button id="studio-play" type="button">Play</button><button id="studio-reset" type="button">Reset preview</button><label class="visually-hidden" for="studio-playhead">Animation playhead</label><input id="studio-playhead" type="range" min="0" max="1200" step="1" value="0"><output id="studio-time">0 / 1200 ms</output><button id="studio-undo" type="button" title="Undo animation edit" aria-label="Undo animation edit"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button><button id="studio-redo" type="button" title="Redo animation edit" aria-label="Redo animation edit"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i></button></div>
                <div class="studio-track-tools"><label class="visually-hidden" for="studio-property">Property for a new keyframe</label><input id="studio-property" list="studio-animated-properties" value="opacity" placeholder="CSS property"><button id="studio-add-keyframe" type="button">Add keyframe</button><span class="studio-hint">Drag keyframes or use arrow keys to move them.</span></div>
                <p id="studio-track-hint" class="studio-track-hint" role="status" aria-live="polite" hidden></p>
                <div id="studio-tracks" class="studio-tracks"></div>
                <fieldset id="studio-keyframe" class="studio-keyframe-editor" hidden><legend>Keyframe · <span id="studio-keyframe-property"></span></legend><label>Time (ms)<input id="studio-keyframe-time" type="number" min="0" step="any"></label><label>Value<input id="studio-keyframe-value" type="text"></label><label>Easing<input id="studio-keyframe-easing" type="text" list="studio-easings" placeholder="Use animation easing"></label><button id="studio-delete-keyframe" type="button">Delete keyframe</button></fieldset>
            </section>
        </div>
        <p id="studio-error" class="studio-error" role="alert" hidden></p>
        <footer class="editor-action-dialog-footer"><span class="studio-hint">Save to the shared animation library. Save the page to persist element references.</span><button type="button" data-studio-discard>Discard Animation</button><button type="button" class="button-primary" data-studio-save>Save Animation</button></footer>
        <datalist id="studio-easings"><option value="linear"><option value="ease"><option value="ease-in"><option value="ease-out"><option value="ease-in-out"><option value="cubic-bezier(0.2, 0.8, 0.2, 1)"><option value="steps(4, end)"></datalist>
        <datalist id="studio-animated-properties"><option value="opacity"><option value="translate"><option value="rotate"><option value="scale"><option value="transform-origin"><option value="border-radius"><option value="background-color"><option value="background-image"><option value="filter"><option value="box-shadow"><option value="width"><option value="padding"></datalist>
    </dialog>
    <dialog id="studio-close-confirm" class="editor-settings-dialog" aria-labelledby="studio-close-title"><header class="editor-action-dialog-header"><h2 id="studio-close-title">Save animation changes?</h2></header><p class="studio-confirm-description">Save this animation, discard your changes, or keep editing.</p><footer class="editor-action-dialog-footer"><button type="button" data-studio-continue autofocus>Continue Editing</button><button type="button" data-studio-discard>Discard</button><button type="button" class="button-primary" data-studio-save>Save</button></footer></dialog>
    <dialog id="editor-block-history" class="editor-settings-dialog editor-block-history" aria-labelledby="editor-block-history-title" aria-describedby="editor-block-history-context">
        <header class="editor-action-dialog-header"><div><h2 id="editor-block-history-title">Version history</h2><p id="editor-block-history-context" data-history-context></p></div><button type="button" class="editor-action-icon" data-history-close aria-label="Close version history" autofocus><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <p class="editor-block-history-hint">Restore this block and its saved children. Older versions restore properties only. Save the document to keep the restored version.</p>
        <ol data-history-list class="editor-block-history-list" aria-label="Saved block versions"></ol>
        <footer class="editor-action-dialog-footer editor-block-history-pagination"><button type="button" class="editor-action-icon" data-history-prev aria-label="Newer versions" title="Newer versions" disabled><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button><span data-history-count role="status">Showing 0 of 0</span><button type="button" class="editor-action-icon" data-history-next aria-label="Older versions" title="Older versions" disabled><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button></footer>
    </dialog>
    <dialog id="editor-page-history" class="editor-settings-dialog editor-block-history" aria-labelledby="editor-page-history-title" aria-describedby="editor-page-history-context">
        <header class="editor-action-dialog-header"><div><h2 id="editor-page-history-title">Page snapshots</h2><p id="editor-page-history-context" data-history-context></p></div><button type="button" class="editor-action-icon" data-history-close aria-label="Close page snapshots" autofocus><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <p class="editor-block-history-hint">Restore the whole page into the editor, including its settings and blocks. You can undo the restore. Save or publish to keep it.</p>
        <ol data-history-list class="editor-block-history-list" aria-label="Saved page snapshots"></ol>
        <footer class="editor-action-dialog-footer editor-block-history-pagination"><button type="button" class="editor-action-icon" data-history-prev aria-label="Newer versions" title="Newer versions" disabled><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button><span data-history-count role="status">Showing 0 of 0</span><button type="button" class="editor-action-icon" data-history-next aria-label="Older versions" title="Older versions" disabled><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button></footer>
    </dialog>
    <dialog id="editor-shortcuts-dialog" class="editor-settings-dialog editor-shortcuts-dialog" aria-labelledby="editor-shortcuts-title" aria-describedby="editor-shortcuts-description">
        <header class="editor-action-dialog-header"><h2 id="editor-shortcuts-title">Edit shortcuts</h2><button type="button" class="editor-action-icon" data-close-shortcuts aria-label="Close shortcuts" autofocus><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <div class="editor-settings-body"><p id="editor-shortcuts-description" class="editor-settings-note">Focus a shortcut and press a key combination. Copy, cut and paste apply to selected blocks; text fields keep their usual shortcuts.</p><div data-shortcuts-list></div><p data-shortcuts-status class="editor-settings-note" role="status"></p></div>
        <footer class="editor-action-dialog-footer"><button type="button" data-reset-shortcuts>Reset defaults</button><button type="button" class="button-primary" data-close-shortcuts>Done</button></footer>
    </dialog>
    <dialog id="editor-settings-dialog" class="editor-settings-dialog" aria-labelledby="editor-settings-title" aria-describedby="editor-settings-storage-note">
        <header class="editor-action-dialog-header"><h2 id="editor-settings-title">Editor settings</h2><button type="button" class="editor-action-icon" data-close-settings aria-label="Close editor settings"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <div class="editor-settings-body">
            <div class="editor-settings-row"><label for="editor-settings-view">Default view mode<small>Canvas preview when opening the editor.</small></label><select id="editor-settings-view"><option value="1440">Desktop</option><option value="768">Tablet</option><option value="390">Mobile</option></select></div>
            <div class="editor-settings-row"><label for="editor-settings-theme">Appearance</label><select id="editor-settings-theme"><option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option></select></div>
            <div class="editor-settings-row"><label for="editor-settings-save-method">Default save method<small>Action for the main save button when opening the editor.</small></label><select id="editor-settings-save-method"><option value="draft">Save draft</option><option value="publish">Publish</option></select></div>
            <div class="editor-settings-row"><label for="editor-settings-autosave">Auto save<small>Save changes as a draft. Publish stays manual.</small></label><input id="editor-settings-autosave" type="checkbox" role="switch" aria-controls="editor-settings-interval-row"></div>
            <div class="editor-settings-row" id="editor-settings-interval-row" hidden><label for="editor-settings-interval">Save every</label><div class="editor-settings-interval"><input id="editor-settings-interval" type="number" min="10" max="3600" step="1" value="60" required aria-describedby="editor-settings-interval-unit" disabled><span id="editor-settings-interval-unit">seconds</span></div></div>
            <div class="editor-settings-row editor-settings-shortcuts"><label for="editor-shortcuts-open">Shortcuts<small>Customize keyboard commands for this browser.</small></label><button type="button" id="editor-shortcuts-open" aria-haspopup="dialog" aria-expanded="false" aria-controls="editor-shortcuts-dialog"><i class="fa-solid fa-keyboard" aria-hidden="true"></i> Edit shortcuts</button></div>
            <p id="editor-settings-storage-note" class="editor-settings-note" role="status">Preferences are saved in this browser and apply to all Quill documents.</p>
        </div>
        <footer class="editor-action-dialog-footer"><button type="button" class="button-primary" data-close-settings>Done</button></footer>
    </dialog>
    <dialog id="editor-route-dialog" class="editor-settings-dialog editor-route-dialog" aria-labelledby="editor-route-title" aria-describedby="editor-route-description">
        <header class="editor-action-dialog-header"><h2 id="editor-route-title">New page route</h2><button type="button" class="editor-action-icon" data-close-route aria-label="Close new route"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <form id="editor-route-form" method="post" action="/dashboard/quill/create/route">
            <div class="editor-route-body">
                <p id="editor-route-description" class="editor-group-description">Build the address for this page. Parts are fixed words; parameters are details that can change, such as a product slug.</p>
                <p class="editor-route-page">Opens <strong id="editor-route-page-name"></strong></p>
                <ul id="editor-route-parts" class="editor-route-parts" aria-label="Route parts in order"></ul>
                <div class="editor-route-tools"><button type="button" data-route-add="part"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add part</button><button type="button" data-route-add="param"><i class="fa-solid fa-code" aria-hidden="true"></i> Add parameter</button></div>
                <p class="editor-route-address">Address <output id="editor-route-preview" aria-live="polite">/</output></p>
                <p class="editor-group-description">Remove all parts to use the home address /.</p>
                <p id="editor-route-order-status" class="editor-group-description" role="status"></p><p id="editor-route-status" class="editor-action-error" role="status"></p>
            </div>
            <footer class="editor-action-dialog-footer"><button type="button" data-close-route>Cancel</button><button type="submit" class="button-primary">Create route</button></footer>
        </form>
        <template id="editor-route-part-template"><li class="editor-route-part"><div><label></label><input type="hidden" data-route-type><input type="text" data-route-value maxlength="512" required></div><div class="editor-route-part-controls"><button type="button" class="editor-action-icon" data-route-move="up" aria-label="Move part up"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button><button type="button" class="editor-action-icon" data-route-move="down" aria-label="Move part down"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button><button type="button" class="editor-action-icon" data-route-remove aria-label="Remove part"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div></li></template>
    </dialog>
    <form id="editor-csrf" hidden>@csrf</form>
    <noscript><p class="editor-noscript">Enable JavaScript to use the Quill editor.</p></noscript>
</body>
</html>
