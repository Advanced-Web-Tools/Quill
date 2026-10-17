(() => {
    'use strict';
    class QuillEditorHistory {
        #pendingInput = null;
        #group = null;
        #watch(document) {
            for (const name of ['input', 'change']) document.addEventListener(name, event => {
                const target = event.target;
                if (!target.matches?.('input:not([type=checkbox]):not([type=radio]), textarea, [contenteditable]')) return;
                this.#pendingInput = target;
                queueMicrotask(() => { if (this.#pendingInput === target) this.#pendingInput = null; });
            }, true);
            document.addEventListener('focusout', () => { this.#group = null; }, true);
        }
        constructor(state) {
            this.state = state; this.undoStack = []; this.redoStack = []; this.restoring = false;
            this.previous = this.capture();
            const undo = document.getElementById('editor-undo');
            const redo = document.getElementById('editor-redo');
            const update = () => { undo.disabled = !this.undoStack.length; redo.disabled = !this.redoStack.length; };
            this.#watch(document);
            state.addEventListener('saved', () => { this.#group = null; });
            state.addEventListener('change', event => {
                const target = this.#pendingInput, time = performance.now();
                const source = event.detail?.source, path = event.detail?.path;
                const editable = target && ['properties', 'canvas', 'page-settings'].includes(source);
                const group = this.#group;
                const merge = editable && group?.target === target && group.source === source && group.path === path && time - group.time < 800;
                if (!this.restoring) {
                    if (!merge) { this.undoStack.push(this.previous); if (this.undoStack.length > 100) this.undoStack.shift(); }
                    this.redoStack = [];
                }
                this.#group = !this.restoring && editable ? { target, source, path, time } : null;
                this.previous = this.capture(); update();
            });
            state.addEventListener('componentrefresh', () => { this.#group = null; this.previous = this.capture(); });
            state.addEventListener('selectionchange', () => { this.#group = null; this.previous.selectedPath = state.selectedPath; });
            undo.addEventListener('click', () => this.undo()); redo.addEventListener('click', () => this.redo());
            const keyboard = event => {
                if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'z') return;
                if (event.target.closest('input, textarea, select, [contenteditable]')) return;
                event.preventDefault(); event.shiftKey ? this.redo() : this.undo();
            };
            document.addEventListener('keydown', keyboard);
            document.getElementById('editor-canvas').addEventListener('load', () => {
                const document = window.document.getElementById('editor-canvas').contentDocument;
                document.addEventListener('keydown', keyboard); this.#watch(document);
            });
            update();
        }
        capture() { return { document: this.state.getDocument(), selectedPath: this.state.selectedPath }; }
        restore(from, to) {
            this.state.assertAvailable(); this.#group = null;
            if (!from.length) return;
            to.push(this.capture()); this.restoring = true;
            try { const snapshot = from.pop(); this.state.restoreDocument(snapshot.document, snapshot.selectedPath); }
            finally { this.restoring = false; }
        }
        undo() { this.restore(this.undoStack, this.redoStack); }
        redo() { this.restore(this.redoStack, this.undoStack); }
    }
    window.QuillEditorHistory = QuillEditorHistory;
})();
