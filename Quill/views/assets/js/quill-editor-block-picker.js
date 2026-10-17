(() => {
    'use strict';
    class QuillEditorBlockPicker {
        constructor(panel, editor, sectionSelect, onError) {
            this.panel = panel; this.editor = editor; this.sectionSelect = sectionSelect; this.onError = onError;
            this.insertionTarget = null; this.expanded = new Map(); this.session = 0;
            editor.on('componentschange', () => this.render());
            editor.blocks.addEventListener('change', () => this.render());
            document.getElementById('editor-block-close').addEventListener('click', () => this.close());
            document.getElementById('editor-block-search').addEventListener('input', () => this.render());
            panel.addEventListener('keydown', event => { if (event.key === 'Escape') this.close(); });
            editor.on('selectionchange', () => this.updateTarget());
            editor.on('change', () => { if (this.insertionTarget) this.close(); });
            this.render();
        }
        updateTarget() {
            if (this.insertionTarget) {
                const { sectionIndex, parentPath, index } = this.insertionTarget;
                const parent = parentPath === null ? this.editor.getDocument().sections[sectionIndex].name : `<${this.editor.getBlock(parentPath).descriptor.name}>`;
                document.getElementById('editor-insert-target').textContent = `Insert at position ${index + 1} in ${parent}`;
                return;
            }
            const path = this.editor.selectedPath;
            const index = path === null ? Number(this.sectionSelect.value) : Number(path.split('.')[0]);
            document.getElementById('editor-insert-target').textContent = path === null
                ? `Insert into ${this.editor.getDocument().sections[index]?.name || 'document'}`
                : `Insert inside <${this.editor.getSelectedBlock().descriptor.name}>`;
        }
        open(target = null, { componentsOnly = false } = {}) {
            this.editor.ui.pageSettings?.close();
            const search = document.getElementById('editor-block-search');
            if (this.componentsOnly !== componentsOnly) search.value = '';
            search.placeholder = componentsOnly ? 'Search components…' : 'Search blocks…';
            this.panel.querySelector('label[for=editor-block-search]').textContent = componentsOnly ? 'Search components' : 'Search blocks';
            this.componentsOnly = componentsOnly;
            this.panel.querySelector('h2').textContent = componentsOnly ? 'Components' : 'Add block';
            this.render();
            if (target === null && this.editor.isReadOnly()) {
                const parts = this.editor.selectedPath.split('.'), index = Number(parts.pop());
                target = { sectionIndex: Number(parts[0]), parentPath: parts.length > 1 ? parts.join('.') : null, index: index + 1 };
            }
            this.session++;
            this.insertionTarget = target;
            this.editor.dispatchEvent(new CustomEvent('insertiontargetchange', { detail: target }));
            this.updateTarget(); this.panel.hidden = false;
            document.getElementById('editor-layers').hidden = true;
            document.getElementById('editor-add-block').setAttribute('aria-expanded', String(!componentsOnly));
            document.getElementById('editor-add-component').setAttribute('aria-expanded', String(componentsOnly));
            document.getElementById('editor-block-search').focus();
        }
        close() {
            this.session++;
            this.insertionTarget = null;
            this.editor.dispatchEvent(new CustomEvent('insertiontargetchange', { detail: null }));
            this.panel.hidden = true; document.getElementById('editor-layers').hidden = false;
            document.getElementById('editor-add-block').setAttribute('aria-expanded', 'false');
            document.getElementById('editor-add-component').setAttribute('aria-expanded', 'false');
            document.getElementById(this.componentsOnly ? 'editor-add-component' : 'editor-add-block').focus();
        }
        category(fragment, name, search) {
            const section = document.createElement('details'); section.className = 'editor-block-category'; section.dataset.blockCategory = name;
            section.open = !!search || (this.expanded.get(name) ?? true);
            section.addEventListener('toggle', () => { if (!search && section.isConnected) this.expanded.set(name, section.open); });
            const heading = document.createElement('summary'); heading.textContent = name;
            const chevron = document.createElement('i'); chevron.className = 'fa-solid fa-chevron-down'; chevron.setAttribute('aria-hidden', 'true'); heading.append(chevron);
            const grid = document.createElement('div'); grid.className = 'editor-block-grid';
            section.append(heading, grid); fragment.append(section); return grid;
        }
        render() {
            const container = this.panel.querySelector('.editor-block-picker');
            const fragment = document.createDocumentFragment();
            const search = document.getElementById('editor-block-search').value.toLowerCase();
            const categories = new Map();
            for (const definition of this.componentsOnly ? [] : this.editor.blocks.all()) {
                if (!`${definition.name} ${definition.description || ''} ${definition.category || ''}`.toLowerCase().includes(search)) continue;
                const category = definition.category || 'Other';
                if (!categories.has(category)) {
                    categories.set(category, this.category(fragment, category, search));
                }
                const button = document.createElement('button'); button.type = 'button';
                button.dataset.blockKey = definition.key; button.dataset.addTag = definition.blocks[0].descriptor.name;
                button.title = definition.description || definition.name;
                const icon = document.createElement('i');
                icon.className = /^(?:fa(?:s|r|b|-solid|-regular|-brands)?\s+)?fa-[a-z0-9-]+(?:\s+fa-[a-z0-9-]+)*$/.test(definition.icon || '')
                    ? `editor-block-icon ${definition.icon}` : 'editor-block-icon fa-solid fa-cube';
                if (!/\b(?:fa|fas|far|fab|fa-solid|fa-regular|fa-brands)\s/.test(definition.icon || '')) icon.classList.add('fa-solid');
                icon.setAttribute('aria-hidden', 'true');
                const name = document.createElement('strong'); name.textContent = definition.name;
                button.append(icon, name);
                button.addEventListener('click', () => {
                    try {
                        const target = this.insertionTarget;
                        if (target) this.editor.insertBlock(definition.key, target.sectionIndex, target.parentPath, target.index);
                        else this.editor.insertBlock(definition.key, Number(this.sectionSelect.value));
                        this.close(); this.onError(null);
                    }
                    catch (error) { this.onError(error); }
                });
                categories.get(category).append(button);
            }
            const components = [...(this.editor.ui?.components?.all() || []), ...(this.editor.ui?.components?.externals() || [])].filter(component => `${component.name} ${component.description} ${component.provider || ''} Components`.toLowerCase().includes(search));
            if (components.length || !search) {
                const grid = this.category(fragment, 'Components', search);
                if (!components.length) {
                    const empty = document.createElement('p'); empty.className = 'editor-component-empty'; empty.textContent = 'Save a block as a component to reuse it here.'; grid.append(empty);
                }
                for (const component of components) {
                    const button = document.createElement('button'); button.type = 'button'; button.dataset.componentId = component.id;
                    button.title = component.description || component.name;
                    const icon = document.createElement('i'); icon.className = 'editor-block-icon fa-solid fa-cubes'; icon.setAttribute('aria-hidden', 'true');
                    const name = document.createElement('strong'); name.textContent = component.name; button.append(icon, name);
                    if (component.provider) {
                        const provider = document.createElement('small'); provider.className = 'editor-resource-provider'; provider.textContent = `Provided by ${component.provider}`; button.append(provider);
                        button.title = `${component.description || component.name} · Inserts a local copy`;
                    }
                    button.addEventListener('click', async () => {
                        const session = this.session, current = () => session === this.session && !this.panel.hidden;
                        const target = this.insertionTarget;
                        const section = target?.sectionIndex ?? Number(this.sectionSelect.value);
                        const parent = target ? target.parentPath : this.editor.selectedPath;
                        button.disabled = true;
                        try {
                            const inserted = await this.editor.ui.components.insert(component.id, section, parent, target?.index ?? null, current);
                            if (inserted !== null) { this.close(); this.onError(null); }
                        } catch (error) { if (current()) this.onError(error); }
                        finally { if (button.isConnected) button.disabled = false; }
                    });
                    grid.append(button);
                }
            }
            if (!categories.size && !components.length && search) { const empty = document.createElement('p'); empty.textContent = 'No matching blocks.'; fragment.append(empty); }
            container.replaceChildren(fragment);
        }
    }
    window.QuillEditorBlockPicker = QuillEditorBlockPicker;
})();
