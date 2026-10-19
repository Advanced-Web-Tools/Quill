(() => {
    'use strict';
    class QuillEditorMediaLibrary {
        #editor; #providers;
        constructor(editor, providers = []) { this.#editor = editor; this.#providers = structuredClone(providers); }
        providers(type) { return this.#providers.filter(provider => provider.mediaTypes.includes(type)).map(provider => structuredClone(provider)); }
        static item(value, type) {
            const item = typeof value === 'string' ? { url: value } : value;
            if (!item || typeof item.url !== 'string' || !item.url.trim() || (item.type && item.type !== type)) throw new Error(`Choose a valid ${type}.`);
            const url = new URL(item.url, document.baseURI);
            if (!['http:', 'https:'].includes(url.protocol)) throw new Error('Choose an HTTP media URL or a path from your site.');
            if (item.alt !== undefined && typeof item.alt !== 'string') throw new Error('Invalid media alternative text.');
            return { url: item.url.trim(), ...(item.alt !== undefined ? { alt: item.alt } : {}) };
        }
        /** Shared provider host for source dropdowns and background-image tabs. */
        mount({ container, type = 'image', mode = 'dropdown', urlPanel, onSelect, signal, value = '', lazy = false }) {
            const providers = this.providers(type), editor = this.#editor;
            const host = document.createElement('div'); host.className = 'editor-media-library'; container.append(host);
            const navigation = document.createElement(mode === 'tabs' ? 'div' : 'select');
            navigation.className = mode === 'tabs' ? 'editor-media-tabs' : 'editor-media-provider';
            navigation.setAttribute('aria-label', 'Media library provider');
            if (mode === 'tabs') navigation.role = 'tablist';
            const providerPanel = document.createElement('div'); providerPanel.className = 'editor-media-provider-panel';
            const empty = document.createElement('p'); empty.className = 'editor-group-description'; empty.textContent = 'No media library providers are registered.'; empty.hidden = providers.length > 0;
            const error = document.createElement('p'); error.className = 'editor-action-error'; error.role = 'alert'; error.hidden = true;
            host.append(...(providers.length ? [navigation] : []), empty, providerPanel, urlPanel, error);
            let suspended = lazy;
            let selected = providers[0]?.key || 'url', active, instance, currentValue = value, contextPath = editor.selectedPath;
            const tabs = new Map();
            const options = [...providers.map(provider => [provider.key, provider.name]), ['url', 'URL']];
            navigation.hidden = providers.length === 0;
            const cleanup = () => {
                active?.abort(); active = null;
                const previous = instance; instance = null;
                try { previous?.destroy?.(); } catch (failure) { editor.reportError(failure); }
                providerPanel.replaceChildren();
            };
            const activate = async key => {
                cleanup(); selected = key; error.hidden = true;
                urlPanel.hidden = key !== 'url'; providerPanel.hidden = key === 'url';
                if (mode === 'tabs') for (const [id, tab] of tabs) { tab.setAttribute('aria-selected', String(id === key)); tab.tabIndex = id === key ? 0 : -1; }
                else navigation.value = key;
                if (key === 'url' || signal.aborted || suspended) return;
                const provider = providers.find(item => item.key === key);
                if (!provider) return;
                const request = new AbortController(); active = request;
                contextPath = editor.selectedPath;
                const path = contextPath;
                const content = document.createElement('div'); providerPanel.append(content);
                content.setAttribute('aria-busy', 'true');
                try {
                    const mount = await editor.modules.load(provider.module);
                    if (request.signal.aborted) return;
                    const result = await mount({ container: content, editor, type, value: currentValue, signal: request.signal,
                        onSelect: value => {
                            if (request.signal.aborted || editor.selectedPath !== path) return;
                            try {
                                const item = QuillEditorMediaLibrary.item(value, type);
                                onSelect(item); error.hidden = true; editor.reportError(null);
                            } catch (failure) { error.textContent = failure.message; error.hidden = false; editor.reportError(failure); }
                        } });
                    const mounted = typeof result === 'function' ? { destroy: result } : (result || {});
                    if (request.signal.aborted) { mounted.destroy?.(); return; }
                    instance = mounted;
                } catch (failure) {
                    if (!request.signal.aborted) {
                        content.replaceChildren(); error.textContent = `Unable to open ${provider.name}. Choose URL or try again.`; error.hidden = false;
                        const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Try again'; retry.addEventListener('click', () => activate(key)); content.append(retry);
                    }
                } finally { content.removeAttribute('aria-busy'); }
            };
            for (const [key, name] of options) {
                if (mode === 'tabs') {
                    const tab = document.createElement('button'); tab.type = 'button'; tab.role = 'tab'; tab.textContent = name; tab.dataset.mediaProvider = key;
                    tab.addEventListener('click', () => activate(key), { signal }); tabs.set(key, tab); navigation.append(tab);
                } else navigation.add(new Option(name, key));
            }
            if (mode === 'tabs') navigation.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                const keys = [...tabs.keys()], index = keys.indexOf(selected);
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? keys.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + keys.length) % keys.length;
                event.preventDefault(); activate(keys[next]); tabs.get(keys[next]).focus();
            }, { signal });
            else navigation.addEventListener('change', () => activate(navigation.value), { signal });
            signal.addEventListener('abort', () => { cleanup(); host.remove(); }, { once: true });
            activate(selected);
            return { element: host, update(value, selectionChanged = false) {
                currentValue = value;
                if (selectionChanged || contextPath !== editor.selectedPath) { contextPath = editor.selectedPath; activate(selected); }
                else {
                    try {
                        const request = active;
                        Promise.resolve(instance?.update?.({ value, type })).catch(failure => { if (!request?.signal.aborted) editor.reportError(failure); });
                    } catch (failure) { editor.reportError(failure); }
                }
            }, suspend() { suspended = true; cleanup(); }, resume() { suspended = false; activate(selected); } };
        }
    }
    window.QuillEditorMediaLibrary = QuillEditorMediaLibrary;
})();
