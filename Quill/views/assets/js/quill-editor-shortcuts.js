(() => {
    'use strict';
    const storageKey = 'awt.quill.editor.shortcuts.v1';
    const actions = [
        ['publish', 'Publish', 'Mod+Shift+KeyS'], ['save', 'Save draft', 'Mod+KeyS'],
        ['undo', 'Undo', 'Mod+KeyZ'], ['redo', 'Redo', 'Mod+Shift+KeyZ'],
        ['copy', 'Copy', 'Mod+KeyC'], ['paste', 'Paste', 'Mod+KeyV'], ['delete', 'Delete', 'Delete'],
        ['copyStyle', 'Copy style', 'Mod+Alt+KeyC'], ['openActions', 'Open actions', 'Mod+Alt+KeyA'],
        ['openAnimations', 'Open animations', 'Mod+Alt+KeyM'], ['cut', 'Cut', 'Mod+KeyX'], ['duplicate', 'Duplicate', 'Mod+KeyD'],
    ];
    const defaults = Object.fromEntries(actions.map(([id, , binding]) => [id, binding]));
    const valid = value => typeof value === 'string' && /^(?:Mod\+)?(?:Alt\+)?(?:Shift\+)?(?:Key[A-Z]|Digit[0-9]|F(?:[1-9]|1[0-2])|Delete|Backspace|Arrow(?:Up|Down|Left|Right)|Space|Enter|Equal|Minus|BracketLeft|BracketRight|Backslash|Semicolon|Quote|Comma|Period|Slash|Backquote)$/.test(value)
        && (value.includes('Mod+') || value.includes('Alt+') || /^(?:Delete|Backspace|F(?:[1-9]|1[0-2]))$/.test(value));
    const combo = event => {
        const code = event.code || (event.key?.length === 1 && /[a-z]/i.test(event.key) ? `Key${event.key.toUpperCase()}` : event.key);
        return `${event.ctrlKey || event.metaKey ? 'Mod+' : ''}${event.altKey ? 'Alt+' : ''}${event.shiftKey ? 'Shift+' : ''}${code}`;
    };
    const format = binding => binding ? binding.replace('Mod', /Mac|iPhone|iPad/.test(navigator.platform) ? 'Cmd' : 'Ctrl').replace(/Key|Digit/g, '').split('+').join(' + ') : 'Not assigned';

    class QuillEditorShortcuts {
        #editor; #bindings; #dialog; #clipboard = null; #installed = new WeakSet();
        constructor(editor) {
            this.#editor = editor;
            this.#bindings = { ...defaults };
            try {
                const stored = JSON.parse(localStorage.getItem(storageKey));
                if (stored && typeof stored === 'object' && !Array.isArray(stored)) {
                    const incoming = Object.fromEntries(actions.map(([id]) => [id, Object.hasOwn(stored, id) ? stored[id] : defaults[id]]));
                    const assigned = Object.values(incoming).filter(value => value !== null);
                    if (assigned.every(valid) && new Set(assigned).size === assigned.length) this.#bindings = incoming;
                }
            } catch { /* Browser storage is optional. */ }
            this.#dialog = document.getElementById('editor-shortcuts-dialog');
            const opener = document.getElementById('editor-shortcuts-open');
            opener.addEventListener('click', () => {
                editor.state.assertAvailable(); this.#render(); this.#dialog.showModal(); opener.setAttribute('aria-expanded', 'true');
            });
            this.#dialog.querySelectorAll('[data-close-shortcuts]').forEach(button => button.addEventListener('click', () => this.#dialog.close()));
            this.#dialog.addEventListener('close', () => { opener.setAttribute('aria-expanded', 'false'); opener.focus(); });
            this.#dialog.querySelector('[data-reset-shortcuts]').addEventListener('click', () => {
                this.#bindings = { ...defaults }; this.#render(); this.#persist(); this.#editor.notify('Shortcuts reset.');
            });
            const frame = document.getElementById('editor-canvas');
            const installFrame = () => { if (frame.contentDocument) this.#install(frame.contentDocument); };
            frame.addEventListener('load', installFrame); editor.on('canvasrendered', installFrame);
            this.#install(document); installFrame(); this.#updateTitles();
        }
        get bindings() { return { ...this.#bindings }; }
        #install(doc) {
            if (this.#installed.has(doc)) return;
            this.#installed.add(doc);
            doc.addEventListener('keydown', event => {
                if (event.defaultPrevented || event.isComposing || event.getModifierState?.('AltGraph') || document.querySelector('dialog[open]') || this.#editor.canvas.picking) return;
                const action = actions.find(([id]) => this.#bindings[id] === combo(event))?.[0];
                if (!action) return;
                const editing = event.target.closest?.('input, textarea, select, [contenteditable]:not([contenteditable=false])') || event.target.isContentEditable;
                if (editing && !['save', 'publish'].includes(action)) return;
                // Preserve ordinary text copy/cut when the user has explicitly selected text.
                if (['copy', 'cut'].includes(action) && !doc.getSelection()?.isCollapsed) return;
                event.preventDefault(); event.stopImmediatePropagation();
                if (!event.repeat) this.run(action).catch(error => this.#editor.reportError(error));
            }, true);
        }
        #notice(text, error = false) {
            const node = this.#dialog.querySelector('[data-shortcuts-status]'); node.textContent = text; node.classList.toggle('editor-action-error', error);
        }
        #persist() {
            try { localStorage.setItem(storageKey, JSON.stringify(this.#bindings)); this.#notice('Shortcuts are saved in this browser.'); }
            catch { this.#notice('Shortcuts apply for this session. Browser storage is unavailable.'); }
            this.#updateTitles();
        }
        #updateTitles() {
            for (const [id, name] of actions) {
                const button = document.getElementById(`editor-${id}`);
                if (button && ['undo', 'redo'].includes(id)) button.title = `${name}${this.#bindings[id] ? ` (${format(this.#bindings[id])})` : ''}`;
            }
        }
        #render() {
            const list = this.#dialog.querySelector('[data-shortcuts-list]'); list.replaceChildren();
            this.#notice('Shortcuts are saved in this browser.');
            for (const [id, name] of actions) {
                const row = document.createElement('div'); row.className = 'editor-shortcut-row';
                const label = document.createElement('label'); label.htmlFor = `editor-shortcut-${id}`; label.textContent = name;
                const field = document.createElement('input'); field.id = label.htmlFor; field.readOnly = true; field.value = format(this.#bindings[id]); field.dataset.shortcut = id;
                field.setAttribute('aria-describedby', 'editor-shortcuts-description'); field.title = 'Focus and press a key combination';
                field.addEventListener('keydown', event => {
                    if (event.key === 'Tab') return;
                    if (event.key === 'Escape') { field.blur(); return; }
                    event.preventDefault(); event.stopPropagation();
                    if (['Control', 'Meta', 'Alt', 'Shift'].includes(event.key)) return;
                    const binding = combo(event);
                    if (!valid(binding)) { this.#notice('Use Ctrl/Cmd or Alt with a key, a function key, Delete or Backspace.', true); return; }
                    const conflict = actions.find(([other]) => other !== id && this.#bindings[other] === binding);
                    if (conflict) { this.#notice(`${format(binding)} is already assigned to ${conflict[1]}.`, true); return; }
                    this.#bindings[id] = binding; field.value = format(binding); this.#persist();
                });
                const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'editor-action-icon'; clear.title = clear.ariaLabel = `Clear ${name} shortcut`;
                const icon = document.createElement('i'); icon.className = 'fa-solid fa-xmark'; icon.setAttribute('aria-hidden', 'true'); clear.append(icon);
                clear.addEventListener('click', () => { this.#bindings[id] = null; field.value = format(null); this.#persist(); });
                row.append(label, field, clear); list.append(row);
            }
        }
        #selected() {
            const path = this.#editor.selectedPath;
            if (path === null) throw new Error('Select a block first.');
            return path;
        }
        #copy(cut) {
            const path = this.#selected(), block = this.#editor.state.getBlock(path);
            if (cut) this.#editor.removeBlock(path);
            this.#clipboard = structuredClone(block);
            this.#editor.notify(cut ? 'Block cut.' : 'Block copied.');
        }
        #paste() {
            if (!this.#clipboard) throw new Error('Copy or cut a block before pasting.');
            const block = structuredClone(this.#clipboard);
            const renew = nodes => nodes.forEach(node => {
                if (node._quill?.component) return;
                if (node.attributes.id) node.attributes.id += '-copy-' + crypto.randomUUID().slice(0, 8);
                renew(node.children);
            });
            renew([block]);
            const path = this.#editor.selectedPath;
            if (path === null) {
                const sections = this.#editor.getDocument().sections;
                const section = Math.max(0, sections.findIndex(item => item.name === 'main'));
                this.#editor.state.insertBlocks(section, [block], null);
            } else {
                const parts = path.split('.'), index = Number(parts.pop());
                this.#editor.state.insertBlocks(Number(parts[0]), [block], parts.length > 1 ? parts.join('.') : null, index + 1);
            }
            this.#editor.notify('Block pasted.');
        }
        async run(action) {
            const editor = this.#editor;
            editor.state.assertAvailable();
            switch (action) {
                case 'save': case 'publish':
                    await editor.ui.save.save(action === 'save' ? 'draft' : 'publish');
                    editor.notify(action === 'save' ? 'Draft saved.' : 'Published.'); break;
                case 'undo': editor.ui.history.undo(); break;
                case 'redo': editor.ui.history.redo(); break;
                case 'copy': this.#copy(false); break;
                case 'cut': this.#copy(true); break;
                case 'paste': this.#paste(); break;
                case 'delete': editor.removeBlock(this.#selected()); break;
                case 'duplicate': editor.duplicateBlock(this.#selected()); break;
                case 'copyStyle': editor.copyStyle(this.#selected()); break;
                case 'openActions':
                    editor.ui.actions.open(null, editor.selectedPath); editor.notify('Actions opened.'); break;
                case 'openAnimations':
                    await editor.ui.animations.open(); editor.notify('Animation Studio opened.'); break;
                default: throw new Error('Unknown editor shortcut.');
            }
            editor.reportError(null);
        }
    }
    window.QuillEditorShortcuts = QuillEditorShortcuts;
})();
