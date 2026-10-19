(() => {
    'use strict';
    class QuillEditorProperties {
        #editor;
        #prefix;
        #scopeControls;
        #form;
        #tabs;
        #panels;
        #categories = new Map();
        #mounted = new Map();
        #ticket = 0;
        #activeCategory = null;
        #selectionPath = null;
        #subscriptions = [];
        #abort = new AbortController();

        constructor(form, editor, { prefix = 'editor', scopeControls = true } = {}) {
            this.#editor = editor; this.#prefix = prefix; this.#scopeControls = scopeControls; this.#form = form;
            this.#tabs = form.querySelector('[role=tablist]');
            this.#panels = form.querySelector(`[id="${prefix}-property-panels"]`);
            if (scopeControls) {
            const viewport = form.querySelector('#editor-style-viewport');
            viewport.addEventListener('click', event => {
                const button = event.target.closest('[data-style-viewport]');
                if (!button) return;
                editor.setStyleViewport(button.dataset.styleViewport);
                if (editor.styleViewport !== 'all') {
                    const device = document.getElementById('editor-viewport');
                    device.value = { desktop: '1440', tablet: '768', mobile: '390' }[editor.styleViewport];
                    device.dispatchEvent(new Event('change'));
                }
            }, { signal: this.#abort.signal });
            form.querySelector('#editor-style-state').addEventListener('change', event => {
                editor.setStyleState(event.target.value);
            }, { signal: this.#abort.signal });
            const startHidden = form.querySelector('#editor-start-hidden');
            startHidden.addEventListener('change', () => {
                try { editor.setAttribute('hidden', startHidden.checked ? true : null); }
                catch (error) { editor.reportError(error); this.refresh(); }
            }, { signal: this.#abort.signal });
            }
            form.addEventListener('submit', event => event.preventDefault(), { signal: this.#abort.signal });
            this.#tabs.addEventListener('click', event => {
                const tab = event.target.closest('[data-tab]');
                if (tab) this.#activate(tab.dataset.tab);
            }, { signal: this.#abort.signal });
            this.#tabs.addEventListener('keydown', event => {
                const tabs = [...this.#tabs.querySelectorAll('[data-tab]')];
                let index = tabs.indexOf(document.activeElement);
                if (index < 0) return;
                if (event.key === 'ArrowRight') index = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft') index = (index + tabs.length - 1) % tabs.length;
                else if (event.key === 'Home') index = 0;
                else if (event.key === 'End') index = tabs.length - 1;
                else return;
                event.preventDefault();
                this.#activate(tabs[index].dataset.tab);
                tabs[index].focus();
            }, { signal: this.#abort.signal });
            const refresh = () => this.refresh();
            this.#subscriptions.push(editor.on('change', refresh), editor.on('selectionchange', refresh), editor.on('canvasrendered', refresh), editor.on('styleviewportchange', refresh), editor.on('stylestatechange', refresh));
            editor.properties.addEventListener('change', refresh);
            this.#subscriptions.push(() => editor.properties.removeEventListener('change', refresh));
            this.refresh();
        }

        async refresh() {
            const ticket = ++this.#ticket;
            const block = this.#editor.getSelectedBlock();
            if (this.#scopeControls) {
            this.#form.querySelectorAll('[data-style-viewport]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.styleViewport === this.#editor.styleViewport)));
            document.getElementById('editor-style-state').value = this.#editor.styleState;
            const context = document.getElementById('editor-style-context');
            const viewportLabel = { all: 'All devices', desktop: 'Desktop', tablet: 'Tablet and smaller', mobile: 'Mobile' }[this.#editor.styleViewport];
            const stateLabel = document.getElementById('editor-style-state').selectedOptions[0].textContent;
            const description = `${viewportLabel} · ${stateLabel}${this.#editor.styleScope === 'base' ? ' · Shared styles' : ' · Overrides'}`;
            if (context.textContent !== description) context.textContent = description;
            context.dataset.override = String(this.#editor.styleScope !== 'base');
            const startHidden = document.getElementById('editor-start-hidden');
            startHidden.checked = !!block && Object.hasOwn(block.attributes, 'hidden') && block.attributes.hidden !== false && block.attributes.hidden !== null;
            startHidden.disabled = !block || this.#editor.isReadOnly();
            }
            const path = this.#editor.selectedPath;
            const definitions = this.#editor.properties.all();
            const entries = [];
            if (block && !this.#editor.isReadOnly()) {
                await Promise.all(definitions.map(async definition => {
                    try {
                        if (!await this.#editor.modules.matches(definition.condition, block, this.#editor)) return;
                        const visible = await Promise.all(definition.elements.map(async (element, index) => {
                            if (typeof element === 'string') element = { definition: element };
                            const entry = { id: `${definition.key}/${index}`, definition, element, signature: `${this.#editor.properties.version(definition.key)}/${JSON.stringify(element)}` };
                            try { return await this.#editor.modules.matches(element.condition, block, this.#editor) ? entry : null; }
                            catch (error) { return { ...entry, error }; }
                        }));
                        entries.push(...visible.filter(Boolean));
                    } catch (error) {
                        entries.push({ id: `${definition.key}/condition-error`, definition, element: {}, signature: 'error', error });
                    }
                }));
            }
            if (ticket !== this.#ticket) return;
            entries.sort((left, right) => definitions.findIndex(value => value.key === left.definition.key) - definitions.findIndex(value => value.key === right.definition.key)
                || Number(left.id.split('/').at(-1)) - Number(right.id.split('/').at(-1)));
            const desired = new Map(entries.map(entry => [entry.id, entry]));
            for (const [id, instance] of this.#mounted) {
                if (!desired.has(id) || desired.get(id).signature !== instance.signature) this.#unmount(id);
            }
            const categoryKeys = new Set(entries.map(entry => entry.definition.category));
            for (const [key, category] of this.#categories) {
                if (!categoryKeys.has(key)) { category.tab.remove(); category.panel.remove(); this.#categories.delete(key); }
            }
            const positions = new Map();
            let categoryPosition = 0;
            for (const entry of entries) {
                const key = entry.definition.category;
                if (!this.#categories.has(key)) this.#createCategory(key, entry.definition.categoryLabel || key);
                const category = this.#categories.get(key);
                if (!this.#mounted.has(entry.id)) {
                    const container = document.createElement('div');
                    container.className = 'editor-property-extension';
                    container.id = this.#prefix + '-property-' + [...entry.id].map(character => character.codePointAt(0).toString(16).padStart(2, '0')).join('');
                    container.dataset.property = entry.definition.key;
                    const item = { container, abort: new AbortController(), signature: entry.signature, instance: null, path: null };
                    this.#mounted.set(entry.id, item);
                    item.ready = this.#mount(entry, item);
                }
                const container = this.#mounted.get(entry.id).container;
                const position = positions.get(key) || 0;
                if (category.panel.children[position] !== container) category.panel.insertBefore(container, category.panel.children[position] || null);
                positions.set(key, position + 1);
                if (position === 0) {
                    this.#labelCategory(category.tab, entry.definition.categoryLabel || key, entry.definition.icon);
                    if (this.#tabs.children[categoryPosition] !== category.tab) this.#tabs.insertBefore(category.tab, this.#tabs.children[categoryPosition] || null);
                    if (this.#panels.children[categoryPosition] !== category.panel) this.#panels.insertBefore(category.panel, this.#panels.children[categoryPosition] || null);
                    categoryPosition++;
                }
            }
            if (path !== this.#selectionPath) {
                const mediaCategory = { img: 'image', video: 'video' }[block?.descriptor.name.toLowerCase()];
                if (categoryKeys.has(mediaCategory)) this.#activeCategory = mediaCategory;
                this.#selectionPath = path;
            }
            if (!categoryKeys.has(this.#activeCategory)) this.#activeCategory = entries[0]?.definition.category ?? null;
            this.#activate(this.#activeCategory);
            let empty = this.#panels.querySelector('[data-no-properties]');
            if (!entries.length && block && !empty) {
                empty = document.createElement('p');
                empty.dataset.noProperties = '';
                empty.className = 'editor-panel-description';
                empty.textContent = 'No settings match this block.';
                this.#panels.append(empty);
            } else if (entries.length || !block) empty?.remove();
            await Promise.all(entries.map(async entry => {
                const item = this.#mounted.get(entry.id);
                await item.ready;
                if (ticket !== this.#ticket || item.abort.signal.aborted) return;
                try {
                    await item.instance?.update?.(structuredClone(block), { path, selectionChanged: item.path !== path || item.scope !== this.#editor.styleScope });
                    item.path = path; item.scope = this.#editor.styleScope;
                } catch (error) { this.#error(item, entry, error); }
            }));
        }

        async #mount(entry, item) {
            try {
                if (entry.error) throw entry.error;
                const mount = await this.#editor.modules.load(entry.element.definition);
                if (item.abort.signal.aborted) return;
                const instance = await mount({ container: item.container, editor: this.#editor, signal: item.abort.signal });
                const normalized = typeof instance === 'function' ? { destroy: instance } : (instance || {});
                if (item.abort.signal.aborted) { normalized.destroy?.(); return; }
                item.instance = normalized;
            } catch (error) {
                if (!item.abort.signal.aborted) this.#error(item, entry, error);
            }
        }

        #error(item, entry, error) {
            let feedback = item.container.querySelector('[data-module-error]');
            if (!feedback) {
                feedback = document.createElement('p');
                feedback.dataset.moduleError = '';
                feedback.className = 'editor-property-error';
                feedback.setAttribute('role', 'alert');
                item.container.append(feedback);
            }
            const message = `${entry.definition.key}: ${error.message}`;
            feedback.hidden = true;
            if (feedback.textContent !== message) this.#editor.reportError(new Error(message));
            feedback.textContent = message;
        }

        #labelCategory(tab, label, classes) {
            const icon = document.createElement('i');
            icon.className = /^(?:fa-solid|fa-regular|fa-brands|fas|far|fab) fa-[a-z0-9-]+$/.test(classes || '') ? classes : 'fa-solid fa-sliders';
            icon.setAttribute('aria-hidden', 'true');
            const caption = document.createElement('span'); caption.textContent = label;
            tab.replaceChildren(icon, caption);
        }

        #createCategory(key, label) {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.role = 'tab';
            tab.id = `${this.#prefix}-tab-${key}`;
            tab.dataset.tab = key;
            this.#labelCategory(tab, label);
            tab.setAttribute('aria-controls', `${this.#prefix}-panel-${key}`);
            const panel = document.createElement('div');
            panel.id = `${this.#prefix}-panel-${key}`;
            panel.role = 'tabpanel';
            panel.dataset.panel = key;
            panel.setAttribute('aria-labelledby', tab.id);
            this.#categories.set(key, { tab, panel });
        }

        #activate(key) {
            this.#activeCategory = key;
            for (const [category, { tab, panel }] of this.#categories) {
                tab.setAttribute('aria-selected', String(category === key));
                tab.tabIndex = category === key ? 0 : -1;
                panel.hidden = category !== key;
            }
        }

        async showCategory(key) {
            await this.refresh();
            const category = this.#categories.get(key);
            if (!category) throw new Error('This category does not apply to the selected block.');
            this.#activate(key);
            return category.panel;
        }

        #unmount(id) {
            const item = this.#mounted.get(id);
            this.#mounted.delete(id);
            item.abort.abort();
            try { item.instance?.destroy?.(); } catch (error) { this.#editor.reportError(error); }
            item.container.remove();
        }
        destroy() {
            this.#ticket++;
            this.#abort.abort();
            this.#subscriptions.splice(0).forEach(unsubscribe => unsubscribe());
            for (const id of [...this.#mounted.keys()]) this.#unmount(id);
            for (const { tab, panel } of this.#categories.values()) { tab.remove(); panel.remove(); }
            this.#categories.clear();
        }
    }
    window.QuillEditorProperties = QuillEditorProperties;
})();
