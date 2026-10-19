(() => {
    'use strict';
    const styleStates = ['hover', 'focus', 'focus-visible', 'active', 'disabled', 'checked'];
    function quillBlock(raw) {
        if (!raw) return null;
        const metadata = raw._quill || {};
        return {
            schema_version: 1, kind: 'element', styles: { base: {}, desktop: {}, tablet: {}, mobile: {} }, bindings: {}, custom_settings: {}, editor: {},
            ...structuredClone(metadata), descriptor: structuredClone(raw.descriptor), attributes: structuredClone(raw.attributes),
            content: raw.content, children: raw.children.map(quillBlock),
        };
    }
    function editorBlock(block) {
        const metadata = { ...(block._quill || {}) };
        for (const key of ['definition_key', 'styles', 'bindings', 'custom_settings', 'editor', 'animations']) {
            if (Object.hasOwn(block, key)) metadata[key] = structuredClone(block[key]);
        }
        return { descriptor: block.descriptor, attributes: block.attributes, content: block.content, children: block.children.map(editorBlock), _quill: metadata };
    }
    class QuillEditorApi extends EventTarget {
        #renderers = new Map();
        #styleViewport = 'all';
        #styleState = 'default';
        #styleClipboard = null;
        constructor(state, canvas, extensions, reportError, fontCatalog = []) {
            super();
            this.state = state;
            this.canvas = canvas;
            this.reportError = reportError;
            this.fonts = new window.QuillEditorFonts(fontCatalog);
            this.palette = new window.QuillEditorPalette(state);
            this.modules = new window.QuillEditorModules();
            this.blocks = new window.QuillEditorRegistry(extensions.blocks, definition => {
                if (!definition.name || !Array.isArray(definition.blocks) || !definition.blocks.length) throw new Error('A block definition requires a name and root block templates.');
                new window.QuillEditorState({ schema_version: 1, sections: [{ name: 'template', blocks: definition.blocks }] });
            });
            this.properties = new window.QuillEditorRegistry(extensions.properties, definition => {
                if (!/^[A-Za-z][A-Za-z0-9_.:-]*$/.test(definition.category) || !Array.isArray(definition.elements) || !definition.elements.length) throw new Error('A property definition requires a category and element modules.');
                if (definition.condition != null) this.modules.validate(definition.condition);
                for (const element of definition.elements) {
                    this.modules.validate(typeof element === 'string' ? element : element?.definition);
                    if (element?.condition != null) this.modules.validate(element.condition);
                }
            });
            for (const name of ['change', 'selectionchange', 'saved', 'componentrefresh']) {
                state.addEventListener(name, event => this.dispatchEvent(new CustomEvent(name, { detail: event.detail })));
            }
            canvas.setResizeHandler((path, values) => {
                try {
                    const style = this.getStyleDeclarations(path);
                    for (const [property, value] of Object.entries(values)) style.setProperty(property, value);
                    this.setStyleDeclarations(style.cssText, path);
                    this.reportError(null);
                } catch (error) { this.reportError(error); }
            });
            canvas.setRenderHook((element, raw, path) => {
                const renderer = this.#renderers.get(raw._quill?.definition_key);
                return renderer?.({ element, block: quillBlock(raw), path, editor: this });
            });
        }
        get type() { return this.state.editorType; }
        isReadOnly(path = this.selectedPath) { return path !== null && this.state.componentRoot(path) !== null; }
        get selectedPath() { return this.state.selectedPath; }
        getDocument() { return this.state.getDocument(); }
        getPreviewAssets() {
            const doc = this.getDocument(), assets = { styles: new Set(doc.assets?.styles || []), scripts: new Set(doc.assets?.scripts || []) };
            const visit = blocks => blocks.forEach(block => {
                if (block._quill?.definition_key === 'Quill:icon' || block.attributes?.['data-quill-icon']) assets.styles.add('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css');
                for (const type of ['styles', 'scripts']) for (const url of block._quill?.component?.assets?.[type] || []) assets[type].add(url);
                visit(block.children);
            });
            doc.sections.forEach(section => visit(section.blocks));
            this.fonts.stylesheets(doc).forEach(url => assets.styles.add(url));
            return { styles: [...assets.styles], scripts: [...assets.scripts] };
        }
        getBlock(path) { return quillBlock(this.state.getBlock(path)); }
        getSelectedBlock() { return this.selectedPath === null ? null : this.getBlock(this.selectedPath); }
        select(path) { this.state.select(path); }
        updateBlock(changes, path = this.selectedPath) {
            if (path === null) throw new Error('Select a block first.');
            const current = this.state.getBlock(path);
            const patch = { ...changes };
            const metadata = { ...(current._quill || {}) };
            for (const key of ['definition_key', 'styles', 'bindings', 'custom_settings', 'editor', 'animations']) {
                if (Object.hasOwn(patch, key)) { metadata[key] = structuredClone(patch[key]); delete patch[key]; }
            }
            patch._quill = metadata;
            if (patch.children) patch.children = patch.children.map(editorBlock);
            this.state.updateBlock(path, patch);
        }
        setAttribute(name, value, path = this.selectedPath) {
            const attributes = this.getBlock(path).attributes;
            if (value === null || value === undefined) delete attributes[name]; else Object.defineProperty(attributes, name, { value, enumerable: true, configurable: true, writable: true });
            this.updateBlock({ attributes }, path);
        }
        get styleViewport() { return this.#styleViewport; }
        get styleState() { return this.#styleState; }
        get styleScope() {
            if (this.#styleState === 'default') return this.#styleViewport === 'all' ? 'base' : this.#styleViewport;
            return this.#styleViewport === 'all' ? this.#styleState : `${this.#styleViewport}:${this.#styleState}`;
        }
        setStyleState(state) {
            if (!['default', ...styleStates].includes(state)) throw new Error('Invalid CSS state.');
            this.palette.close();
            this.#styleState = state;
            this.canvas.setStyleState(state === 'default' ? null : state);
            this.dispatchEvent(new Event('stylestatechange'));
        }
        get breakpoints() { return this.getDocument().settings?.breakpoints || { tablet: 1024, mobile: 767 }; }
        setStyleViewport(viewport) {
            if (!['all', 'desktop', 'tablet', 'mobile'].includes(viewport)) throw new Error('Invalid style viewport.');
            this.palette.close();
            this.#styleViewport = viewport;
            this.dispatchEvent(new Event('styleviewportchange'));
        }
        getStyleDeclarations(path = this.selectedPath) {
            const block = this.getBlock(path);
            const style = document.createElement('div').style;
            const group = this.styleScope;
            for (const [property, value] of Object.entries(block.styles?.[group] || {})) style.cssText += `${property}:${value};`;
            if (group === 'base') style.cssText += ';' + (block.attributes.style || '');
            return style;
        }
        setStyleDeclarations(cssText, path = this.selectedPath) {
            const block = this.getBlock(path);
            const responsive = Object.entries(block.styles || {}).some(([group, values]) => group !== 'base' && Object.keys(values).length);
            if (this.styleScope === 'base' && !responsive && (block.attributes.style || !Object.keys(block.styles?.base || {}).length)) { this.setAttribute('style', cssText || null, path); return; }
            const style = document.createElement('div').style; style.cssText = cssText || '';
            const toMap = declarations => Object.fromEntries([...declarations].map(property => [property,
                declarations.getPropertyValue(property) + (declarations.getPropertyPriority(property) ? ' !important' : '')]));
            const styles = structuredClone(block.styles || {});
            const attributes = { ...block.attributes };
            const base = document.createElement('div').style;
            for (const [property, value] of Object.entries(styles.base || {})) base.cssText += `${property}:${value};`;
            base.cssText += ';' + (attributes.style || '');
            styles.base = toMap(base); delete attributes.style;
            styles[this.styleScope] = toMap(style);
            const raw = this.state.getBlock(path);
            this.state.updateBlock(path, { attributes, _quill: { ...raw._quill, styles } }, 'properties', { breakpoints: this.breakpoints });
        }
        setStyle(property, value, path = this.selectedPath) {
            if (!/^--[A-Za-z0-9_-]+$/.test(property) && value && !CSS.supports(property, value)) throw new Error(`Invalid ${property}: ${value}`);
            const block = this.getBlock(path);
            if (this.styleScope !== 'base' || Object.entries(block.styles || {}).some(([group, values]) => group !== 'base' && Object.keys(values).length) || (!block.attributes.style && Object.keys(block.styles?.base || {}).length)) {
                const declarations = this.getStyleDeclarations(path);
                if (value) declarations.setProperty(property, value); else declarations.removeProperty(property);
                this.setStyleDeclarations(declarations.cssText, path); return;
            }
            const attributes = block.attributes;
            const style = document.createElement('div').style;
            style.cssText = attributes.style || '';
            if (value) style.setProperty(property, value); else style.removeProperty(property);
            if (style.cssText) attributes.style = style.cssText; else delete attributes.style;
            this.updateBlock({ attributes }, path);
        }
        get hasCopiedStyle() { return this.#styleClipboard !== null; }
        copyStyle(path = this.selectedPath) {
            this.state.assertEditable(path);
            const block = this.getBlock(path);
            this.#styleClipboard = structuredClone({ inline: block.attributes.style || '', styles: block.styles || {} });
            this.dispatchEvent(new Event('styleclipboardchange'));
            this.notify?.('Style copied.');
        }
        pasteStyle(path = this.selectedPath) {
            if (!this.#styleClipboard) throw new Error('Copy a block style first.');
            const raw = this.state.getBlock(path);
            const attributes = { ...raw.attributes };
            if (this.#styleClipboard.inline) attributes.style = this.#styleClipboard.inline; else delete attributes.style;
            const styles = structuredClone(this.#styleClipboard.styles);
            const responsive = Object.entries(styles).some(([group, values]) => /^(desktop|tablet|mobile)(:|$)/.test(group) && Object.keys(values).length);
            this.state.updateBlock(path, { attributes, _quill: { ...raw._quill, styles } }, 'properties', responsive ? { breakpoints: this.breakpoints } : null);
            this.notify?.('Style pasted.');
        }
        duplicateBlock(path = this.selectedPath) { const result = this.state.duplicateBlock(path); this.notify?.('Block duplicated.'); return result; }
        removeBlock(path = this.selectedPath) { const result = this.state.removeBlock(path); this.notify?.('Block deleted.'); return result; }

        setContent(content, path = this.selectedPath) { this.updateBlock({ content }, path); }
        setProps(props, path = this.selectedPath) { this.updateBlock({ descriptor: { ...this.getBlock(path).descriptor, props } }, path); }
        insertBlock(key, sectionIndex, parentPath = this.selectedPath, index = null) {
            const definition = this.blocks.get(key);
            if (!definition) throw new Error(`Unknown block definition: ${key}`);
            return this.state.insertBlocks(sectionIndex, definition.blocks, parentPath, index);
        }
        on(name, listener, options) {
            this.addEventListener(name, listener, options);
            return () => this.removeEventListener(name, listener, options);
        }
        save(mode = 'draft') { return this.ui.save.save(mode); }
        registerRenderer(key, renderer) {
            if (typeof renderer !== 'function') throw new Error('A canvas renderer must be a function.');
            this.#renderers.set(key, renderer);
            this.dispatchEvent(new Event('rendererschange'));
            return () => {
                if (this.#renderers.get(key) === renderer) {
                    this.#renderers.delete(key);
                    this.dispatchEvent(new Event('rendererschange'));
                }
            };
        }
    }
    window.QuillEditorApi = QuillEditorApi;
})();
