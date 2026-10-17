(() => {
    'use strict';
    class QuillEditorModules {
        #modules = new Map();
        validate(path) {
            if (typeof path !== 'string' || !path.trim()) throw new Error('An editor module requires a URL.');
            const url = new URL(path, document.baseURI);
            if (!['http:', 'https:'].includes(url.protocol) || !/\.m?js$/i.test(url.pathname) || url.hash) throw new Error(`Invalid editor module URL: ${path}`);
            return url.href;
        }
        load(path) {
            const url = new URL(this.validate(path));
            if (!this.#modules.has(url.href)) {
                this.#modules.set(url.href, import(url.href).then(module => {
                    if (typeof module.default !== 'function') throw new Error(`Editor module must export a default function: ${path}`);
                    return module.default;
                }).catch(error => { this.#modules.delete(url.href); throw error; }));
            }
            return this.#modules.get(url.href);
        }
        async matches(path, block, editor) {
            if (!path) return true;
            const condition = await this.load(path);
            const result = await condition(structuredClone(block), editor);
            if (typeof result !== 'boolean') throw new Error(`Editor condition must return true or false: ${path}`);
            return result;
        }
    }
    window.QuillEditorModules = QuillEditorModules;
})();
