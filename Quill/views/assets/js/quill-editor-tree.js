(() => {
    'use strict';
    class QuillEditorTree {
        constructor(root, state, sectionSelect, showError, registry = null) {
            const tree = root.querySelector('#editor-tree');
            const get = id => root.querySelector(`#${id}`);
            const collapsed = new Set();
            const closedSections = new Set();
            const abort = new AbortController();
            function label(block) {
                const hidden = Object.hasOwn(block.attributes, 'hidden') && block.attributes.hidden !== false && block.attributes.hidden !== null;
                const name = (block._quill?.component?.name || block._quill?.editor?.label || block.descriptor.name) + (hidden ? ' (hidden)' : '');
                if (block._quill?.component) return name;
                const text = block.content.trim().replace(/\s+/g, ' ');
                return text ? `${name} · ${text.slice(0, 45)}` : name;
            }

            function renderTree() {
                const documentState = state.getDocument();
                const fragment = document.createDocumentFragment();
                let count = 0;
                function blockList(blocks, parentPath) {
                    const list = document.createElement('ul');
                    list.className = 'editor-tree-list';
                    blocks.forEach((block, index) => {
                        const shared = !!block._quill?.component;
                        count++;
                        const path = `${parentPath}.${index}`;
                        const item = document.createElement('li');
                        const row = document.createElement('div');
                        row.className = 'editor-tree-row'; row.toggleAttribute('data-component', shared);
                        row.draggable = true;
                        row.addEventListener('dragstart', event => {
                            event.dataTransfer.setData('application/x-quill-block', path);
                            event.dataTransfer.effectAllowed = 'move';
                            state.select(path);
                        });
                        row.addEventListener('dragover', event => {
                            if (!event.dataTransfer.types.includes('application/x-quill-block')) return;
                            event.preventDefault();
                            const bounds = row.getBoundingClientRect();
                            const ratio = (event.clientY - bounds.top) / bounds.height;
                            const source = state.selectedPath;
                            if (source && (source === path || path.startsWith(`${source}.`))) {
                                row.dataset.drop = 'invalid'; event.dataTransfer.dropEffect = 'none'; return;
                            }
                            const leaf = shared || ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr','script','style'].includes(block.descriptor.name.toLowerCase());
                            row.dataset.drop = ratio < .25 ? 'before' : (ratio > .75 ? 'after' : leaf ? (ratio < .5 ? 'before' : 'after') : 'inside');
                        });
                        row.addEventListener('dragleave', () => { delete row.dataset.drop; });
                        row.addEventListener('drop', event => {
                            event.preventDefault();
                            event.stopPropagation();
                            try {
                                const source = event.dataTransfer.getData('application/x-quill-block');
                                if (row.dataset.drop === 'invalid') return;
                                if (source) { collapsed.clear(); state.moveBlock(source, path, row.dataset.drop || 'inside'); }
                            } catch (error) { showError(error); }
                            delete row.dataset.drop;
                        });
                        const disclosure = document.createElement(block.children.length && !shared ? 'button' : 'span');
                        disclosure.className = 'editor-tree-toggle';
                        if (block.children.length && !shared) {
                            disclosure.type = 'button';
                            disclosure.innerHTML = `<i class="fa-solid fa-chevron-${collapsed.has(path) ? 'right' : 'down'}" aria-hidden="true"></i>`;
                            disclosure.setAttribute('aria-label', `Toggle children of ${block.descriptor.name}`);
                            disclosure.setAttribute('aria-expanded', String(!collapsed.has(path)));
                            disclosure.addEventListener('click', () => {
                                if (collapsed.has(path)) collapsed.delete(path); else collapsed.add(path);
                                renderTree();
                                tree.querySelector(`[data-toggle-path="${path}"]`)?.focus();
                            });
                            disclosure.dataset.togglePath = path;
                        }
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'editor-tree-select';
                        button.dataset.path = path;
                        button.setAttribute('aria-current', String(state.selectedPath === path));
                        button.title = label(block);
                        const text = document.createElement('span');
                        text.className = 'editor-tree-label';
                        text.textContent = label(block);
                        const icon = document.createElement('i');
                        const registeredIcon = registry?.get(block._quill?.definition_key)?.icon;
                        const tagIcons = { section: 'layer-group', div: 'square', p: 'paragraph', h1: 'heading', h2: 'heading', h3: 'heading', a: 'link', img: 'image', button: 'hand-pointer', hr: 'minus' };
                        icon.className = shared ? 'fa-solid fa-cubes' : /^(?:fa-solid|fa-regular|fa-brands|fas|far|fab) fa-[a-z0-9-]+$/.test(registeredIcon || '') ? registeredIcon : `fa-solid fa-${tagIcons[block.descriptor.name] || 'cube'}`;
                        icon.setAttribute('aria-hidden', 'true'); button.append(icon, text);
                        button.addEventListener('click', () => state.select(path));
                        row.append(disclosure, button);
                        item.append(row);
                        if (block.children.length && !shared) {
                            const children = blockList(block.children, path);
                            children.hidden = collapsed.has(path);
                            item.append(children);
                        }
                        list.append(item);
                    });
                    return list;
                }
                documentState.sections.forEach((section, sectionIndex) => {
                    const group = document.createElement('details');
                    group.className = 'editor-tree-section';
                    group.dataset.section = String(sectionIndex);
                    group.open = !closedSections.has(sectionIndex);
                    group.addEventListener('toggle', () => {
                        if (!group.isConnected) return;
                        if (group.open) closedSections.delete(sectionIndex); else closedSections.add(sectionIndex);
                    });
                    const summary = document.createElement('summary');
                    summary.textContent = section.name;
                    summary.addEventListener('click', () => { sectionSelect.value = String(sectionIndex); state.select(null); });
                    summary.addEventListener('dragover', event => {
                        if (!event.dataTransfer.types.includes('application/x-quill-block')) return;
                        event.preventDefault(); event.dataTransfer.dropEffect = 'move'; summary.dataset.drop = 'inside';
                    });
                    summary.addEventListener('dragleave', () => { delete summary.dataset.drop; });
                    summary.addEventListener('dragend', () => { delete summary.dataset.drop; });
                    summary.addEventListener('drop', event => {
                        event.preventDefault(); event.stopPropagation(); delete summary.dataset.drop;
                        const source = event.dataTransfer.getData('application/x-quill-block');
                        try { if (source) { collapsed.clear(); state.moveBlock(source, String(sectionIndex), 'inside'); } } catch (error) { showError(error); }
                    });
                    group.append(summary, blockList(section.blocks, String(sectionIndex)));
                    if (!section.blocks.length) {
                        const empty = document.createElement('p');
                        empty.className = 'editor-tree-empty';
                        empty.textContent = 'No blocks in this section.';
                        group.append(empty);
                    }
                    fragment.append(group);
                });
                tree.replaceChildren(fragment);
                get('editor-block-count').textContent = String(count);
                get('editor-empty-canvas').hidden = count !== 0 || documentState.sections.length === 0;
            }

            function updateSelection() {
                const path = state.selectedPath;
                if (path !== null) {
                    const parts = path.split('.');
                    for (let length = 2; length < parts.length; length++) {
                        const ancestorPath = parts.slice(0, length).join('.');
                        collapsed.delete(ancestorPath);
                        const toggle = tree.querySelector(`[data-toggle-path="${ancestorPath}"]`);
                        if (toggle) {
                            toggle.innerHTML = '<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>';
                            toggle.setAttribute('aria-expanded', 'true');
                            toggle.closest('li').querySelector(':scope > ul').hidden = false;
                        }
                    }
                    sectionSelect.value = parts[0];
                    tree.querySelector(`[data-section="${parts[0]}"]`).open = true;
                }
                tree.querySelectorAll('[data-path]').forEach(button => button.setAttribute('aria-current', String(button.dataset.path === path)));
                root.querySelectorAll('[data-move]').forEach(button => { button.disabled = path === null || state.componentRoot(path) !== null; });
            }


            const refresh = () => { renderTree(); updateSelection(); };
            registry?.addEventListener('change', refresh, { signal: abort.signal });
            state.addEventListener('componentrefresh', refresh, { signal: abort.signal });
            state.addEventListener('change', refresh, { signal: abort.signal });
            state.addEventListener('selectionchange', updateSelection, { signal: abort.signal });
            root.querySelectorAll('[data-move]').forEach(button => {
                button.disabled = true;
                button.addEventListener('click', () => {
                    try { collapsed.clear(); state.moveSelected(button.dataset.move); showError(null); }
                    catch (error) { showError(error); }
                }, { signal: abort.signal });
            });
            this.destroy = () => abort.abort();
            refresh();
        }
    }
    window.QuillEditorTree = QuillEditorTree;
})();
