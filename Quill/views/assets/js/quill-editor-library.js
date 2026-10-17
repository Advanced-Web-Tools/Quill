(() => {
    'use strict';
    window.QuillEditorIcon = {
        set(element, name, text = '') {
            const icon = document.createElement('i'); icon.className = `fa-solid fa-${name}`; icon.setAttribute('aria-hidden', 'true');
            element.replaceChildren(icon, ...(text ? [document.createTextNode(' ' + text)] : []));
            return element;
        },
    };
    window.QuillEditorColorPicker = {
        wrap(input) {
            if (input.parentElement?.classList.contains('editor-color-field')) return input.parentElement;
            const field = document.createElement('span'); field.className = 'editor-color-field';
            const indicator = document.createElement('i'); indicator.className = 'fa-solid fa-droplet-slash editor-color-empty-indicator'; indicator.setAttribute('aria-hidden', 'true');
            field.append(input, indicator);
            input.addEventListener('input', () => queueMicrotask(() => this.update(input)));
            this.update(input);
            return field;
        },
        update(input) {
            const field = input.parentElement;
            if (!field?.classList.contains('editor-color-field')) return;
            const gradient = input.style.backgroundImage && input.style.backgroundImage !== 'none' ? input.style.backgroundImage : '';
            const paint = document.createElement('span').style;
            paint.color = input.style.backgroundColor || input.value;
            const color = paint.color;
            const empty = !gradient && (!color || color === 'transparent' || /^(?:rgba|hsla)\(.*,\s*0(?:\.0+)?\s*\)$/.test(color) || /\/\s*0(?:\.0+)?%?\s*\)$/.test(color));
            field.dataset.emptyPaint = String(empty);
            field.querySelector('.editor-color-empty-indicator').hidden = !empty;
            const label = input.getAttribute('aria-label') || 'Color';
            input.title = `${label}: ${empty ? 'No color / transparent' : gradient || input.value || color}`;
            if (empty) input.setAttribute('aria-description', 'No color or transparent. Choose a color.'); else input.removeAttribute('aria-description');
        },
        setup() {
            window.Coloris?.({
                el: '[data-coloris]', wrap: false, themeMode: 'dark', theme: 'pill', alpha: true,
                formatToggle: true, format: 'hex', clearButton: true, closeButton: true,
                clearLabel: '<i class="fa-solid fa-eraser" aria-hidden="true"></i> Clear',
                closeLabel: '<i class="fa-solid fa-check" aria-hidden="true"></i> Close',
            });
        },
    };
    window.QuillEditorColorPicker.setup();
    document.addEventListener('focusin', event => {
        if (!event.target.matches?.('[data-coloris]') || !window.Coloris) return;
        const parent = event.target.closest('dialog[open]') || document.body;
        const picker = document.getElementById('clr-picker');
        if (picker && picker.parentElement !== parent) { window.Coloris.close(); window.Coloris({ parent }); }
    }, true);
    document.addEventListener('close', event => {
        const picker = document.getElementById('clr-picker');
        if (event.target.tagName === 'DIALOG' && !event.target.open && picker && event.target.contains(picker)) {
            window.Coloris?.close(); window.Coloris?.({ parent: document.body });
        }
    }, true);
    // Saving and background library refreshes must classify session errors consistently.
    class QuillEditorResponse {
        static async read(response, fallback = 'Unable to access this resource.') {
            const loginRedirect = response.redirected && /\/dashboard\/login\/?$/.test(new URL(response.url, location.href).pathname);
            if (response.status === 401 || loginRedirect) throw new Error('Your login session expired. Sign in again to save. Local changes have been retained.');
            let result;
            try { result = await response.json(); }
            catch { throw new Error(fallback); }
            if (!result || typeof result !== 'object') throw new Error(fallback);
            if (!response.ok) throw new Error(result.message || result.data?.message || fallback);
            return result;
        }
    }
    window.QuillEditorResponse = QuillEditorResponse;
    class QuillLibraryClient {
        static async request(url, fields = null) {
            const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
            if (fields) {
                options.method = 'POST'; options.body = new FormData(document.getElementById('editor-csrf'));
                for (const [key,value] of Object.entries(fields)) options.body.set(key, String(value));
            }
            const response = await fetch(url, options);
            const result = await QuillEditorResponse.read(response, 'The server did not return a valid library response. Try again.');
            return result.data;
        }
    }
    class QuillLibraryDialog {
        static ask(title, message, choices, className = '') {
            return new Promise(resolve => {
                const opener = document.activeElement, dialog = document.createElement('dialog'); dialog.className = 'editor-settings-dialog editor-library-confirm ' + className;
                const header = document.createElement('header'); header.className = 'editor-action-dialog-header';
                const heading = document.createElement('h2'); heading.textContent = title; heading.id = 'library-confirm-' + crypto.randomUUID(); header.append(heading); dialog.setAttribute('aria-labelledby', heading.id);
                const description = document.createElement('p'); description.className = 'studio-confirm-description'; description.textContent = message; description.id = heading.id + '-description'; dialog.setAttribute('aria-describedby', description.id);
                const footer = document.createElement('footer'); footer.className = 'editor-action-dialog-footer';
                const finish = choice => { dialog.close(); dialog.remove(); if (opener?.isConnected) opener.focus(); resolve(choice); };
                for (const [value,label,cls] of choices) {
                    const button = document.createElement('button'); button.type = 'button'; button.textContent = label; button.className = cls || ''; button.dataset.libraryChoice = value;
                    if (className.includes('editor-animation-confirm')) button.dataset.animationChoice = value;
                    button.addEventListener('click', () => finish(value)); footer.append(button);
                }
                dialog.addEventListener('cancel', event => { event.preventDefault(); finish(null); });
                dialog.append(header,description,footer); document.body.append(dialog); dialog.showModal(); footer.querySelector('button').focus();
            });
        }
    }
    window.QuillLibraryClient = QuillLibraryClient;
    window.QuillLibraryDialog = QuillLibraryDialog;
})();
