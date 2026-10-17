(() => {
    'use strict';
    class QuillEditorRegistry extends EventTarget {
        #definitions = new Map();
        #versions = new Map();
        #validate;
        constructor(definitions = [], validate = () => {}) {
            super();
            this.#validate = validate;
            definitions.forEach(definition => this.add(definition));
        }
        add(definition, replace = false) {
            if (!definition || !/^[A-Za-z][A-Za-z0-9_.:-]*$/.test(definition.key)) throw new Error('Invalid editor registry key.');
            if (!replace && this.#definitions.has(definition.key)) throw new Error(`Duplicate editor definition: ${definition.key}`);
            this.#validate(definition);
            this.#definitions.set(definition.key, structuredClone(definition));
            this.#versions.set(definition.key, (this.#versions.get(definition.key) || 0) + 1);
            this.dispatchEvent(new CustomEvent('change', { detail: { key: definition.key } }));
        }
        remove(key) {
            if (this.#definitions.delete(key)) this.dispatchEvent(new CustomEvent('change', { detail: { key } }));
        }
        get(key) { return this.#definitions.has(key) ? structuredClone(this.#definitions.get(key)) : null; }
        all() { return [...this.#definitions.values()].map(value => structuredClone(value)); }
        version(key) { return this.#versions.get(key) || 0; }
    }
    window.QuillEditorRegistry = QuillEditorRegistry;
})();
