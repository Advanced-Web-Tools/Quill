(() => {
    'use strict';
    class QuillEditorSave {
        #state;
        #bootstrap;
        #showError;
        #saving = false;
        #mode = 'draft';
        constructor(state, bootstrap, showError) {
            this.#state = state;
            this.#bootstrap = bootstrap;
            this.#showError = showError;
            const mode = document.getElementById('editor-save-mode');
            const button = document.getElementById('editor-save');
            button.disabled = !bootstrap.saveUrl;
            mode.disabled = !bootstrap.saveUrl;
            this.setDefaultMode('draft');
            mode.addEventListener('change', () => {
                const action = mode.value;
                mode.value = '';
                if (['draft', 'publish'].includes(action)) this.save(action).catch(() => {});
            });
            button.addEventListener('click', () => this.save(this.#mode).catch(() => {}));
        }
        get saving() { return this.#saving; }
        get mode() { return this.#mode; }
        setDefaultMode(mode) {
            if (!['draft', 'publish'].includes(mode)) throw new Error('Choose draft or publish.');
            this.#mode = mode;
            const icon = document.createElement('i');
            icon.className = mode === 'publish' ? 'fa-solid fa-cloud-arrow-up' : 'fa-solid fa-floppy-disk';
            icon.setAttribute('aria-hidden', 'true');
            document.getElementById('editor-save').replaceChildren(icon, document.createTextNode(mode === 'publish' ? 'Publish' : 'Save draft'));
            document.getElementById('editor-save-mode').value = '';
        }
        async save(mode = 'draft', { automatic = false } = {}) {
            this.#state.assertAvailable();
            if (!['draft', 'publish'].includes(mode)) throw new Error('Choose draft or publish.');
            if (this.#saving) throw new Error('A save is already in progress.');
            if (!this.#bootstrap.saveUrl) throw new Error('Open an existing document before saving.');
            const get = id => document.getElementById(id);
            const state = this.#state;
            this.#saving = true;
            get('editor-save').disabled = true;
            if (!automatic) this.setDefaultMode(mode);
            get('editor-save-mode').disabled = true;
            const revision = state.revision;
            const payload = new FormData(get('editor-csrf'));
            payload.set('document', JSON.stringify(state.getDocument()));
            payload.set('mode', mode);
            payload.set('lock_version', String(this.#bootstrap.lockVersion));
            get('editor-state-status').textContent = 'Saving…';
            try {
                const response = await fetch(this.#bootstrap.saveUrl, { method: 'POST', body: payload, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const result = await window.QuillEditorResponse.read(response, 'The server did not return a save confirmation. Local changes have been retained. Try saving again.');
                const savedVersion = result.data?.lock_version;
                if (!Number.isSafeInteger(savedVersion) || savedVersion < 1) throw new Error('The save response is missing a valid document version. Local changes have been retained.');
                this.#bootstrap.lockVersion = savedVersion;
                state.markSaved(revision);
                if (this.#bootstrap.editorType === 'component') window.dispatchEvent(new CustomEvent('quill.component.saved', { detail: this.#bootstrap.editorId }));
                get('editor-state-status').textContent = state.dirty ? 'New changes · not saved' : result.message;
                this.#showError(null);
                const popup = get('editor-success');
                popup.textContent = result.message || (mode === 'publish' ? 'Published.' : 'Draft saved.');
                popup.hidden = automatic;
                clearTimeout(this.successTimer);
                this.successTimer = setTimeout(() => { popup.hidden = true; }, 3500);
                return result.data;
            } catch (error) {
                this.#showError(error);
                get('editor-state-status').textContent = 'Save failed · changes retained';
                throw error;
            } finally {
                this.#saving = false;
                get('editor-save').disabled = false;
                get('editor-save-mode').disabled = false;
            }
        }
    }
    window.QuillEditorSave = QuillEditorSave;
})();
