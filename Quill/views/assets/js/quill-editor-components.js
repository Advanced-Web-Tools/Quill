(() => {
    'use strict';
    const make = (tag, className = '', text = '') => {
        const element = document.createElement(tag); element.className = className; element.textContent = text; return element;
    };
    class QuillEditorComponents {
        #editor; #bootstrap; #items; #dialog; #form; #name; #description; #error; #snapshot; #saving = false; #inserting = false;
        #sourcePath; #sourceRevision; #refreshing = false; #channel; #lastRefresh = 0; #detaching = false;
        constructor(editor, bootstrap) {
            this.#editor = editor; this.#bootstrap = bootstrap;
            if (typeof BroadcastChannel !== 'undefined') {
                this.#channel = new BroadcastChannel('quill-shared-components');
                this.#channel.onmessage = () => this.refresh(true).catch(error => editor.reportError(error));
            }
            const refresh = () => { if (!document.hidden) this.refresh().catch(error => editor.reportError(error)); };
            window.addEventListener('focus', refresh); document.addEventListener('visibilitychange', refresh);
            setInterval(refresh, 30000);
            window.addEventListener('quill.component.saved', event => {
                this.#channel?.postMessage({ id: event.detail }); refresh();
            });
            editor.on('change', event => { if (event.detail?.source === 'history') refresh(); }); this.#items = structuredClone(bootstrap.components || []);
            const dialog = make('dialog', 'editor-actions-dialog editor-component-dialog'); dialog.id = 'editor-component-dialog';
            dialog.setAttribute('aria-labelledby', 'editor-component-title');
            const form = make('form');
            const header = make('header', 'editor-action-dialog-header');
            const title = make('h2', '', 'Convert to component'); title.id = 'editor-component-title';
            const close = make('button', 'editor-action-icon'); close.type = 'button'; close.setAttribute('aria-label', 'Close component dialog');
            const icon = make('i', 'fa-solid fa-xmark'); icon.setAttribute('aria-hidden', 'true'); close.append(icon); close.addEventListener('click', () => dialog.close()); header.append(title, close);
            const body = make('div', 'editor-action-dialog-body');
            const hint = make('p', 'editor-panel-description', 'Create a shared component from this block and its children. Edit its content and styling in the component editor.'); body.append(hint);
            const field = (label, tag, id) => {
                const wrapper = make('label', 'editor-action-field', label); wrapper.htmlFor = id;
                const control = make(tag); control.id = id; wrapper.append(control); body.append(wrapper); return control;
            };
            this.#name = field('Name', 'input', 'editor-component-name'); this.#name.type = 'text'; this.#name.required = true; this.#name.maxLength = 255;
            this.#description = field('Description', 'textarea', 'editor-component-description'); this.#description.rows = 3; this.#description.maxLength = 65535;
            this.#error = make('p', 'editor-action-error'); this.#error.id = 'editor-component-error'; this.#error.role = 'alert'; this.#error.hidden = true; body.append(this.#error);
            const footer = make('footer', 'editor-action-dialog-footer');
            const cancel = make('button', '', 'Cancel'); cancel.type = 'button'; cancel.addEventListener('click', () => dialog.close());
            const save = make('button', 'button-primary', 'Save component'); save.type = 'submit'; save.id = 'editor-component-save'; footer.append(cancel, save);
            form.append(header, body, footer); dialog.append(form); document.body.append(dialog);
            this.#dialog = dialog; this.#form = form;
            form.addEventListener('submit', event => { event.preventDefault(); this.#save(); });
            dialog.addEventListener('cancel', event => { if (this.#saving) event.preventDefault(); });
            dialog.addEventListener('close', () => {
                this.#snapshot = null;
                document.querySelector('iframe')?.contentDocument?.querySelector('[data-toolbar-action=more]')?.focus({ preventScroll: true });
            });
        }
        all() { return structuredClone(this.#items).sort((a, b) => a.name.localeCompare(b.name)); }
        externals() { return structuredClone(this.#bootstrap.externalComponents || []).sort((a, b) => a.name.localeCompare(b.name)); }
        open(path = this.#editor.selectedPath) {
            if (this.#saving) return;
            this.#editor.state.assertEditable(path);
            this.#sourcePath = path; this.#sourceRevision = this.#editor.state.revision;
            const block = this.#editor.state.getBlock(path), document = this.#editor.getDocument();
            const used = new Set();
            const visit = block => {
                if (block._quill?.component) return;
                for (const field of Object.values(window.QuillActions.bindings)) if (block._quill?.editor?.[field]) used.add(block._quill.editor[field]);
                block.children.forEach(visit);
            };
            visit(block);
            const settings = { actions: (document.settings?.actions || []).filter(action => used.has(action.id)) };
            if (document.settings?.breakpoints) settings.breakpoints = structuredClone(document.settings.breakpoints);
            this.#snapshot = { schema_version: 1, sections: [{ name: 'main', blocks: [block] }], settings, assets: structuredClone(document.assets || this.#bootstrap.assets) };
            this.#name.value = block._quill?.editor?.label || ''; this.#description.value = ''; this.#error.hidden = true;
            if (!this.#dialog.open) this.#dialog.showModal(); this.#name.focus();
        }
        async #request(url, options = {}) {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, ...options });
            const result = await window.QuillEditorResponse.read(response, 'The server did not return a valid component response. Try again.');
            return result.data;
        }
        async #save() {
            if (this.#saving || !this.#form.reportValidity()) return;
            const name = this.#name.value.trim();
            if (!name) { this.#name.focus(); return; }
            this.#saving = true; this.#error.hidden = true;
            const save = this.#form.querySelector('[type=submit]'); save.textContent = 'Saving…';
            this.#form.querySelectorAll('button, input, textarea').forEach(control => { control.disabled = true; });
            try {
                const body = new FormData(document.getElementById('editor-csrf'));
                body.set('name', name); body.set('description', this.#description.value.trim()); body.set('document', JSON.stringify(this.#snapshot));
                const data = await this.#request(this.#bootstrap.componentUrl, { method: 'POST', body });
                const component = data?.component;
                if (!Number.isSafeInteger(component?.id) || component.id < 1 || typeof component.name !== 'string') throw new Error('The component response is invalid. Reload the Components list before saving again.');
                this.#items = [...this.#items.filter(item => item.id !== component.id), component];
                this.#editor.dispatchEvent(new Event('componentschange'));
                this.#lastRefresh = Date.now();
                if (this.#sourceRevision === this.#editor.state.revision) {
                    this.#editor.state.replaceWithComponent(this.#sourcePath, component, data.document || this.#snapshot);
                }
                this.#channel?.postMessage({ id: component.id });
                this.#dialog.close();
            } catch (error) { this.#error.textContent = error.message; this.#error.hidden = false; }
            finally {
                this.#saving = false; save.textContent = 'Save component';
                this.#form.querySelectorAll('button, input, textarea').forEach(control => { control.disabled = false; });
            }
        }
        async refresh(force = false) {
            if (this.#refreshing || this.#saving || this.#inserting || this.#detaching || this.#editor.canvas.interacting || (!force && Date.now() - this.#lastRefresh < 2000)) return;
            const instances = new Map();
            const visit = blocks => blocks.forEach(block => {
                if (block._quill?.component) instances.set(block._quill.component.id, block._quill.component);
                else visit(block.children);
            });
            this.#editor.getDocument().sections.forEach(section => visit(section.blocks));
            if (!instances.size) return;
            this.#refreshing = true; this.#lastRefresh = Date.now();
            try {
                const entries = await Promise.all([...instances].map(async ([id, previous]) => {
                    const data = await this.#request(`${this.#bootstrap.componentUrl}/${id}`);
                    if (!data?.document?.sections) throw new Error('Unable to refresh this shared component.');
                    return { info: data.component || previous, document: data.document };
                }));
                if (!this.#editor.canvas.interacting) this.#editor.state.refreshComponents(entries);
            } finally { this.#refreshing = false; }
        }
        async detach(path = this.#editor.selectedPath) {
            this.#editor.state.assertAvailable();
            if (this.#detaching) throw new Error('A component is already being converted.');
            const block = this.#editor.state.getBlock(path), info = block._quill?.component;
            if (!info || this.#editor.state.componentRoot(path) !== path) throw new Error('Select the whole component instance.');
            const revision = this.#editor.state.revision, original = JSON.stringify(block);
            this.#detaching = true;
            try {
                const query = new URLSearchParams({ instance: info.instanceId, version: info.version });
                const data = await this.#request(`${this.#bootstrap.componentUrl}/${info.id}/block?${query}`);
                this.#editor.state.assertAvailable();
                if (this.#editor.state.revision !== revision || this.#editor.selectedPath !== path || JSON.stringify(this.#editor.state.getBlock(path)) !== original) throw new Error('The selection changed. Choose the component and try again.');
                if (data.component?.id !== info.id || data.component?.version !== info.version) throw new Error('The component changed. Refresh its preview and try again.');
                return this.#editor.state.detachComponent(path, data.document, data.targetMap);
            } finally { this.#detaching = false; }
        }
        async insert(id, sectionIndex, parentPath, index = null, current = () => true) {
            if (this.#bootstrap.editorType === 'component' && id === this.#bootstrap.editorId) throw new Error('A component cannot contain itself.');
            if (this.#inserting) throw new Error('A component is already being inserted.');
            this.#inserting = true;
            const revision = this.#editor.state.revision, selected = this.#editor.selectedPath;
            try {
                const external = typeof id === 'string';
                const data = await this.#request(external ? `${this.#bootstrap.externalComponentUrl}/${encodeURIComponent(id)}` : `${this.#bootstrap.componentUrl}/${id}`);
                if (!current()) return null;
                if (revision !== this.#editor.state.revision || selected !== this.#editor.selectedPath) throw new Error('The document changed. Choose the insertion point again.');
                const component = data?.document;
                if (!component || !Array.isArray(component.sections)) throw new Error('The component document is invalid.');
                const blocks = component.sections.flatMap(section => section.blocks);
                if (!blocks.length) throw new Error('This component is empty. Add blocks in its editor first.');
                const info = data.component || this.#items.find(item => item.id === id) || { id, name: 'Shared component' };
                if (info.id !== id) throw new Error('The component response does not match the selected source.');
                this.#lastRefresh = Date.now();
                if (external) return this.#editor.state.insertBlocks(sectionIndex, blocks, parentPath, index);
                return this.#editor.state.insertComponent(info, component, sectionIndex, parentPath, index);
            } finally { this.#inserting = false; }
        }
    }
    window.QuillEditorComponents = QuillEditorComponents;
})();
