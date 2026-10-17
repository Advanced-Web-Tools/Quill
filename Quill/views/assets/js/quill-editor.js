(() => {
    'use strict';
    const root = document.getElementById('quill-editor');
    if (!root) return;
    const get = id => document.getElementById(id);
    function toast(id, error) {
        const popup = get(id);
        popup.replaceChildren(); popup.hidden = !error;
        if (!error) return;
        const text = document.createElement('span'); text.textContent = error.message || error;
        const icon = document.createElement('i'); icon.className = 'fa-solid fa-circle-exclamation'; icon.setAttribute('aria-hidden', 'true');
        const close = document.createElement('button'); close.type = 'button'; close.setAttribute('aria-label', 'Dismiss notification');
        close.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
        close.addEventListener('click', () => { popup.hidden = true; });
        popup.append(icon, text, close);
    }
    const showError = error => toast('editor-error', error);
    const propertyError = error => toast('editor-property-error', error);
    try {
        const bootstrap = JSON.parse(get('quill-editor-data').textContent);
        if (bootstrap.error) throw new Error(bootstrap.error);
        bootstrap.document.assets ??= bootstrap.assets;
        bootstrap.document.settings = { title: bootstrap.name, description: bootstrap.description, ...(bootstrap.document.settings || {}) };
        const state = new window.QuillEditorState(bootstrap.document, { type: bootstrap.editorType });
        const updateDocumentIndicator = () => { root.dataset.documentDirty = String(state.dirty); };
        state.addEventListener('change', updateDocumentIndicator);
        state.addEventListener('saved', updateDocumentIndicator);
        updateDocumentIndicator();
        const canvas = new window.QuillEditorCanvas(get('editor-canvas'), path => state.select(path), (path, content) => state.updateBlock(path, { content }, 'canvas'));
        const editor = new window.QuillEditorApi(state, canvas, bootstrap.extensions, propertyError, bootstrap.googleFonts);
        editor.routes = Object.freeze((bootstrap.routes || []).map(route => Object.freeze(route)));
        editor.dataSources = Object.freeze((bootstrap.dataSources || []).map(source => Object.freeze(source)));
        editor.dataRoutes = Object.freeze((bootstrap.dataRoutes || []).map(route => Object.freeze(route)));
        const sectionSelect = { value: '0' };
        const hasSections = state.getDocument().sections.length > 0;
        get('editor-add-block').disabled = !hasSections;
        get('editor-add-component').disabled = !hasSections;
        canvas.setMoveHandler((source, target, position) => {
            try { state.moveBlock(source, target, position); showError(null); } catch (error) { showError(error); }
        });
        const workspace = root.querySelector('.editor-workspace');
        const clearMainDrop = () => {
            canvas.setDragging(false);
            delete workspace.dataset.mainDrop;
        };
        document.addEventListener('dragstart', event => {
            if (event.dataTransfer.types.includes('application/x-quill-block')) canvas.setDragging(true);
        });
        document.addEventListener('dragend', clearMainDrop, true);
        document.addEventListener('drop', clearMainDrop, true);
        workspace.addEventListener('dragover', event => {
            if (!event.dataTransfer.types.includes('application/x-quill-block') || event.target.closest('.editor-canvas-tools')) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            canvas.setDragging(true); workspace.dataset.mainDrop = '';
        });
        workspace.addEventListener('dragleave', event => {
            if (!workspace.contains(event.relatedTarget)) delete workspace.dataset.mainDrop;
        });
        workspace.addEventListener('drop', event => {
            const source = event.dataTransfer.getData('application/x-quill-block');
            if (!source || event.target.closest('.editor-canvas-tools')) return;
            event.preventDefault(); event.stopPropagation(); clearMainDrop();
            canvas.moveToMain(source);
        });
        editor.palette.configure({ url: bootstrap.paletteUrl });
        const tree = new window.QuillEditorTree(root, state, sectionSelect, showError, editor.blocks);
        const properties = new window.QuillEditorProperties(get('editor-properties'), editor);
        const picker = new window.QuillEditorBlockPicker(get('editor-block-dialog'), editor, sectionSelect, showError);
        const save = new window.QuillEditorSave(state, bootstrap, showError);
        editor.ui = { tree, properties, picker, save };
        editor.ui.components = new window.QuillEditorComponents(editor, bootstrap);
        picker.render();
        editor.ui.actions = new window.QuillEditorActions(editor);
        editor.ui.inserter = new window.QuillEditorInserter(editor, picker);
        editor.ui.pageSettings = new window.QuillEditorPageSettings(editor, bootstrap, propertyError);
        editor.ui.blockToolbar = new window.QuillEditorBlockToolbar(editor);
        get('editor-add-block').addEventListener('click', () => picker.open());
        get('editor-add-component').addEventListener('click', () => picker.open(null, { componentsOnly: true }));
        get('editor-empty-add').addEventListener('click', () => picker.open());
        get('editor-palettes-open').addEventListener('click', () => {
            try {
                state.assertAvailable(); editor.ui.pageSettings.close();
                if (!get('editor-block-dialog').hidden) picker.close();
                editor.palette.open({ manage: true, current: '#2ecc71', gradients: true });
                const button = get('editor-palettes-open'), dialog = document.querySelector('.editor-palette-dialog[open]');
                button.setAttribute('aria-expanded', 'true');
                dialog?.addEventListener('close', () => { button.setAttribute('aria-expanded', 'false'); button.focus(); }, { once: true });
            } catch (error) { propertyError(error); }
        });

        const componentNotice = document.createElement('div'); componentNotice.id = 'editor-component-notice'; componentNotice.hidden = true;
        componentNotice.className = 'editor-component-notice'; get('editor-selection-actions').before(componentNotice);
        get('editor-remove-selection').addEventListener('click', () => {
            try { editor.removeBlock(); editor.reportError(null); }
            catch (error) { editor.reportError(error); }
        });
        get('editor-detach-component').addEventListener('click', async () => {
            const button = get('editor-detach-component');button.disabled = true;button.setAttribute('aria-busy','true');
            try { await editor.ui.components.detach(); editor.reportError(null); }
            catch (error) { editor.reportError(error); }
            finally { button.disabled = false;button.removeAttribute('aria-busy'); }
        });
        let componentAnimationsAbort = null;
        function updateSelection() {
            componentAnimationsAbort?.abort(); componentAnimationsAbort = null;
            const locked = editor.isReadOnly();
            componentNotice.hidden = !locked;
            if (locked) {
                const info = state.getBlock(state.selectedPath)._quill.component;
                const heading = document.createElement('h3'); heading.textContent = info.name;
                const text = document.createElement('p'); text.textContent = 'Shared component. Drag to reposition it. Content and styling are managed in the component editor.';
                const link = document.createElement('a'); link.href = `/quill/editor/component/${info.id}`; link.target = '_blank'; link.rel = 'noopener'; link.textContent = 'Open component editor';
                componentNotice.replaceChildren(heading, text, link);
                if (editor.type === 'page' && editor.ui.animationLibrary) {
                    componentAnimationsAbort = new AbortController();
                    editor.ui.animationLibrary.mount(componentNotice, componentAnimationsAbort.signal);
                }
            }
            const selected = state.selectedPath !== null;
            get('editor-selection-actions').hidden = !selected;
            get('editor-detach-component').hidden = get('editor-detach-component-note').hidden = !locked;
            get('editor-remove-selection').querySelector('span').textContent = locked ? 'Remove component instance' : 'Delete block';
            get('editor-remove-selection-note').hidden = !locked;
            if (locked) get('editor-remove-selection').setAttribute('aria-describedby', 'editor-remove-selection-note');
            else get('editor-remove-selection').removeAttribute('aria-describedby');
            get('editor-properties').hidden = !selected || locked;
            get('editor-properties-empty').hidden = selected;
            get('editor-selected-tag').hidden = !selected;
            if (selected) get('editor-selected-tag').textContent = locked ? 'Shared component' : `<${state.getBlock(state.selectedPath).descriptor.name}>`;
            const breadcrumb = get('editor-breadcrumb');
            breadcrumb.hidden = !selected; breadcrumb.replaceChildren();
            if (selected) {
                const parts = state.selectedPath.split('.');
                for (let length = 2; length <= parts.length; length++) {
                    const path = parts.slice(0, length).join('.');
                    const button = document.createElement('button'); button.type = 'button';
                    button.textContent = state.getBlock(path)._quill?.component?.name || state.getBlock(path)._quill?.editor?.label || state.getBlock(path).descriptor.name;
                    button.addEventListener('click', () => state.select(path));
                    if (length > 2) {
                        const chevron = document.createElement('i'); chevron.className = 'fa-solid fa-chevron-right'; chevron.setAttribute('aria-hidden', 'true'); breadcrumb.append(chevron);
                    }
                    breadcrumb.append(button);
                }
            }
            canvas.select(state.selectedPath);
        }
        let canvasRenderQueue = Promise.resolve();
        function renderCanvas(event) {
            if (event?.detail?.source === 'canvas') return;
            // Keep full renders and local patches ordered, reading the latest state at execution.
            canvasRenderQueue = canvasRenderQueue.then(async () => {
                const document = state.getDocument(), assets = editor.getPreviewAssets();
                const path = event?.detail?.path;
                const updated = path && await canvas.updateBlock(document, bootstrap.css, assets, state.selectedPath, path);
                if (!updated) await canvas.render(document, bootstrap.css, assets, state.selectedPath);
                canvas.select(state.selectedPath);
                editor.dispatchEvent(new Event('canvasrendered'));
            }).catch(showError);
            return canvasRenderQueue;
        }
        state.addEventListener('change', event => {
            if (!['animations','animationbindings'].includes(event?.detail?.source)) { renderCanvas(event); updateSelection(); }
            get('editor-state-status').textContent = state.dirty ? 'Session changes · not saved' : 'Document loaded';
        });
        state.addEventListener('componentrefresh', () => { renderCanvas(); updateSelection(); });
        state.addEventListener('selectionchange', () => { propertyError(null); updateSelection(); });
        editor.addEventListener('rendererschange', renderCanvas);
        window.addEventListener('beforeunload', event => {
            if (state.dirty) { event.preventDefault(); event.returnValue = ''; }
        });
        const history = new window.QuillEditorHistory(state);
        editor.ui.history = history;
        editor.ui.viewport = new window.QuillEditorViewport(editor);
        editor.ui.settings = new window.QuillEditorSettings(editor, bootstrap);
        editor.ui.animationLibrary = new window.QuillAnimationLibrary(editor, bootstrap);
        editor.ui.animations = new window.QuillAnimationStudio(editor, bootstrap.propertyAssetBase);
        window.quillEditor = state;
        window.QuillEditor = editor;
        get('editor-state-status').textContent = 'Document loaded';
        updateSelection();
        renderCanvas();
        window.dispatchEvent(new CustomEvent('quill.editor.ready', { detail: { editor } }));
    } catch (error) {
        get('editor-state-status').textContent = 'Document could not be loaded';
        showError(error);
    }
})();
