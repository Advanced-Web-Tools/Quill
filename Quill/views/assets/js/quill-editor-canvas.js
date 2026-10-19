(() => {
    'use strict';

    const blockedTags = new Set(['script', 'style', 'iframe', 'object', 'embed', 'base', 'meta', 'link', 'html', 'head', 'body']);
    const styleStates = ['hover', 'focus', 'focus-visible', 'active', 'disabled', 'checked'];
    const urlAttributes = new Set(['href', 'src', 'action', 'formaction', 'poster', 'xlink:href', 'background']);
    const containerTags = new Set(['div', 'section', 'article', 'aside', 'main', 'header', 'footer', 'nav', 'form']);

    function safeUrl(value, attribute, tag) {
        const text = String(value).replace(/[\u0000-\u0020\u007f]/g, '');
        if (!/^[a-z][a-z\d+.-]*:/i.test(text)) return true;
        if (/^https?:/i.test(text)) return true;
        if (attribute === 'href' && /^(mailto|tel):/i.test(text)) return true;
        return tag === 'img' && attribute === 'src' && /^data:image\/(png|jpeg|gif|webp|avif);base64,/i.test(text);
    }

    class QuillEditorCanvas {
        #frame;
        #onSelect;
        #onTextChange;
        #nodes = new Map();
        #paths = new WeakMap();
        #ready;
        #renderHook = null;
        #cleanups = new Map();
        #baseRules = new Map();
        #baseStyles = null;
        #renderContext = null;
        #styleState = null;
        setStyleState(state) {
            this.#styleState = state;
            const body = this.#frame.contentDocument?.body;
            if (body) {
                if (state) body.dataset.quillStyleState = state; else delete body.dataset.quillStyleState;
                body.ownerDocument.dispatchEvent(new Event('quillresize'));
            }
        }
        #breakpoints = { tablet: 1024, mobile: 767 };
        #onMove = null;
        #dragHandle = null;
        #mainSection = null;
        #pickHandler = null;
        #selectionToolbar = null;
        #resizeHandler = null;
        #resizeOverlay = null;
        #resizeObserver = null;
        #cancelResize = null;
        #components = new Map();
        #componentObserver = null;
        #dragging = false;
        get interacting() { return this.#dragging || this.picking || !!this.#cancelResize; }


        setResizeHandler(handler) { this.#resizeHandler = handler; }

        setPickHandler(handler) {
            this.#cancelResize?.();
            this.#pickHandler = handler;
            if (this.#resizeOverlay) this.#resizeOverlay.hidden = !!handler;
            const toolbar = this.#frame.contentDocument?.querySelector('[data-quill-toolbar]');
            if (toolbar) toolbar.hidden = !!handler;
        }
        get picking() { return !!this.#pickHandler; }
        setSelectionToolbar(handler) { this.#selectionToolbar = handler; }

        setMoveHandler(handler) { this.#onMove = handler; }

        setDragging(active) {
            this.#dragging = active;
            const target = this.#frame.ownerDocument.getElementById('editor-main-drop');
            if (target) target.hidden = !active || this.#mainSection === null;
            if (!active) {
                this.#frame.ownerDocument.querySelector('.editor-workspace')?.removeAttribute('data-main-drop');
                this.#frame.contentDocument?.querySelectorAll('[data-quill-drop]').forEach(element => element.removeAttribute('data-quill-drop'));
                this.#frame.contentDocument?.body.removeAttribute('data-quill-main-drop');
            }
        }

        moveToMain(source) {
            this.setDragging(false);
            if (source && this.#mainSection !== null) this.#onMove?.(source, this.#mainSection, 'inside');
        }

        setRenderHook(hook) { this.#renderHook = hook; }
        getElement(path) { return this.#nodes.get(path) || null; }
        // Rich-text formatting can insert inline siblings before untouched structural children.
        remapBlockPaths(remappings) {
            remappings = remappings.filter(({ from, to }) => from !== to);
            if (!remappings.length) return;
            const remap = path => {
                const match = remappings.find(({ from }) => path === from || path.startsWith(from + '.'));
                return match ? match.to + path.slice(match.from.length) : path;
            };
            const nodes = new Map();
            for (const [path, element] of this.#nodes) {
                const next = remap(path); nodes.set(next, element); this.#paths.set(element, next);
                if (element.hasAttribute('data-quill-editor-node')) element.dataset.quillEditorNode = next;
            }
            this.#nodes = nodes;
            const components = new Map();
            for (const [path, outline] of this.#components) {
                const next = remap(path); components.set(next, outline); this.#paths.set(outline, next);
            }
            this.#components = components;
            for (const element of this.#frame.contentDocument.querySelectorAll('[data-quill-component] *')) {
                const path = this.#paths.get(element);
                if (path) this.#paths.set(element, remap(path));
                if (element.hasAttribute('data-quill-editor-node')) element.dataset.quillEditorNode = remap(element.dataset.quillEditorNode);
            }
            this.#baseRules = new Map([...this.#baseRules].map(([path, rules]) => [remap(path), rules.map(rule => rule.replace(/(\[data-quill-editor-node=")([0-9.]+)("\])/g, (_, start, value, end) => start + remap(value) + end))]));
            if (this.#baseStyles) this.#baseStyles.textContent = [...this.#baseRules.values()].flat().join('\n');
            this.#cleanups = new Map([...this.#cleanups].map(([path, cleanup]) => [remap(path), cleanup]));
        }

        getBounds(path) {
            const element = this.getElement(path);
            if (!element) return null;
            if (!this.#components.has(path)) return element.getBoundingClientRect();
            const rects = [];
            const visit = node => {
                const rect = node.getBoundingClientRect();
                if (rect.width || rect.height) rects.push(rect); else [...node.children].forEach(visit);
            };
            [...element.children].forEach(visit);
            if (!rects.length) return element.getBoundingClientRect();
            const left = Math.min(...rects.map(rect => rect.left)), top = Math.min(...rects.map(rect => rect.top));
            const right = Math.max(...rects.map(rect => rect.right)), bottom = Math.max(...rects.map(rect => rect.bottom));
            return { left, top, right, bottom, x: left, y: top, width: right - left, height: bottom - top };
        }
        #positionComponents() {
            for (const [path, outline] of this.#components) {
                const bounds = this.getBounds(path); if (!bounds) continue;
                outline.style.left = `${bounds.left}px`; outline.style.top = `${bounds.top}px`;
                outline.style.width = `${bounds.width}px`; outline.style.height = `${bounds.height}px`;
                outline.hidden = !bounds.width || !bounds.height;
                outline.toggleAttribute('data-selected', !!this.getElement(path)?.hasAttribute('data-quill-editor-selected'));
            }
        }

        constructor(frame, onSelect, onTextChange) {
            this.#frame = frame;
            this.#onSelect = onSelect;
            this.#onTextChange = onTextChange;
            this.#ready = new Promise(resolve => frame.addEventListener('load', () => {
                const document = frame.contentDocument;
                document.addEventListener('click', event => {
                    if (event.target.closest('[data-quill-inserter], [data-quill-toolbar], [data-quill-resizer]')) return;
                    if (event.target.closest('[data-quill-editor-text]')) return;
                    event.preventDefault();
                    event.stopPropagation();
                    let element = event.target;
                    while (element && !this.#paths.has(element)) element = element.parentElement;
                    if (this.#pickHandler) {
                        if (element && !this.#components.has(this.#paths.get(element)) && element !== this.#dragHandle) this.#pickHandler(this.#paths.get(element));
                        return;
                    }
                    this.#onSelect(element ? this.#paths.get(element) : null);
                }, true);
                const nodeFor = target => {
                    let element = target;
                    while (element && !this.#paths.has(element)) element = element.parentElement;
                    return element;
                };
                const clearDrop = () => {
                    document.querySelectorAll('[data-quill-drop]').forEach(element => element.removeAttribute('data-quill-drop'));
                    document.body.removeAttribute('data-quill-main-drop');
                };
                document.addEventListener('dragstart', event => {
                    if (event.target.closest('[contenteditable]') || (event.target.closest('[data-quill-toolbar]') && !event.target.closest('[data-drag-path]'))) { event.preventDefault(); return; }
                    const element = nodeFor(event.target); if (!element) return;
                    event.stopPropagation();
                    const path = this.#paths.get(element);
                    event.dataTransfer.setData('application/x-quill-block', path);
                    event.dataTransfer.effectAllowed = 'move';
                    this.setDragging(true);
                    if (element !== this.#dragHandle) this.#onSelect(path);
                });
                document.addEventListener('dragover', event => {
                    if (!event.dataTransfer.types.includes('application/x-quill-block')) return;
                    const element = nodeFor(event.target);
                    event.preventDefault(); clearDrop();
                    this.setDragging(true);
                    if (!element) {
                        if (this.#mainSection !== null) {
                            document.body.setAttribute('data-quill-main-drop', '');
                            event.dataTransfer.dropEffect = 'move';
                        }
                        return;
                    }
                    const bounds = this.getBounds(this.#paths.get(element));
                    const ratio = (event.clientY - bounds.top) / Math.max(bounds.height, 1);
                    const leaf = this.#components.has(this.#paths.get(element)) || ['AREA','BR','HR','IMG','INPUT','SOURCE','WBR','EMBED'].includes(element.tagName);
                    const source = this.#paths.get(document.querySelector('[data-quill-editor-selected]'));
                    const target = this.#paths.get(element);
                    if (source && (source === target || target.startsWith(`${source}.`))) {
                        element.dataset.quillDrop = 'invalid'; event.dataTransfer.dropEffect = 'none'; return;
                    }
                    element.dataset.quillDrop = ratio < .25 ? 'before' : ratio > .75 ? 'after' : leaf ? (ratio < .5 ? 'before' : 'after') : 'inside';
                    event.dataTransfer.dropEffect = 'move';
                });
                document.addEventListener('dragleave', event => { if (!event.relatedTarget) clearDrop(); });
                document.addEventListener('dragend', () => this.setDragging(false));
                document.addEventListener('scroll', () => { this.#positionHandle(); this.#positionResizer(); this.#positionComponents(); }, true);
                frame.contentWindow.addEventListener('resize', () => { this.#positionResizer(); this.#positionComponents(); });
                document.addEventListener('drop', event => {
                    const element = nodeFor(event.target);
                    const source = event.dataTransfer.getData('application/x-quill-block');
                    if (!source) return;
                    event.preventDefault(); event.stopPropagation();
                    const position = element?.dataset.quillDrop || 'inside'; clearDrop(); this.setDragging(false);
                    if (position === 'invalid') return;
                    if (!element) { this.moveToMain(source); return; }
                    this.#onMove?.(source, this.#paths.get(element), position);
                });
                document.addEventListener('submit' , event => event.preventDefault(), true);
                document.addEventListener('dblclick', event => {
                    if (event.target.closest('[data-quill-inserter], [data-quill-toolbar], [data-quill-resizer]')) return;
                    let element = event.target;
                    while (element && !this.#paths.has(element)) element = element.parentElement;
                    if (!element) return;
                    event.preventDefault();
                    this.editText(this.#paths.get(element));
                });
                resolve();
            }, { once: true }));
            // Scripts and navigation stay disabled; same-origin access lets the host draw and select DOM nodes.
            frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src http: https: data:; '
                + 'media-src http: https:; style-src \'unsafe-inline\' http: https:; font-src http: https: data:; form-action \'none\'; base-uri \'none\'">'
                + '</head><body></body></html>';
        }

        editText(path) {
            if (this.#components.has(path)) return;
            const element = this.#nodes.get(path);
            if (!element || blockedTags.has(element.tagName.toLowerCase())
                || ['AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR'].includes(element.tagName)) return;
            if (element.querySelector(':scope > [data-quill-editor-text]')) return;
            this.#onSelect(path);
            if (this.#resizeOverlay) this.#resizeOverlay.hidden = true;
            const document = element.ownerDocument;
            const text = document.createElement('span');
            text.dataset.quillEditorText = '';
            text.style.display = 'inline';
            text.style.whiteSpace = 'pre-wrap';
            element.draggable = false;
            text.contentEditable = 'plaintext-only';
            text.setAttribute('role', 'textbox');
            text.setAttribute('aria-label', `Edit ${element.tagName.toLowerCase()} text`);
            if (element.firstChild?.nodeType === Node.TEXT_NODE) {
                text.textContent = element.firstChild.textContent;
                element.firstChild.replaceWith(text);
            } else element.prepend(text);
            text.addEventListener('input', () => this.#onTextChange(path, text.innerText));
            text.addEventListener('paste', event => {
                event.preventDefault();
                document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
            });
            text.addEventListener('keydown', event => {
                if (event.key === 'Escape') { event.preventDefault(); text.blur(); }
            });
            text.addEventListener('blur', () => { element.draggable = true; text.replaceWith(document.createTextNode(text.innerText)); this.#positionResizer(); }, { once: true });
            text.focus();
            const selection = document.getSelection();
            selection.selectAllChildren(text);
            selection.collapseToEnd();
        }

        async render(documentState, css, assets, selectedPath) {
            await this.#ready;
            this.#cancelResize?.();
            this.#componentObserver?.disconnect(); this.#components.clear();
            const document = this.#frame.contentDocument;
            const mainIndex = documentState.sections.findIndex(section => section.name === 'main');
            this.#mainSection = documentState.sections.length ? String(mainIndex < 0 ? 0 : mainIndex) : null;
            const mainDrop = this.#frame.ownerDocument.getElementById('editor-main-drop');
            if (mainDrop && this.#mainSection !== null) mainDrop.textContent = `Drop into ${documentState.sections[Number(this.#mainSection)].name}`;
            this.#cleanups.forEach(cleanup => cleanup()); this.#cleanups.clear();
            const scrollTop = document.scrollingElement.scrollTop;
            document.head.querySelectorAll('style, link').forEach(element => element.remove());
            for (const asset of assets.styles || []) {
                if (typeof asset !== 'string' || !safeUrl(asset, 'href', 'link')) continue;
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = asset;
                document.head.append(link);
            }
            // Reuse only editor tokens; document styles and native color-scheme stay independent.
            const designTokens = this.#frame.ownerDocument.getElementById('editor-design-tokens');
            if (designTokens) document.head.append(designTokens.cloneNode(true));
            document.documentElement.dataset.editorTheme = this.#frame.ownerDocument.documentElement.dataset.editorTheme || 'system';
            const scopedStyles = document.createElement('style');
            scopedStyles.textContent = css;
            document.head.append(scopedStyles);
            // Base declarations come from current state; keep existing responsive rules and their order.
            const sheet = scopedStyles.sheet;
            const clearGeneratedRules = rules => {
                for (let index = rules.cssRules.length - 1; index >= 0; index--) {
                    const rule = rules.cssRules[index];
                    if (rule.type === 1 && (rule.selectorText === 'body' || /^\.quill-scope-[a-f0-9]{64}$/.test(rule.selectorText))) rules.deleteRule(index);
                    else if (rule.cssRules && rule.deleteRule) clearGeneratedRules(rule);
                }
            };
            clearGeneratedRules(sheet);
            const editorStyles = document.createElement('style');
            editorStyles.textContent = 'body{margin:0;min-height:100vh;box-sizing:border-box;font-family:system-ui,sans-serif;color:#24283a;overflow-wrap:anywhere}'
                + 'body[data-quill-main-drop]{outline:2px dashed var(--editor-accent);outline-offset:-3px}'
                + '[data-quill-editor-node]{min-height:12px;cursor:default}'
                + '[data-quill-editor-empty]:empty{min-height:72px;min-width:44px;outline:1px dashed #8d9b96;outline-offset:-1px}'
                + '[data-quill-editor-empty]:empty::after{content:"+";display:grid;place-items:center;min-height:72px;width:100%;color:#6e887c;font:28px/1 system-ui;pointer-events:none}'
                + '[data-quill-editor-node]:hover{outline:1px dashed var(--editor-selection);outline-offset:2px}'
                + '[data-quill-component]{display:contents!important}'
                + '[data-quill-component-outline]{position:fixed;z-index:2147483644;box-sizing:border-box;border:1px dashed var(--editor-component);cursor:grab;background:transparent}'
                + '[data-quill-component-outline][data-selected]{border:2px solid var(--editor-component)}'
                + '[data-quill-component-outline]:hover{border:2px solid var(--editor-component)}'
                + '[data-quill-component] > [data-component-empty]{display:block;min-height:64px;padding:20px;color:var(--editor-component);font:13px system-ui}'
                + '[data-quill-editor-selected]{outline:2px solid var(--editor-selection)!important;outline-offset:3px!important}'
                + '[data-quill-drop=before]{box-shadow:0 -3px var(--editor-accent)!important}[data-quill-drop=after]{box-shadow:0 3px var(--editor-accent)!important}[data-quill-drop=inside]{outline:2px dashed var(--editor-accent)!important;background-color:var(--editor-accent-soft)!important}'
                + '[data-quill-drop=invalid]{outline:2px dashed var(--editor-error)!important;cursor:not-allowed!important}'
                + '[data-quill-resizer]{position:fixed;inset:0;pointer-events:none;z-index:2147483646}'
                + '[data-quill-resizer][hidden],[data-quill-resize][hidden]{display:none!important}'
                + '[data-quill-resize]{all:initial;position:fixed;box-sizing:border-box;pointer-events:auto;touch-action:none;transform:translate(-50%,-50%);display:grid;place-items:center}'
                + '[data-quill-resize]::after{content:"";display:block;width:45%;height:45%;box-sizing:border-box;border:1px solid var(--editor-accent);border-radius:2px;background:var(--editor-bg)}'
                + '[data-quill-resize]:hover::after,[data-quill-resize]:focus-visible::after{background:var(--editor-accent);outline:2px solid var(--editor-bg)}'
                + '[data-quill-resize-size]{position:fixed;transform:translate(-50%,6px);padding:4px 7px;border-radius:4px;background:var(--editor-bg);color:var(--editor-ink);font:11px/1.3 system-ui;white-space:nowrap}'
                + '@media(prefers-reduced-motion:reduce){[data-quill-editor-node]{transition:none!important;}}'
                + '[data-quill-editor-node][hidden]{display:none!important;}'
                + '.quill-preview-placeholder{padding:12px;border:1px dashed #ccc;color:#777;font:12px system-ui}';
            document.head.append(editorStyles);
            const icons = document.createElement('link'); icons.rel = 'stylesheet';
            icons.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css'; document.head.append(icons);
            this.#nodes.clear();
            this.#paths = new WeakMap();
            this.#baseRules.clear();
            this.#breakpoints = documentState.settings?.breakpoints || { tablet: 1024, mobile: 767 };
            const fragment = document.createDocumentFragment();
            documentState.sections.forEach((section, sectionIndex) => {
                section.blocks.forEach((block, index) => fragment.append(this.#createBlock(block, `${sectionIndex}.${index}`, document)));
            });
            const baseStyles = document.createElement('style');
            baseStyles.textContent = [...this.#baseRules.values()].flat().join('\n');
            this.#baseStyles = baseStyles;
            document.head.append(baseStyles);
            document.body.style.cssText = '';
            for (const [property, value] of Object.entries(documentState.settings?.styles || {})) document.body.style.setProperty(property, value);
            document.body.replaceChildren(fragment);
            this.setStyleState(this.#styleState);
            this.#componentObserver = new ResizeObserver(() => { this.#positionComponents(); document.dispatchEvent(new Event('quillresize')); });
            for (const [path] of this.#components) {
                const outline = document.createElement('div'); outline.dataset.quillComponentOutline = path; outline.draggable = true;
                this.#paths.set(outline, path); document.body.append(outline); this.#components.set(path, outline);
                const observe = node => { this.#componentObserver.observe(node); [...node.children].forEach(observe); };
                observe(this.getElement(path));
            }
            document.scrollingElement.scrollTop = scrollTop;
            this.select(selectedPath);
            this.#renderContext = JSON.stringify([css, assets, documentState.settings || {}]);
        }

        async updateBlock(documentState, css, assets, selectedPath, path) {
            await this.#ready;
            const current = this.getElement(path);
            if (!current?.isConnected || !this.#baseStyles?.isConnected || this.#cancelResize
                || this.#renderContext !== JSON.stringify([css, assets, documentState.settings || {}])) return false;
            const indices = path.split('.').map(Number);
            let blocks = documentState.sections[indices.shift()]?.blocks;
            let block, breakpoints = this.#breakpoints;
            for (const index of indices) {
                block = blocks?.[index]; if (!block || block._quill?.component) return false;
                breakpoints = block._quill?.editor?.styleBreakpoints || breakpoints;
                blocks = block.children;
            }
            const hasComponent = node => !!node._quill?.component || node.children.some(hasComponent);
            if (!block || hasComponent(block) || current.querySelector('[data-quill-component]')) return false;
            const within = key => key === path || key.startsWith(path + '.');
            for (const [key, cleanup] of this.#cleanups) if (within(key)) { cleanup(); this.#cleanups.delete(key); }
            for (const key of this.#nodes.keys()) if (within(key)) this.#nodes.delete(key);
            for (const key of this.#baseRules.keys()) if (within(key)) this.#baseRules.delete(key);
            const document = current.ownerDocument;
            const { scrollTop, scrollLeft } = document.scrollingElement;
            current.replaceWith(this.#createBlock(block, path, document, null, breakpoints));
            this.#baseStyles.textContent = [...this.#baseRules.values()].flat().join('\n');
            document.scrollingElement.scrollTop = scrollTop; document.scrollingElement.scrollLeft = scrollLeft;
            this.select(selectedPath); this.#positionComponents();
            document.dispatchEvent(new Event('quillresize'));
            return true;
        }

        #createBlock(block, path, document, lockedRoot = null, breakpoints = this.#breakpoints) {
            const rules = []; this.#baseRules.set(path, rules);
            const shared = block._quill?.component;
            const componentRoot = lockedRoot || (shared ? path : null);
            if (shared?.breakpoints) breakpoints = shared.breakpoints;
            if (block._quill?.editor?.styleBreakpoints) breakpoints = block._quill.editor.styleBreakpoints;
            const tag = block.descriptor.name.toLowerCase();
            const blocked = blockedTags.has(tag);
            const element = document.createElement(blocked ? 'div' : tag);
            if (blocked) {
                element.className = 'quill-preview-placeholder';
                element.textContent = `<${tag}>`;
            } else {
                for (const [name, value] of Object.entries(block.attributes)) {
                    const attribute = name.toLowerCase();
                    if (attribute.startsWith('on') || ['srcdoc', 'srcset', 'autofocus', 'is', 'data-quill-editor-node', 'data-quill-editor-selected'].includes(attribute)
                        || value === false || value === null) continue;
                    if (urlAttributes.has(attribute) && !safeUrl(value, attribute, tag)) continue;
                    element.setAttribute(name, value === true ? '' : String(value));
                }
                for (const group of ['base', 'desktop', 'tablet', 'mobile', ...styleStates, ...['desktop', 'tablet', 'mobile'].flatMap(viewport => styleStates.map(state => `${viewport}:${state}`))]) {
                    const declarations = document.createElement('div').style;
                    for (const [property, value] of Object.entries(block._quill?.styles?.[group] || {})) declarations.cssText += `${property}:${value};`;
                    if (!declarations.cssText) continue;
                    const selector = `[data-quill-editor-node="${path}"]`;
                    const [viewport, state] = group.includes(':') ? group.split(':') : styleStates.includes(group) ? ['base', group] : [group, null];
                    let rule = state
                        ? `${selector}:${state},body[data-quill-style-state="${state}"] ${selector}[data-quill-editor-selected]{${declarations.cssText}}`
                        : `${selector}{${declarations.cssText}}`;
                    if (viewport !== 'base') {
                        const query = viewport === 'desktop' ? `(min-width: ${breakpoints.tablet + 1}px)` : `(max-width: ${breakpoints[viewport]}px)`;
                        rule = `@media ${query}{${rule}}`;
                    }
                    rules.push(rule);
                }
                element.textContent = block.content;
                block.children.forEach((child, index) => element.append(this.#createBlock(child, `${path}.${index}`, document, componentRoot, breakpoints)));
            }
            if (shared) {
                element.setAttribute('data-quill-component', '');
                if (!element.children.length) {
                    const empty = document.createElement('div'); empty.dataset.componentEmpty = ''; empty.textContent = shared.name; element.append(empty);
                }
                if (!lockedRoot) this.#components.set(path, null);
            }
            element.draggable = !componentRoot;
            element.setAttribute('data-quill-editor-node', path);
            this.#paths.set(element, componentRoot || path);
            if (!lockedRoot) this.#nodes.set(path, element);
            const cleanup = !componentRoot && this.#renderHook?.(element, block, path);
            if (typeof cleanup === 'function') this.#cleanups.set(path, cleanup);
            if (containerTags.has(tag) && !element.hasChildNodes()) element.setAttribute('data-quill-editor-empty', '');
            return element;
        }

        #resizeConstraints(element) {
            const style = element.ownerDocument.defaultView.getComputedStyle(element);
            const axis = name => {
                const min = style.getPropertyValue(`min-${name}`), max = style.getPropertyValue(`max-${name}`);
                const pixels = value => /^\d+(?:\.\d+)?px$/.test(value) ? parseFloat(value) : null;
                const lower = pixels(min), upper = pixels(max);
                return { min: lower, max: upper, locked: (max !== 'none' && min !== 'auto' && min === max)
                    || (lower !== null && upper !== null && lower >= upper) };
            };
            return { width: axis('width'), height: axis('height') };
        }

        #resizeEdges(element) {
            const computed = element.ownerDocument.defaultView.getComputedStyle(element);
            if (!['absolute', 'fixed'].includes(computed.position)) return { horizontal: 'e', vertical: 's' };
            // Unlike getComputedStyle(), Typed OM preserves auto insets instead of resolving them to pixels.
            const typed = element.computedStyleMap?.();
            let horizontal = null, vertical = null;
            if (typed) {
                const inset = property => typed.get(property)?.toString() || 'auto';
                const leftAuto = inset('left') === 'auto', rightAuto = inset('right') === 'auto';
                if (leftAuto !== rightAuto) horizontal = leftAuto ? 'w' : 'e';
                vertical = inset('bottom') !== 'auto' && inset('top') === 'auto' ? 'n' : 's';
                if (horizontal) return { horizontal, vertical };
            }
            // Measure ambiguous anchors (including RTL overconstraints), or engines exposing only used offsets.
            const original = element.getAttribute('style'), bounds = element.getBoundingClientRect();
            const restore = () => { if (original === null) element.removeAttribute('style'); else element.setAttribute('style', original); };
            const anchoredEnd = (axis, start, end) => {
                const size = parseFloat(computed.getPropertyValue(axis));
                for (const delta of [2, -2]) {
                    element.style.setProperty('transition', 'none', 'important');
                    element.style.setProperty(axis, `${Math.max(0, size + delta)}px`, 'important');
                    const changed = element.getBoundingClientRect();
                    restore();
                    if (Math.abs(changed[axis] - bounds[axis]) > .5) return Math.abs(changed[start] - bounds[start]) > .5 && Math.abs(changed[end] - bounds[end]) < .5;
                }
                return false;
            };
            try { return { horizontal: horizontal || (anchoredEnd('width', 'left', 'right') ? 'w' : 'e'), vertical: vertical || (anchoredEnd('height', 'top', 'bottom') ? 'n' : 's') }; }
            finally { restore(); }
        }

        #positionResizer() {
            const overlay = this.#resizeOverlay;
            const selected = this.#nodes.get(overlay?.dataset.quillResizer);
            if (!overlay?.isConnected || !selected) return;
            const view = selected.ownerDocument.defaultView, bounds = selected.getBoundingClientRect();
            const computed = view.getComputedStyle(selected);
            overlay.hidden = this.picking || !!selected.querySelector('[contenteditable]') || ['none', 'contents'].includes(computed.display)
                || !bounds.width || !bounds.height || bounds.bottom < 0 || bounds.top > view.innerHeight || bounds.right < 0 || bounds.left > view.innerWidth;
            const scale = Number(this.#frame.ownerDocument.getElementById('editor-canvas-stage')?.dataset.scale) || 1;
            const size = 18 / scale;
            const constraints = this.#resizeConstraints(selected);
            overlay.querySelectorAll('[data-quill-resize]').forEach(handle => {
                const direction = handle.dataset.quillResize;
                handle.hidden = handle.disabled = (/[ew]/.test(direction) && constraints.width.locked) || (/[ns]/.test(direction) && constraints.height.locked);
                const x = direction.includes('w') ? bounds.left : direction.includes('e') ? bounds.right : bounds.left + bounds.width / 2;
                const y = direction.includes('n') ? bounds.top : direction.includes('s') ? bounds.bottom : bounds.top + bounds.height / 2;
                // Keep edge handles usable when a block meets the iframe boundary.
                handle.style.left = `${Math.max(size / 2, Math.min(x, view.innerWidth - size / 2))}px`;
                handle.style.top = `${Math.max(size / 2, Math.min(y, view.innerHeight - size / 2))}px`;
                handle.style.width = handle.style.height = `${size}px`;
            });
            const label = overlay.querySelector('[data-quill-resize-size]');
            label.textContent = `${Math.round(bounds.width)} × ${Math.round(bounds.height)}`;
            label.style.left = `${bounds.left + bounds.width / 2}px`; label.style.top = `${bounds.bottom}px`;
            label.style.fontSize = `${11 / scale}px`;
        }

        #createResizer(path, selected) {
            if (!this.#resizeHandler || this.#components.has(path) || blockedTags.has(selected.tagName.toLowerCase())) return;
            const doc = selected.ownerDocument, overlay = doc.createElement('div');
            overlay.dataset.quillResizer = path;
            const edges = this.#resizeEdges(selected);
            const labels = { e: 'right', w: 'left', s: 'bottom', n: 'top' };
            for (const direction of [edges.horizontal, edges.vertical, edges.vertical + edges.horizontal]) {
                const label = [...direction].map(edge => labels[edge]).join(' ');
                const handle = doc.createElement('button'); handle.type = 'button'; handle.dataset.quillResize = direction;
                handle.setAttribute('aria-label', `Resize ${label}`); handle.title = `Resize ${label} · Shift keeps proportions · Escape cancels`;
                handle.style.cursor = `${direction}-resize`;
                handle.addEventListener('pointerdown', event => this.#startResize(event, path, selected, direction));
                handle.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); });
                handle.addEventListener('keydown', event => {
                    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
                    event.preventDefault(); event.stopPropagation();
                    if ((direction.length === 1 && /[ew]/.test(direction) && ['ArrowUp', 'ArrowDown'].includes(event.key)) || (direction.length === 1 && /[ns]/.test(direction) && ['ArrowLeft', 'ArrowRight'].includes(event.key))) return;
                    const session = this.#resizeSession(selected, direction);
                    const delta = event.shiftKey ? 10 : 1;
                    const values = session.values(event.key === 'ArrowRight' ? delta : event.key === 'ArrowLeft' ? -delta : 0,
                        event.key === 'ArrowDown' ? delta : event.key === 'ArrowUp' ? -delta : 0, false);
                    this.#resizeHandler(path, values);
                    doc.defaultView.requestAnimationFrame(() => {
                        if (this.#resizeOverlay?.dataset.quillResizer === path) this.#resizeOverlay.querySelector(`[data-quill-resize=${direction}]`)?.focus({ preventScroll: true });
                    });
                });
                overlay.append(handle);
            }
            const size = doc.createElement('span'); size.dataset.quillResizeSize = ''; size.hidden = true; overlay.append(size);
            doc.body.append(overlay); this.#resizeOverlay = overlay;
            this.#resizeObserver = new ResizeObserver(() => this.#positionResizer());
            this.#resizeObserver.observe(selected);
            // Parent layout changes can move a selected block without resizing it.
            if (selected.parentElement) this.#resizeObserver.observe(selected.parentElement);
            this.#positionResizer();
        }

        #resizeSession(element, direction) {
            const computed = element.ownerDocument.defaultView.getComputedStyle(element);
            const bounds = element.getBoundingClientRect();
            const extra = axis => computed.boxSizing === 'border-box' ? 0 : axis === 'width'
                ? parseFloat(computed.paddingLeft) + parseFloat(computed.paddingRight) + parseFloat(computed.borderLeftWidth) + parseFloat(computed.borderRightWidth)
                : parseFloat(computed.paddingTop) + parseFloat(computed.paddingBottom) + parseFloat(computed.borderTopWidth) + parseFloat(computed.borderBottomWidth);
            const widthExtra = extra('width'), heightExtra = extra('height'), inline = computed.display === 'inline';
            const constraints = this.#resizeConstraints(element);
            const clamp = (value, axis) => Math.max(axis.min ?? 0, Math.min(value, axis.max ?? Infinity));
            return { values(dx, dy, lock) {
                let width = Math.max(1, bounds.width + (direction.includes('w') ? -dx : direction.includes('e') ? dx : 0));
                let height = Math.max(1, bounds.height + (direction.includes('n') ? -dy : direction.includes('s') ? dy : 0));
                const horizontal = /[ew]/.test(direction), vertical = /[ns]/.test(direction);
                lock = lock && !constraints.width.locked && !constraints.height.locked;
                if (lock) {
                    if (horizontal && (!vertical || Math.abs(dx / bounds.width) >= Math.abs(dy / bounds.height))) height = width * bounds.height / bounds.width;
                    else width = height * bounds.width / bounds.height;
                }
                const values = {};
                if ((horizontal || lock) && !constraints.width.locked) values.width = `${Math.round(clamp(width - widthExtra, constraints.width) * 10) / 10}px`;
                if ((vertical || lock) && !constraints.height.locked) values.height = `${Math.round(clamp(height - heightExtra, constraints.height) * 10) / 10}px`;
                if (inline) values.display = 'inline-block';
                return values;
            } };
        }

        #startResize(event, path, element, direction) {
            if (event.button !== 0 || this.picking || event.currentTarget.hidden || event.currentTarget.disabled) return;
            event.preventDefault(); event.stopPropagation(); this.#cancelResize?.();
            const handle = event.currentTarget, doc = element.ownerDocument;
            const original = element.getAttribute('style'), session = this.#resizeSession(element, direction);
            const x = event.clientX, y = event.clientY;
            let values = null;
            const label = this.#resizeOverlay.querySelector('[data-quill-resize-size]'); label.hidden = false;
            const previousCursor = doc.documentElement.style.cursor, previousSelection = doc.documentElement.style.userSelect;
            doc.documentElement.style.cursor = `${direction}-resize`; doc.documentElement.style.userSelect = 'none';
            const move = next => {
                if (next.pointerId !== event.pointerId || (!values && Math.hypot(next.clientX - x, next.clientY - y) < 2)) return;
                values = session.values(next.clientX - x, next.clientY - y, next.shiftKey);
                for (const [property, value] of Object.entries(values)) element.style.setProperty(property, value, 'important');
                this.#positionResizer();
                doc.dispatchEvent(new Event('quillresize'));
            };
            const finish = commit => {
                this.#cancelResize = null;
                // A canceled capture can send its final click to the page underneath.
                const clearClick = () => { doc.defaultView.removeEventListener('click', swallowClick, true); doc.defaultView.removeEventListener('pointerdown', clearClick, true); };
                const swallowClick = next => { next.preventDefault(); next.stopImmediatePropagation(); clearClick(); };
                doc.defaultView.addEventListener('click', swallowClick, { once: true, capture: true });
                doc.defaultView.addEventListener('pointerdown', clearClick, { once: true, capture: true });
                handle.removeEventListener('pointermove', move); handle.removeEventListener('pointerup', up);
                handle.removeEventListener('pointercancel', cancel); handle.removeEventListener('lostpointercapture', cancel);
                doc.removeEventListener('keydown', key, true);
                this.#frame.ownerDocument.removeEventListener('keydown', key, true);
                if (handle.hasPointerCapture(event.pointerId)) handle.releasePointerCapture(event.pointerId);
                if (original === null) element.removeAttribute('style'); else element.setAttribute('style', original);
                doc.documentElement.style.cursor = previousCursor; doc.documentElement.style.userSelect = previousSelection;
                label.hidden = true; this.#positionResizer();
                doc.dispatchEvent(new Event('quillresize'));
                if (commit && values) this.#resizeHandler(path, values);
            };
            const up = next => { if (next.pointerId === event.pointerId) { if (next.clientX !== x || next.clientY !== y) move(next); finish(true); } };
            const cancel = () => finish(false);
            const key = next => { if (next.key === 'Escape') { next.preventDefault(); next.stopPropagation(); cancel(); } };
            this.#cancelResize = cancel;
            handle.addEventListener('pointermove', move); handle.addEventListener('pointerup', up);
            handle.addEventListener('pointercancel', cancel); handle.addEventListener('lostpointercapture', cancel);
            doc.addEventListener('keydown', key, true);
            this.#frame.ownerDocument.addEventListener('keydown', key, true);
            handle.setPointerCapture(event.pointerId);
        }

        #positionHandle() {
            if (this.#selectionToolbar) return;
            const handle = this.#dragHandle;
            if (!handle?.isConnected) return;
            const selected = this.#nodes.get(handle.dataset.dragPath);
            if (!selected) return;
            const bounds = this.getBounds(handle.dataset.dragPath);
            handle.style.left = `${Math.max(0, bounds.left)}px`;
            handle.style.top = `${Math.max(0, bounds.top - 24)}px`;
        }

        select(path) {
            this.#cancelResize?.();
            this.#resizeObserver?.disconnect();
            this.#resizeOverlay?.remove(); this.#resizeOverlay = null;
            this.#dragHandle?.remove(); this.#dragHandle = null;
            for (const element of this.#nodes.values()) element.removeAttribute('data-quill-editor-selected');
            const selected = this.#nodes.get(path);
            selected?.setAttribute('data-quill-editor-selected', '');
            this.#positionComponents();
            if (!selected) { this.#selectionToolbar?.(null, null); return; }
            this.#createResizer(path, selected);
            const handle = selected.ownerDocument.createElement('button');
            handle.type = 'button'; handle.draggable = true; handle.dataset.dragPath = path;
            handle.setAttribute('aria-label', 'Drag selected block'); handle.title = 'Drag selected block';
            handle.innerHTML = '<i class="fa-solid fa-grip-vertical" aria-hidden="true"></i>';
            handle.style.cssText = 'position:fixed;z-index:2147483647;width:24px;height:24px;padding:3px;border:0;border-radius:4px;background:var(--editor-accent);color:var(--editor-on-accent);cursor:grab';
            this.#paths.set(handle, path); selected.ownerDocument.body.append(handle);
            this.#dragHandle = handle;
            if (this.#selectionToolbar) this.#selectionToolbar(path, handle); else this.#positionHandle();
        }
    }

    window.QuillEditorCanvas = QuillEditorCanvas;
})();
