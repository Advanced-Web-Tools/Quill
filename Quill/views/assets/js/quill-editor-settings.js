(() => {
    'use strict';
    const storageKey = 'awt.quill.editor.preferences.v1';
    const defaults = { view: '1440', autoSave: false, interval: 60, theme: 'system', saveMethod: 'draft' };
    class QuillEditorSettings {
        #editor; #preferences; #timer = null; #canSave; #abort = new AbortController();
        constructor(editor, bootstrap) {
            this.#editor = editor;
            this.#canSave = !!bootstrap.saveUrl;
            let stored;
            try { stored = JSON.parse(localStorage.getItem(storageKey)); } catch { /* Storage is optional. */ }
            this.#preferences = {
                saveMethod: ['draft', 'publish'].includes(stored?.saveMethod) ? stored.saveMethod : defaults.saveMethod,
                view: ['1440', '768', '390'].includes(stored?.view) ? stored.view : defaults.view,
                autoSave: typeof stored?.autoSave === 'boolean' ? stored.autoSave : defaults.autoSave,
                interval: Number.isInteger(stored?.interval) && stored.interval >= 10 && stored.interval <= 3600 ? stored.interval : defaults.interval,
                theme: ['system', 'light', 'dark'].includes(stored?.theme) ? stored.theme : defaults.theme,
            };
            const get = id => document.getElementById(id);
            const button = get('editor-settings-open'), dialog = get('editor-settings-dialog');
            const view = get('editor-settings-view'), autoSave = get('editor-settings-autosave');
            const interval = get('editor-settings-interval'), theme = get('editor-settings-theme');
            const saveMethod = get('editor-settings-save-method');
            saveMethod.value = this.#preferences.saveMethod;
            editor.ui.save.setDefaultMode(this.#preferences.saveMethod);
            const options = { signal: this.#abort.signal };
            view.value = this.#preferences.view; autoSave.checked = this.#preferences.autoSave;
            interval.value = this.#preferences.interval; theme.value = this.#preferences.theme;
            const showInterval = () => { get('editor-settings-interval-row').hidden = !autoSave.checked; interval.disabled = !autoSave.checked; };
            showInterval();
            button.addEventListener('click', () => { dialog.showModal(); button.setAttribute('aria-expanded', 'true'); }, options);
            dialog.querySelectorAll('[data-close-settings]').forEach(close => close.addEventListener('click', () => dialog.close(), options));
            dialog.addEventListener('close', () => { button.setAttribute('aria-expanded', 'false'); button.focus(); }, options);
            saveMethod.addEventListener('change', () => { this.#preferences.saveMethod = saveMethod.value; editor.ui.save.setDefaultMode(saveMethod.value); this.#persist(); }, options);
            view.addEventListener('change', () => { this.#preferences.view = view.value; this.#applyView(); this.#persist(); }, options);
            theme.addEventListener('change', () => { this.#preferences.theme = theme.value; this.#applyTheme(); this.#persist(); }, options);
            autoSave.addEventListener('change', () => {
                this.#preferences.autoSave = autoSave.checked; showInterval(); this.#schedule(); this.#persist();
            }, options);
            interval.addEventListener('change', () => {
                if (!interval.checkValidity()) { interval.reportValidity(); return; }
                this.#preferences.interval = Number(interval.value); this.#schedule(); this.#persist();
            }, options);
            editor.on('canvasrendered', () => this.#applyTheme(), options);
            window.addEventListener('pagehide', () => clearInterval(this.#timer), options);
            window.addEventListener('pageshow', () => this.#schedule(), options);
            this.#applyTheme(); this.#applyView(); this.#schedule();
        }
        get preferences() { return { ...this.#preferences }; }
        #persist() {
            const note = document.getElementById('editor-settings-storage-note');
            try {
                localStorage.setItem(storageKey, JSON.stringify(this.#preferences));
                note.textContent = 'Preferences are saved in this browser and apply to all Quill documents.';
            } catch {
                note.textContent = 'Preferences apply for this session. Browser storage is unavailable.';
            }
        }
        #applyView() {
            const viewport = document.getElementById('editor-viewport');
            viewport.value = this.#preferences.view; viewport.dispatchEvent(new Event('change'));
        }
        #applyTheme() {
            document.documentElement.dataset.editorTheme = this.#preferences.theme;
            const root = document.getElementById('editor-canvas').contentDocument?.documentElement;
            if (root) root.dataset.editorTheme = this.#preferences.theme;
        }
        #schedule() {
            clearInterval(this.#timer); this.#timer = null;
            if (this.#preferences.autoSave && this.#canSave) {
                this.#timer = setInterval(() => this.#autoSave(), this.#preferences.interval * 1000);
            }
        }
        async #autoSave() {
            // An open action/component dialog owns an uncommitted draft and revision.
            if (!this.#editor.state.dirty || this.#editor.ui.save.saving || document.hidden
                || document.querySelector('dialog[open]') || this.#editor.canvas.picking) return;
            try { await this.#editor.ui.save.save('draft', { automatic: true }); }
            catch { /* The save collaborator retains local changes and displays its error. */ }
        }
        destroy() { clearInterval(this.#timer); this.#abort.abort(); }
    }
    window.QuillEditorSettings = QuillEditorSettings;
})();
