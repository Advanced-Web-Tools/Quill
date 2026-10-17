(() => {
    'use strict';
    class QuillEditorInserter {
        #editor;
        #picker;
        #frame;
        #document;
        #overlay;
        #locked = null;
        #hover = null;
        #point = null;
        #animation = null;
        #dragging = false;

        constructor(editor, picker) {
            this.#editor = editor; this.#picker = picker;
            this.#frame = document.getElementById('editor-canvas');
            editor.on('canvasrendered', () => this.#refresh());
            editor.on('viewportchange', () => this.#refresh());
            editor.on('insertiontargetchange', event => {
                this.#locked = event.detail;
                if (!this.#locked) { this.#point = null; this.#hover = null; }
                this.#refresh();
            });
        }

        #mount() {
            const doc = this.#frame.contentDocument;
            if (!doc?.body) return false;
            if (doc !== this.#document) {
                this.#document = doc;
                doc.addEventListener('pointermove', event => {
                    if (event.target.closest('[data-quill-resizer]') && !this.#locked) { this.#point = null; this.#schedule(); return; }
                    if (event.target.closest('[data-quill-inserter], [data-quill-toolbar]') || this.#locked) return;
                    this.#point = event.buttons || this.#dragging || event.target.closest('[contenteditable]') ? null : { x: event.clientX, y: event.clientY };
                    this.#schedule();
                });
                doc.addEventListener('pointerleave', () => {
                    if (!this.#locked) { this.#point = null; this.#schedule(); }
                });
                doc.addEventListener('dragstart', () => { this.#dragging = true; this.#point = null; this.#schedule(); });
                doc.addEventListener('dragend', () => { this.#dragging = false; });
                doc.addEventListener('drop', () => { this.#dragging = false; }, true);
                doc.addEventListener('scroll', () => { this.#point = null; this.#schedule(); }, true);
                doc.addEventListener('load', () => this.#schedule(), true);
                doc.addEventListener('keydown', event => {
                    if (event.key === 'Escape' && this.#locked) { event.preventDefault(); this.#picker.close(); }
                });
            }
            if (!doc.querySelector('[data-quill-inserter-style]')) {
                const style = doc.createElement('style'); style.dataset.quillInserterStyle = '';
                style.textContent = '[data-quill-inserter]{position:fixed;z-index:2147483646;pointer-events:none;display:flex;align-items:center;justify-content:center}'
                    + '[data-quill-inserter][hidden]{display:none!important}'
                    + '[data-quill-inserter]::before{content:"";position:absolute;background:var(--editor-accent);border-radius:2px}'
                    + '[data-quill-inserter][data-orientation=horizontal]::before{width:100%;height:var(--inserter-stroke,3px)}'
                    + '[data-quill-inserter][data-orientation=vertical]::before{height:100%;width:var(--inserter-stroke,3px)}'
                    + '[data-quill-inserter] button{position:relative;pointer-events:auto;display:grid;place-items:center;box-sizing:border-box;width:var(--inserter-size,26px);height:var(--inserter-size,26px);padding:0;border:2px solid var(--editor-accent);border-radius:50%;background:var(--editor-bg);color:var(--editor-accent);cursor:pointer;font:12px system-ui;box-shadow:0 1px 5px #0005}'
                    + '[data-quill-inserter] button:hover,[data-quill-inserter] button:focus-visible{background:var(--editor-accent);color:var(--editor-on-accent);outline:2px solid var(--editor-bg);outline-offset:2px}'
                    + '[data-quill-inserter][data-locked] button{background:var(--editor-accent);color:var(--editor-on-accent)}';
                doc.head.append(style);
            }
            if (!this.#overlay?.isConnected) {
                const overlay = doc.createElement('div'); overlay.dataset.quillInserter = ''; overlay.hidden = true;
                const button = doc.createElement('button'); button.type = 'button';
                button.addEventListener('click', event => {
                    event.preventDefault(); event.stopPropagation();
                    if (this.#locked || !this.#hover) return;
                    const { sectionIndex, parentPath, index } = this.#hover;
                    this.#picker.open({ sectionIndex, parentPath, index });
                });
                overlay.append(button); doc.body.append(overlay); this.#overlay = overlay;
            }
            return true;
        }

        #schedule() {
            if (this.#animation !== null) return;
            this.#animation = requestAnimationFrame(() => { this.#animation = null; this.#refresh(); });
        }

        #candidates() {
            const result = [];
            const visit = (blocks, sectionIndex, parentPath) => {
                const prefix = parentPath === null ? String(sectionIndex) : parentPath;
                const elements = blocks.map((block, index) => this.#editor.canvas.getElement(`${prefix}.${index}`));
                for (let index = 1; index < elements.length; index++) {
                    const before = elements[index - 1], after = elements[index];
                    if (!before || !after || before.parentElement !== after.parentElement) continue;
                    const a = this.#editor.canvas.getBounds(`${prefix}.${index - 1}`), b = this.#editor.canvas.getBounds(`${prefix}.${index}`);
                    if (!a.width || !a.height || !b.width || !b.height) continue;
                    const overlapY = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
                    const overlapX = Math.min(a.right, b.right) - Math.max(a.left, b.left);
                    let x, y, length, orientation;
                    if (overlapY > 0 && (a.right <= b.left + 1 || b.right <= a.left + 1)) {
                        orientation = 'vertical';
                        x = a.left < b.left ? (a.right + b.left) / 2 : (b.right + a.left) / 2;
                        y = Math.max(a.top, b.top); length = overlapY;
                    } else if (a.bottom <= b.top + 1 || b.bottom <= a.top + 1) {
                        orientation = 'horizontal';
                        y = a.top < b.top ? (a.bottom + b.top) / 2 : (b.bottom + a.top) / 2;
                        // At a wrapped row boundary the line spans the parent rather than crossing columns diagonally.
                        const bounds = before.parentElement.getBoundingClientRect();
                        x = overlapX > 0 ? Math.max(a.left, b.left) : bounds.left;
                        length = overlapX > 0 ? overlapX : bounds.width;
                    } else continue;
                    result.push({ sectionIndex, parentPath, index, x, y, length, orientation });
                }
                blocks.forEach((block, index) => { if (!block._quill?.component) visit(block.children, sectionIndex, `${prefix}.${index}`); });
            };
            this.#editor.getDocument().sections.forEach((section, index) => visit(section.blocks, index, null));
            return result;
        }

        #boundary(target) {
            const blocks = target.parentPath === null ? this.#editor.getDocument().sections[target.sectionIndex]?.blocks : this.#editor.getBlock(target.parentPath).children;
            if (!blocks?.length || (target.index !== 0 && target.index !== blocks.length)) return null;
            const prefix = target.parentPath === null ? target.sectionIndex : target.parentPath;
            const before = target.index === 0, index = before ? 0 : blocks.length - 1;
            const element = this.#editor.canvas.getElement(`${prefix}.${index}`); if (!element) return null;
            const bounds = this.#editor.canvas.getBounds(`${prefix}.${index}`);
            if (!bounds.width || !bounds.height) return null;
            const computed = element.ownerDocument.defaultView.getComputedStyle(element.parentElement);
            let vertical = computed.display.includes('flex') && computed.flexDirection.startsWith('row');
            let reversed = computed.flexDirection.endsWith('reverse') || computed.direction === 'rtl';
            const neighbor = this.#editor.canvas.getElement(`${prefix}.${before ? 1 : index - 1}`);
            if (neighbor) {
                const other = this.#editor.canvas.getBounds(`${prefix}.${before ? 1 : index - 1}`);
                vertical = Math.min(bounds.bottom, other.bottom) > Math.max(bounds.top, other.top);
                reversed = vertical ? (before ? other.left < bounds.left : other.left > bounds.left) : (before ? other.top < bounds.top : other.top > bounds.top);
            }
            return { ...target, orientation: vertical ? 'vertical' : 'horizontal',
                x: vertical ? (before !== reversed ? bounds.left : bounds.right) : bounds.left,
                y: vertical ? bounds.top : (before !== reversed ? bounds.top : bounds.bottom),
                length: vertical ? bounds.height : bounds.width };
        }

        #refresh() {
            if (!this.#mount()) return;
            const scale = this.#editor.ui.viewport?.getViewport().scale || 1;
            const size = 26 / scale;
            let target = null;
            if (this.#locked) {
                target = this.#candidates().find(candidate => candidate.sectionIndex === this.#locked.sectionIndex
                    && candidate.parentPath === this.#locked.parentPath && candidate.index === this.#locked.index) || this.#boundary(this.#locked);
            } else if (this.#point && !this.#dragging) {
                let closest = 16 / scale;
                for (const candidate of this.#candidates()) {
                    const { x, y, length, orientation } = candidate;
                    const dx = this.#point.x - x, dy = this.#point.y - y;
                    const distance = orientation === 'vertical'
                        ? Math.hypot(dx, Math.max(0, -dy, dy - length))
                        : Math.hypot(dy, Math.max(0, -dx, dx - length));
                    if (distance < closest) { closest = distance; target = candidate; }
                }
            }
            this.#hover = target;
            this.#overlay.hidden = !target;
            if (!target) return;
            const vertical = target.orientation === 'vertical';
            const style = this.#overlay.style;
            style.setProperty('--inserter-size', `${size}px`);
            style.setProperty('--inserter-stroke', `${3 / scale}px`);
            style.left = `${target.x - (vertical ? size / 2 : 0)}px`;
            style.top = `${target.y - (vertical ? 0 : size / 2)}px`;
            style.width = `${vertical ? size : target.length}px`;
            style.height = `${vertical ? target.length : size}px`;
            this.#overlay.dataset.orientation = target.orientation;
            this.#overlay.toggleAttribute('data-locked', !!this.#locked);
            const button = this.#overlay.firstElementChild;
            button.setAttribute('aria-label', this.#locked ? 'Insertion position locked' : 'Add block here');
            button.setAttribute('aria-pressed', String(!!this.#locked));
            button.title = this.#locked ? 'Choose a block from Add block' : 'Add block here';
            button.innerHTML = `<i class="fa-solid fa-${this.#locked ? 'lock' : 'plus'}" aria-hidden="true"></i>`;
        }
    }
    window.QuillEditorInserter = QuillEditorInserter;
})();
