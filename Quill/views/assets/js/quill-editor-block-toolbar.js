(() => {
    'use strict';
    class QuillEditorBlockToolbar {
        #editor; #frame; #toolbar; #menu; #more; #path = null; #installed = new WeakSet();
        constructor(editor) {
            this.#editor = editor; this.#frame = document.getElementById('editor-canvas');
            editor.canvas.setSelectionToolbar((path, handle) => this.#render(path, handle));
            editor.on('viewportchange', () => this.#position());
            editor.on('styleclipboardchange', () => {
                const paste = this.#toolbar?.querySelector('[data-toolbar-action="paste-style"]');
                if (paste) paste.disabled = !editor.hasCopiedStyle;
            });
            document.querySelector('.editor-canvas-area').addEventListener('scroll', () => this.#position());
            document.addEventListener('pointerdown', () => { if (!this.#panel) { this.#closeMenu(); this.#finishText(); } });
        }
        #panel = null; #panelAbort = null; #applying = false;
        #closeTextPanel(restore = true) {
            const panel = this.#panel; if (!panel) return;
            this.#panel = null; this.#panelAbort?.abort(); this.#panelAbort = null;
            this.#editor.palette.close(); panel.close(); panel.remove();
            if (restore) { this.#restoreTextRange(); this.#showText(true); }
        }
        #selectionElement() {
            const node = this.#range?.startContainer;
            return node?.nodeType === 1 ? node : node?.parentElement;
        }
        #selectedLink() {
            const link = this.#selectionElement()?.closest('a');
            if (link && this.#text?.parentElement.contains(link) && link.contains(this.#range.endContainer)) return link;
            const links = [...(this.#text?.querySelectorAll('a') || [])].filter(element => element.textContent && this.#range?.intersectsNode(element) && this.#range.toString() === element.textContent);
            return links.length === 1 ? links[0] : null;
        }
        async #openTextPanel(kind) {
            const selection = this.#text?.ownerDocument.getSelection();
            if (selection?.rangeCount && !selection.isCollapsed && this.#text.contains(selection.anchorNode) && this.#text.contains(selection.focusNode)) this.#range = selection.getRangeAt(0).cloneRange();
            if (!this.#hasTextRange()) return;
            const existingData = kind === 'data' ? this.#selectedData() : null;
            if (existingData) this.#range.selectNodeContents(existingData === this.#text.parentElement ? this.#text : existingData);
            this.#closeTextPanel();
            const text = this.#text, range = this.#range.cloneRange();
            const panel = document.createElement('dialog'); panel.className = 'editor-rich-text-dialog'; panel.dataset.richTextPanel = kind;
            const title = document.createElement('h2'); title.textContent = kind === 'data' ? 'Connect selected text' : kind === 'link' ? 'Text link' : 'Text colors';
            panel.setAttribute('aria-label', title.textContent);
            const container = document.createElement('div'); container.id = `editor-rich-${kind}`;
            const message = document.createElement('p'); message.className = 'editor-rich-text-message'; message.role = 'alert';
            const footer = document.createElement('div'); footer.className = 'editor-rich-text-footer';
            const cancel = document.createElement('button'); cancel.type = 'button'; cancel.textContent = 'Cancel';
            const apply = document.createElement('button'); apply.type = 'button'; apply.textContent = kind === 'data' ? 'Apply connection' : kind === 'link' ? 'Apply link' : 'Apply colors'; apply.className = 'button-primary'; apply.dataset.richApply = kind; apply.disabled = true;
            footer.append(cancel, apply); panel.append(title, container, message, footer); document.body.append(panel);
            this.#panel = panel; const abort = new AbortController(); this.#panelAbort = abort;
            const signal = abort.signal;
            cancel.addEventListener('click', () => this.#closeTextPanel(), { signal });
            panel.addEventListener('cancel', event => { event.preventDefault(); this.#closeTextPanel(); }, { signal });
            panel.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); this.#closeTextPanel(); } }, { signal });
            panel.show();
            // Reuse the settings modules against a draft of the selected inline content.
            const base = new URL(JSON.parse(document.getElementById('quill-editor-data').textContent).propertyAssetBase, document.baseURI);
            const link = this.#selectedLink();
            const selected = kind === 'link' || (link && range.toString() === link.textContent) ? link : this.#selectionElement();
            let attributes = kind === 'link' && selected ? Object.fromEntries([...selected.attributes].filter(attribute => !attribute.name.startsWith('data-quill-') && attribute.name !== 'draggable').map(attribute => [attribute.name, attribute.value])) : {};
            const style = document.createElement('span').style;
            const computed = text.ownerDocument.defaultView.getComputedStyle(selected || text.parentElement);
            if (kind === 'colors' && selected !== text && (selected !== text.parentElement || selected?.tagName === 'A')) style.cssText = selected?.getAttribute('style') || '';
            const touched = new Set(); let error = null;
            const adapter = {
                selectedPath: this.#path, routes: this.#editor.routes, palette: this.#editor.palette,
                canvas: { getElement: () => selected || text.parentElement },
                getSelectedBlock: () => ({ attributes: { ...attributes, ...(kind === 'colors' ? { style: style.cssText } : {}) } }),
                getStyleDeclarations: () => { const copy = document.createElement('span').style; copy.cssText = style.cssText; return copy; },
                setStyle: (property, value) => {
                    if (value && !CSS.supports(property, value)) throw new Error('Choose a valid color.');
                    touched.add(property); if (value) style.setProperty(property, value); else style.removeProperty(property);
                },
                setStyleDeclarations: value => { style.cssText = value; touched.add('paint'); },
                setAttribute: (name, value) => { if (value === null) delete attributes[name]; else attributes[name] = value; },
                updateBlock: changes => { if (changes.attributes) attributes = { ...changes.attributes }; },
                reportError: value => { error = value; message.textContent = value?.message || ''; },
            };
            let dataDescriptor, dataControls;
            try {
                if (kind === 'data') {
                    const original = existingData ? this.#inlineOriginal(existingData) : null;
                    dataDescriptor = structuredClone(original?.descriptor || {name:'span',props:{},renderer:''});
                    const hint = document.createElement('p'); hint.className = 'editor-group-description';
                    hint.textContent = 'Connect only this text fragment. Its current text and formatting stay as the fallback. Before and After add text around the database value.';container.append(hint);
                    const dataEditor = {
                        selectedPath: this.#path + ':text', dataSources: this.#editor.dataSources, dataRoutes: this.#editor.dataRoutes,
                        getSelectedBlock: () => ({kind:'element',descriptor:structuredClone(dataDescriptor),attributes:{},content:range.toString(),children:[]}),
                        updateBlock: changes => {dataDescriptor=structuredClone(changes.descriptor);dataControls?.update(dataEditor.getSelectedBlock());},
                        reportError: adapter.reportError,
                    };
                    const mount = await this.#editor.modules.load(new URL('connect-data.js', base).href);
                    if (signal.aborted) return;
                    dataControls = mount({container,editor:dataEditor,signal});dataControls.update(dataEditor.getSelectedBlock());
                } else if (kind === 'link') {
                    const mount = await this.#editor.modules.load(new URL('link.js', base).href);
                    if (signal.aborted) return;
                    const control = mount({ container, editor: adapter, signal });
                    control.update(adapter.getSelectedBlock(), { selectionChanged: true });
                } else {
                    const { colorControl } = await import(new URL('paint-control.js', base).href);
                    if (signal.aborted) return;
                    for (const [property, label] of [['color', 'Text color'], ['background-color', 'Highlight color']]) {
                        const row = document.createElement('div'); row.className = 'editor-property-row editor-property-color';
                        const caption = document.createElement('label'); caption.textContent = label;
                        const paintEditor = { ...adapter, setStyleDeclarations: value => { style.cssText = value; touched.add(property); } };
                        const control = colorControl(property, label, paintEditor, signal);
                        control.input.id = `editor-rich-${property}`; caption.htmlFor = control.input.id;
                        control.set(style.getPropertyValue(property), true, computed);
                        const reset = document.createElement('button'); reset.type = 'button'; reset.className = 'editor-rich-color-reset'; reset.setAttribute('aria-label', `Reset ${label.toLowerCase()}`);
                        reset.innerHTML = '<i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i>';
                        reset.addEventListener('click', () => { touched.add(property); control.reset(); control.set('', false, computed); }, { signal });
                        row.append(caption, control.element, reset); container.append(row);
                        control.input.addEventListener('input', () => control.set(style.getPropertyValue(property), false, computed), { signal });
                    }
                    window.QuillEditorColorPicker.setup();
                }
                if (signal.aborted) return;
                apply.disabled = false;
                apply.addEventListener('click', () => this.#invoke(() => {
                    if (error) throw error;
                    if (kind === 'data' && dataDescriptor.renderer && !dataDescriptor.props.set?.content?.content) throw new Error('Choose the text content field to display.');
                    if (kind === 'link' && !attributes.href) throw new Error('Choose a link destination.');
                    if (this.#text !== text || !text.isConnected) { this.#closeTextPanel(false); return; }
                    this.#range = range.cloneRange();
                    if (kind === 'data') this.#applyTextData(dataDescriptor, existingData);
                    else if (kind === 'link') this.#applyTextLink(attributes);
                    else if (touched.size) this.#applyTextPaint(style.cssText, touched);
                    this.#closeTextPanel();
                }), { signal });
                container.querySelector('select, input')?.focus();
            } catch (error) { if (!signal.aborted) { message.textContent = error.message; this.#editor.reportError(error); } }
        }
        #inlineData = new Map();
        #inlineOriginal(element) {
            if (element === this.#text?.parentElement) return this.#editor.state.getBlock(this.#path);
            return this.#originalInline.get(element) || this.#inlineData.get(element?.dataset.quillInlineId);
        }
        #selectedData() {
            const connected = node => this.#inlineOriginal(node)?.descriptor.renderer.replace(/^\\/, '') === 'Quill\\services\\rendering\\ConnectedDataRenderer';
            let node = this.#selectionElement();
            while (node && node !== this.#text) {
                if (this.#text?.contains(node) && node.contains(this.#range.endContainer) && connected(node)) return node;
                node = node.parentElement;
            }
            const root = this.#text?.parentElement;
            if (root && connected(root)) return root;
            return [...(this.#text?.querySelectorAll('[data-quill-inline-id]') || [])].find(node => connected(node) && this.#range.intersectsNode(node) && node.textContent === this.#range.toString()) || null;
        }
        #applyTextData(descriptor, existing) {
            if (!this.#restoreTextRange() || (!existing && !descriptor.renderer)) return;
            if (existing === this.#text.parentElement) {
                this.#editor.state.updateBlock(this.#path, {descriptor}, 'canvas');return;
            }
            const doc = this.#text.ownerDocument;
            const element = existing || doc.createElement('span');
            if (!existing) {
                try { this.#range.surroundContents(element); }
                catch {
                    const before=doc.createRange(),after=doc.createRange();
                    before.selectNodeContents(this.#text);before.setEnd(this.#range.startContainer,this.#range.startOffset);
                    after.selectNodeContents(this.#text);after.setStart(this.#range.endContainer,this.#range.endOffset);
                    const leading=before.cloneContents(),trailing=after.cloneContents();let fragment=this.#range.cloneContents();
                    let ancestor=this.#range.commonAncestorContainer;if(ancestor.nodeType!==1)ancestor=ancestor.parentElement;
                    while(ancestor&&ancestor!==this.#text){const wrapper=ancestor.cloneNode(false);wrapper.append(fragment);fragment=wrapper;ancestor=ancestor.parentElement;}
                    element.append(fragment);this.#text.replaceChildren(leading,element,trailing);
                }
            }
            const original = this.#inlineOriginal(element) || {};
            const raw = {...original,descriptor:structuredClone(descriptor)};
            element.dataset.quillInlineId ||= 'qi-' + crypto.randomUUID();
            this.#inlineData.set(element.dataset.quillInlineId, raw);this.#originalInline.set(element, raw);
            this.#range = doc.createRange();this.#range.selectNodeContents(element);
            const selection = doc.getSelection();selection.removeAllRanges();selection.addRange(this.#range.cloneRange());
            this.#saveText();this.#updateTextButtons();
        }
        #applyTextLink(attributes) {
            if (!this.#restoreTextRange()) return;
            const doc = this.#text.ownerDocument;
            let link = this.#selectedLink();
            this.#applying = true;
            try {
                if (!link) {
                    doc.execCommand('createLink', false, attributes.href);
                    this.#range = doc.getSelection().getRangeAt(0).cloneRange(); link = this.#selectedLink();
                }
                if (!link) throw new Error('Select the text for this link again.');
                for (const name of ['href', 'target', 'rel', 'download']) {
                    if (Object.hasOwn(attributes, name) && attributes[name] !== false) link.setAttribute(name, attributes[name] === true ? '' : attributes[name]);
                    else link.removeAttribute(name);
                }
                if (link === this.#text.parentElement) {
                    const block = this.#editor.state.getBlock(this.#path);
                    const next = { ...block.attributes };
                    for (const name of ['href', 'target', 'rel', 'download']) { if (link.hasAttribute(name)) next[name] = link.getAttribute(name); else delete next[name]; }
                    this.#editor.state.updateBlock(this.#path, { attributes: next }, 'canvas');
                }
            } finally { this.#applying = false; }
            this.#saveText(); this.#updateTextButtons();
        }
        #applyTextPaint(css, touched) {
            if (!this.#restoreTextRange()) return;
            const doc = this.#text.ownerDocument, link = this.#selectedLink();
            if (link && this.#range.toString() === link.textContent) {
                if (css) link.setAttribute('style', css); else link.removeAttribute('style');
                this.#clearChildPaint(link, touched);
                if (link === this.#text.parentElement) {
                    const attributes = { ...this.#editor.state.getBlock(this.#path).attributes };
                    if (css) attributes.style = css; else delete attributes.style;
                    this.#editor.state.updateBlock(this.#path, { attributes }, 'canvas');
                }
                this.#saveText(); this.#updateTextButtons(); return;
            }
            const span = doc.createElement('span'); span.style.cssText = css;
            const before = doc.createRange(), after = doc.createRange();
            before.selectNodeContents(this.#text); before.setEnd(this.#range.startContainer, this.#range.startOffset);
            after.selectNodeContents(this.#text); after.setStart(this.#range.endContainer, this.#range.endOffset);
            const leading = before.cloneContents(), trailing = after.cloneContents(), fragment = doc.createDocumentFragment();
            let selected = this.#range.cloneContents();
            let ancestor = this.#range.commonAncestorContainer;
            if (ancestor.nodeType !== 1) ancestor = ancestor.parentElement;
            while (ancestor && ancestor !== this.#text) {
                const wrapper = ancestor.cloneNode(false); wrapper.append(selected); selected = wrapper; ancestor = ancestor.parentElement;
            }
            fragment.append(selected);
            const originals = new Map([...this.#text.querySelectorAll('[data-quill-editor-node]')].map(element => [element.dataset.quillEditorNode, this.#originalInline.get(element)]));
            for (const part of [leading, fragment, trailing]) part.querySelectorAll('[data-quill-editor-node]').forEach(element => {
                const original = originals.get(element.dataset.quillEditorNode); if (original) this.#originalInline.set(element, original);
            });
            if (touched.has('color') && !span.style.color && !span.style.getPropertyValue('--quill-text-gradient')) span.style.color = doc.defaultView.getComputedStyle(this.#text.parentElement).color;
            this.#clearChildPaint(fragment, touched);
            // A link's own color takes precedence over the selection wrapper.
            fragment.querySelectorAll('a').forEach(anchor => {
                for (const property of span.style) anchor.style.setProperty(property, span.style.getPropertyValue(property), span.style.getPropertyPriority(property));
            });
            span.append(fragment); this.#text.replaceChildren(leading, span, trailing);
            this.#range.selectNodeContents(span); doc.getSelection().removeAllRanges(); doc.getSelection().addRange(this.#range.cloneRange());
            this.#saveText(); this.#updateTextButtons();
        }
        #clearChildPaint(container, touched) {
            const properties = [...touched];
            if (touched.has('color')) properties.push('-webkit-text-fill-color', '--quill-text-gradient', 'background-clip', '-webkit-background-clip');
            if (touched.has('background-color')) properties.push('--quill-background-paint');
            container.querySelectorAll('[style]').forEach(element => {
                const textPaint = (element.style.backgroundClip || '').includes('text');
                if ((touched.has('color') && textPaint) || (touched.has('background-color') && !textPaint)) element.style.removeProperty('background-image');
                properties.forEach(property => element.style.removeProperty(property));
            });
        }
        #pointerActive = false; #originalInline = new WeakMap(); #text = null; #range = null; #textMode = false; #richControls = null; #returnToText = null; #lineBreak = null;
        #textRoot(node) {
            let element = node?.nodeType === 1 ? node : node?.parentElement;
            const nearest = element?.closest('[data-quill-editor-node]');
            const inline = 'span,strong,b,em,i,u,s,strike,sub,sup,a,code,br';
            while (element && !element.matches('p,h1,h2,h3,h4,h5,h6,li,blockquote,figcaption,button,label,td,th')) element = element.parentElement;
            if (!element && nearest?.matches(inline + ',div,section,article,aside,main,header,footer,nav')) element = nearest;
            if (!element?.hasAttribute('data-quill-editor-node') || element.closest('[data-quill-component]')) return null;
            if ([...element.querySelectorAll('*')].some(child => !child.matches(inline + ',[data-quill-editor-text]'))) return null;
            return element;
        }
        #installText(doc) {
            // Capture before the canvas click handler, which deliberately leaves editable text alone.
            const linkFor = event => event.target.closest?.('a[href]')?.closest('[data-quill-editor-node], [data-quill-editor-text]') ? event.target.closest('a[href]') : null;
            doc.defaultView.addEventListener('click', event => {
                const link = linkFor(event); if (!link) return;
                event.preventDefault();
                if (this.#editor.canvas.picking || link.closest('[data-quill-component]')) return;
                const root = this.#textRoot(link); if (!root || !link.textContent.trim()) return;
                const selection = doc.getSelection();
                if (this.#text?.contains(link) && selection.rangeCount && !selection.isCollapsed && link.contains(selection.anchorNode) && link.contains(selection.focusNode)) {
                    this.#range = selection.getRangeAt(0).cloneRange(); this.#showText(true); return;
                }
                this.#closeTextPanel(false); this.#startText(root);
                const range = doc.createRange(); range.selectNodeContents(link === root ? this.#text : link);
                selection.removeAllRanges(); selection.addRange(range); this.#range = range.cloneRange();
                this.#showText(true); this.#updateTextButtons();
            }, true);
            doc.defaultView.addEventListener('auxclick', event => { if (linkFor(event)) event.preventDefault(); }, true);
            doc.addEventListener('pointerdown', () => { this.#pointerActive = true; }, true);
            doc.addEventListener('pointerup', () => { this.#pointerActive = false; }, true);
            doc.addEventListener('pointercancel', () => { this.#pointerActive = false; }, true);
            doc.addEventListener('dblclick', event => {
                if (this.#editor.canvas.picking || event.target.closest('[data-quill-toolbar]')) return;
                const root = this.#textRoot(event.target);
                if (!root) return;
                event.preventDefault(); event.stopImmediatePropagation(); this.#startText(root);
            }, true);
            doc.addEventListener('pointerup', event => {
                if (this.#editor.canvas.picking || event.target.closest('[data-quill-toolbar]')) return;
                const selection = doc.getSelection();
                if (!selection.rangeCount || selection.isCollapsed || !selection.toString().trim()) return;
                const root = this.#textRoot(selection.anchorNode);
                if (root && root.contains(selection.focusNode) && !this.#text) this.#startText(root, selection.getRangeAt(0));
            });
            doc.addEventListener('selectionchange', () => {
                if (!this.#text?.isConnected || this.#panel) return;
                const selection = doc.getSelection();
                if (selection.rangeCount && this.#text.contains(selection.anchorNode) && this.#text.contains(selection.focusNode)) {
                    this.#range = selection.getRangeAt(0).cloneRange();
                    if (!selection.isCollapsed && selection.toString().trim()) this.#showText(true);
                    else if (selection.isCollapsed) this.#showText(false);
                } else if (!this.#panel && !this.#toolbar?.contains(doc.activeElement)) {
                    this.#range = null; this.#showText(false);
                }
                this.#updateTextButtons();
            });
        }
        #startText(root, selectedRange = null) {
            if (this.#text?.parentElement === root) return;
            this.#text?.blur();
            const doc = root.ownerDocument, path = root.dataset.quillEditorNode;
            // Keep offsets before moving nodes into the editing host.
            const offset = (node, at) => { const range = doc.createRange(); range.selectNodeContents(root); range.setEnd(node, at); return range.toString().length; };
            const offsets = selectedRange ? [offset(selectedRange.startContainer, selectedRange.startOffset), offset(selectedRange.endContainer, selectedRange.endOffset)] : null;
            this.#editor.select(path);
            this.#originalInline = new WeakMap(); this.#inlineData.clear();
            root.querySelectorAll('[data-quill-editor-node]').forEach(node => {
                const original=this.#editor.state.getBlock(node.dataset.quillEditorNode);
                node.dataset.quillInlineId='qi-'+crypto.randomUUID();this.#inlineData.set(node.dataset.quillInlineId,original);this.#originalInline.set(node,original);
            });
            const text = doc.createElement('span'); text.dataset.quillEditorText = ''; text.contentEditable = 'true';
            text.style.cssText = 'display:inline;white-space:pre-wrap'; text.role = 'textbox'; text.setAttribute('aria-label', `Edit ${root.tagName.toLowerCase()} text`);
            text.setAttribute('aria-multiline', 'true');
            text.append(...root.childNodes); root.append(text); root.draggable = false; this.#text = text;
            this.#syncTextPlaceholder();
            text.addEventListener('input', () => this.#invoke(() => { this.#syncTextPlaceholder(); this.#saveText(); }, false));
            text.addEventListener('paste', event => { event.preventDefault(); doc.execCommand('insertText', false, event.clipboardData.getData('text/plain')); });
            text.addEventListener('drop', event => event.preventDefault());
            text.addEventListener('keydown', event => {
                if (event.key === 'Escape') { event.preventDefault(); text.blur(); }
                if (event.key === 'Enter' && !event.isComposing) { event.preventDefault(); this.#insertLineBreak(); }
            });
            text.addEventListener('beforeinput', event => {
                if (['insertParagraph', 'insertLineBreak'].includes(event.inputType) && !event.isComposing) {
                    event.preventDefault(); this.#insertLineBreak();
                }
            });
            text.addEventListener('blur', event => {
                if (this.#panel || this.#toolbar?.contains(event.relatedTarget)) return;
                this.#finishText();
            });
            text.focus({ preventScroll: true });
            const range = doc.createRange(); range.selectNodeContents(text);
            if (offsets) {
                const walker = doc.createTreeWalker(text, 4); let node, at = 0, start = null, end = null;
                while ((node = walker.nextNode())) {
                    if (!start && at + node.length >= offsets[0]) start = [node, offsets[0] - at];
                    if (!end && at + node.length >= offsets[1]) end = [node, offsets[1] - at];
                    at += node.length;
                }
                if (start && end) { range.setStart(...start); range.setEnd(...end); }
            } else range.collapse(false);
            doc.getSelection().removeAllRanges(); doc.getSelection().addRange(range); this.#range = range.cloneRange();
            this.#showText(!!offsets);
        }
        #finishText() {
            this.#closeTextPanel(false);
            const text = this.#text; if (!text) return;
            this.#text = null; this.#range = null; this.#textMode = false;
            if (text.isConnected) {
                text.querySelectorAll('[data-quill-editor-placeholder]').forEach(node => node.remove());
                text.parentElement.draggable = true; text.replaceWith(...text.childNodes);
                const refresh = () => { if (!this.#text?.isConnected) this.#editor.dispatchEvent(new Event('rendererschange')); };
                // Let the canvas select the clicked block before rebuilding its DOM.
                if (this.#pointerActive) text.ownerDocument.addEventListener('click', refresh, { once: true });
                else queueMicrotask(refresh);
            }
            this.#showText(false);
        }
        #saveText() {
            if (this.#applying) return;
            const text = this.#text; if (!text?.isConnected) return;
            const path = text.parentElement.dataset.quillEditorNode;
            const seen = new Set([this.#editor.state.getBlock(path)._quill?.editor?.urpcUid]);
            const remember = (node, block) => {
                if (!this.#editor.state.sourceBound) return block;
                block._quill = structuredClone(block._quill || {}); block._quill.editor ??= {};
                let uid = block._quill.editor.urpcUid;
                // Browser formatting may split/clone a source span; only one may retain its UID.
                if (!uid || seen.has(uid)) uid = 'quill-added:' + crypto.randomUUID();
                block._quill.editor.urpcUid = uid; seen.add(uid);
                this.#originalInline.set(node, block);
                return block;
            };
            const serialize = element => {
                let content = '', children = [];
                for (const node of element.childNodes) {
                    if (node.nodeType === 3) {
                        if (!children.length) content += node.textContent;
                        else children.push(remember(node, { ...(this.#originalInline.get(node) || {}), descriptor: { name: 'span', props: {}, renderer: '' }, attributes: {}, content: node.textContent, children: [] }));
                    } else if (node.nodeType === 1) {
                        if (node.hasAttribute('data-quill-editor-placeholder')) continue;
                        const tag = node.tagName.toLowerCase();
                        if (!['span','strong','b','em','i','u','s','strike','sub','sup','a','code','br'].includes(tag)) throw new Error('Unsupported inline formatting.');
                        const original = this.#inlineOriginal(node);
                        const attributes = {};
                        for (const attribute of node.attributes) {
                            if (!attribute.name.startsWith('data-quill-') && attribute.name !== 'draggable') attributes[attribute.name] = attribute.value;
                        }
                        children.push(remember(node, { ...(original || {}), descriptor: original?.descriptor || { name: tag, props: {}, renderer: '' }, attributes, ...serialize(node) }));
                    }
                }
                return { content, children };
            };
            this.#editor.state.updateBlock(path, serialize(text), 'canvas');
        }
        #hasTextRange() { return !!this.#text?.isConnected && !!this.#range && !this.#range.collapsed && !!this.#range.toString().trim(); }
        #showText(active) {
            this.#textMode = active && this.#hasTextRange();
            const toolbar = this.#toolbar; if (!toolbar || !this.#richControls) return;
            for (const child of toolbar.children) child.hidden = child === this.#lineBreak ? !this.#text?.isConnected : this.#textMode ? child !== this.#richControls : child === this.#richControls;
            this.#returnToText.hidden = this.#textMode || !this.#hasTextRange();
            toolbar.setAttribute('aria-label', this.#textMode ? 'Text formatting' : 'Block toolbar');
            toolbar.dataset.mode = this.#textMode ? 'text' : 'block';
            this.#closeMenu(); this.#position();
        }
        #restoreTextRange(allowCaret = false) {
            if (allowCaret ? !this.#text?.isConnected || !this.#range || !this.#text.contains(this.#range.commonAncestorContainer) : !this.#hasTextRange()) return false;
            const selection = this.#text.ownerDocument.getSelection(); this.#text.focus({ preventScroll: true });
            selection.removeAllRanges(); selection.addRange(this.#range.cloneRange()); return true;
        }
        #insertLineBreak() {
            const doc = this.#text?.ownerDocument, selection = doc?.getSelection();
            if (selection?.rangeCount && this.#text.contains(selection.anchorNode) && this.#text.contains(selection.focusNode)) this.#range = selection.getRangeAt(0).cloneRange();
            if (!this.#restoreTextRange(true)) return;
            const tail = selection.getRangeAt(0).cloneRange();
            tail.setStart(tail.endContainer, tail.endOffset); tail.setEnd(this.#text, this.#text.childNodes.length);
            const remaining = tail.cloneContents();
            remaining.querySelectorAll('[data-quill-editor-placeholder]').forEach(node => node.remove());
            const atEnd = !remaining.textContent && !remaining.querySelector('br');
            // A trailing editing-only BR gives the caret a visible empty line. It is never serialized.
            doc.execCommand('insertHTML', false, atEnd ? '<br><br data-quill-editor-placeholder>' : '<br>');
            if (atEnd) {
                const placeholder = this.#text.querySelector('[data-quill-editor-placeholder]');
                if (placeholder) { const range = doc.createRange(); range.setStartBefore(placeholder); range.collapse(true); selection.removeAllRanges(); selection.addRange(range); }
            }
            this.#range = selection.getRangeAt(0).cloneRange();
            this.#saveText(); this.#showText(false); this.#updateTextButtons();
        }
        #syncTextPlaceholder() {
            const text = this.#text; if (!text) return;
            text.querySelectorAll('[data-quill-editor-placeholder]').forEach(node => node.remove());
            let last = text;
            while (last.lastChild) last = last.lastChild;
            if (last.nodeType === 1 && last.tagName === 'BR') {
                const placeholder = text.ownerDocument.createElement('br'); placeholder.dataset.quillEditorPlaceholder = ''; text.append(placeholder);
            }
        }
        #formatText(command, value = null) {
            if (!this.#restoreTextRange()) return;
            const doc = this.#text.ownerDocument;
            doc.execCommand(command, false, value);
            this.#range = doc.getSelection().getRangeAt(0).cloneRange();
            this.#saveText(); this.#showText(true); this.#updateTextButtons();
            this.#text?.focus({ preventScroll: true });
        }
        #updateTextButtons() {
            if (!this.#richControls) return;
            const doc = this.#richControls.ownerDocument;
            this.#richControls.querySelectorAll('[data-text-command]').forEach(button => {
                button.setAttribute('aria-pressed', String(doc.queryCommandState(button.dataset.textCommand)));
            });
        }
        #addTextControls(doc, toolbar) {
            const group = doc.createElement('div'); group.className = 'quill-text-controls'; group.hidden = true;
            group.append(this.#button(doc, 'Back to block tools', 'arrow-left', () => this.#showText(false), 'back-to-block'));
            for (const [command, title, icon] of [['bold','Bold','bold'],['italic','Italic','italic'],['underline','Underline','underline'],['strikeThrough','Strikethrough','strikethrough'],['subscript','Subscript','subscript'],['superscript','Superscript','superscript']]) {
                const button = this.#button(doc, title, icon, () => this.#formatText(command), `text-${command}`);
                button.dataset.textCommand = command; button.setAttribute('aria-pressed', 'false'); group.append(button);
            }
            group.append(this.#button(doc, 'Edit link', 'link', () => this.#openTextPanel('link'), 'text-link'));
            group.append(this.#button(doc, 'Connect text to database', 'database', () => this.#openTextPanel('data'), 'text-connect-data'));
            group.append(this.#button(doc, 'Text and highlight colors', 'palette', () => this.#openTextPanel('colors'), 'text-colors'));
            group.append(this.#button(doc, 'Remove link', 'link-slash', () => this.#formatText('unlink'), 'text-unlink'));
            group.append(this.#button(doc, 'Clear formatting', 'text-slash', () => this.#formatText('removeFormat'), 'text-clear'));
            toolbar.addEventListener('focusout', event => {
                if (!this.#panel && event.relatedTarget && !toolbar.contains(event.relatedTarget) && !this.#text?.contains(event.relatedTarget)) this.#finishText();
            });
            const back = this.#button(doc, 'Back to text editing', 'arrow-right', () => { this.#restoreTextRange(); this.#showText(true); }, 'back-to-text'); back.hidden = true;
            this.#lineBreak = this.#button(doc, 'Insert line break (Enter)', 'arrow-turn-down', () => this.#insertLineBreak(), 'text-line-break');
            this.#richControls = group; this.#returnToText = back; toolbar.append(back, group, this.#lineBreak); this.#showText(this.#textMode);
        }
        async #invoke(action, close = true) {
            try { await action(); this.#editor.reportError(null); if (close) this.#closeMenu(); }
            catch (error) { this.#editor.reportError(error); }
        }
        #button(doc, label, icon, action, command) {
            const button = doc.createElement('button'); button.type = 'button'; button.title = label;
            button.setAttribute('aria-label', label); button.dataset.toolbarAction = command;
            const symbol = doc.createElement('i'); symbol.className = `fa-solid fa-${icon}`; symbol.setAttribute('aria-hidden', 'true'); button.append(symbol);
            button.addEventListener('click', () => this.#invoke(action, command !== 'more')); return button;
        }
        #select(doc, label, value, options, action) {
            const select = doc.createElement('select'); select.title = label; select.setAttribute('aria-label', label);
            for (const [key, title] of Object.entries(options)) select.add(new Option(title, key));
            select.value = Object.hasOwn(options, value) ? value : '';
            select.addEventListener('change', () => this.#invoke(() => action(select.value))); return select;
        }
        #style(doc) {
            if (doc.querySelector('[data-quill-toolbar-style]')) return;
            const style = doc.createElement('style'); style.dataset.quillToolbarStyle = '';
            style.textContent = '[data-quill-toolbar]{color-scheme:var(--editor-color-scheme);position:fixed;z-index:2147483647;display:flex;flex-wrap:wrap;align-items:center;gap:4px;padding:5px;box-sizing:border-box;border:1px solid var(--editor-line);border-radius:var(--editor-radius-md);background:var(--editor-bg);color:var(--editor-ink);box-shadow:var(--editor-shadow-lg);font:12px/1.2 var(--editor-font);transform-origin:top left}'
                + '[data-quill-toolbar][hidden],[data-quill-toolbar] [hidden]{display:none!important}'
                + '[data-quill-toolbar] button{display:inline-flex;align-items:center;justify-content:center;gap:8px;box-sizing:border-box;flex-shrink:0;width:30px;height:30px;margin:0;padding:5px;border:0;border-radius:var(--editor-radius-sm);background:transparent;color:var(--editor-ink);font:12px/1.2 var(--editor-font);cursor:pointer}'
                + '[data-quill-toolbar] button:hover,[data-quill-toolbar] button:focus-visible{background:var(--editor-input);color:var(--editor-accent);outline:1px solid var(--editor-accent);outline-offset:-1px}'
                + '[data-quill-toolbar] .quill-text-controls{display:flex;align-items:center;flex-wrap:wrap;gap:4px}'
                + '[data-quill-toolbar] button[aria-pressed=true]{background:var(--editor-accent-soft);color:var(--editor-accent)}'
                + '[data-quill-toolbar] input{width:150px;height:30px;box-sizing:border-box;border:1px solid var(--editor-line);border-radius:var(--editor-radius-sm);background:var(--editor-input);color:var(--editor-ink);padding:4px 7px;font:12px var(--editor-font)}'
                + '[data-quill-toolbar] button:disabled{opacity:.35;cursor:default}'
                + '[data-quill-toolbar] [data-drag-path]{background:var(--editor-accent);color:var(--editor-on-accent);cursor:grab}'
                + '[data-quill-toolbar][data-component]{border-color:var(--editor-component)}'
                + '[data-quill-toolbar][data-component] [data-drag-path]{background:var(--editor-component);color:var(--editor-bg)}'
                + '[data-quill-toolbar] .quill-component-toolbar-notice{padding:4px;color:var(--editor-component);font:11px/1.4 var(--editor-font);white-space:normal}'
                + '[data-quill-toolbar] .quill-toolbar-label{display:inline-flex;align-items:center;gap:6px;max-width:85px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 5px;font:11px/1.2 var(--editor-font)}'
                + '[data-quill-toolbar] select{box-sizing:border-box;max-width:88px;min-width:0;height:30px;margin:0;padding:3px;border:1px solid var(--editor-line);border-radius:var(--editor-radius-sm);background:var(--editor-input);color:var(--editor-ink);font:11px var(--editor-font)}'
                + '[data-quill-toolbar] .quill-toolbar-menu{position:absolute;z-index:1;width:200px;overflow:auto;box-sizing:border-box;padding:5px;border:1px solid var(--editor-line);border-radius:var(--editor-radius-md);background:var(--editor-bg);box-shadow:var(--editor-shadow-lg)}'
                + '[data-quill-toolbar] .quill-toolbar-menu button{width:100%;height:32px;justify-content:flex-start;text-align:left;padding:7px 10px;white-space:nowrap}'
                + '[data-quill-toolbar] .quill-toolbar-menu button span{min-width:0;overflow:hidden;text-overflow:ellipsis}'
                + '[data-quill-toolbar] .quill-toolbar-menu [data-toolbar-action=delete]{color:var(--editor-error)}';
            doc.head.append(style);
        }
        #render(path, handle) {
            if (path !== this.#path || !this.#text?.isConnected) this.#finishText();
            this.#toolbar?.remove(); this.#toolbar = null; this.#menu = null; this.#richControls = null; this.#path = path;
            if (path === null) return;
            const doc = this.#frame.contentDocument; this.#style(doc);
            if (!this.#installed.has(doc)) {
                this.#installed.add(doc); this.#installText(doc);
                doc.addEventListener('scroll', () => this.#position(), true);
                doc.addEventListener('quillresize', () => this.#position());
                doc.defaultView.addEventListener('resize', () => this.#position());
                doc.addEventListener('pointerdown', event => {
                    if (!event.target.closest('[data-quill-toolbar]')) {
                        this.#closeMenu();
                        if (this.#text && !this.#text.contains(event.target)) this.#finishText();
                    }
                });
                doc.addEventListener('keydown', event => {
                    if (event.key === 'Escape' && this.#menu && !this.#menu.hidden) { event.preventDefault(); this.#closeMenu(); this.#more?.focus(); }
                });
            }
            const block = this.#editor.getBlock(path), element = this.#editor.canvas.getElement(path);
            const shared = block.component;
            const computed = doc.defaultView.getComputedStyle(element);
            const toolbar = doc.createElement('div'); toolbar.dataset.quillToolbar = ''; toolbar.role = 'toolbar'; toolbar.setAttribute('aria-label', 'Block toolbar');
            handle.style.cssText = ''; toolbar.append(handle);
            const label = doc.createElement('span'); label.className = 'quill-toolbar-label';
            const name = block.editor?.label || block.descriptor.name;
            const icon = doc.createElement('i');
            const fallback = { img: 'image', p: 'paragraph', button: 'hand-pointer', a: 'link', section: 'layer-group', div: 'square' };
            const registered = this.#editor.blocks.get(block.definition_key)?.icon;
            icon.className = /^(?:fa-solid|fa-regular|fa-brands) fa-[a-z0-9-]+$/.test(registered || '') ? registered : `fa-solid fa-${fallback[block.descriptor.name] || (/^h[1-6]$/.test(block.descriptor.name) ? 'heading' : 'cube')}`;
            icon.setAttribute('aria-hidden', 'true'); label.append(icon, doc.createTextNode(name)); label.title = name; toolbar.append(label);
            if (shared) {
                toolbar.dataset.component = '';
                label.textContent = shared.name; label.title = shared.name;
                const notice = doc.createElement('span'); notice.className = 'quill-component-toolbar-notice';
                notice.textContent = 'Shared component · Edit in component editor';
                notice.title = 'Content and styling are managed in the component editor.';
                toolbar.append(notice); doc.body.append(toolbar); this.#toolbar = toolbar; this.#position(); return;
            }
            const parts = path.split('.'), index = Number(parts.pop()), parent = parts.join('.');
            const sectionIndex = Number(parts[0]), parentPath = parts.length > 1 ? parent : null;
            const siblings = parentPath === null ? this.#editor.getDocument().sections[sectionIndex].blocks : this.#editor.getBlock(parentPath).children;
            let horizontal = false, reversed = false;
            const neighborIndex = index ? index - 1 : index + 1;
            const neighbor = this.#editor.canvas.getElement(`${parent}.${neighborIndex}`);
            if (neighbor) {
                const a = this.#editor.canvas.getBounds(path), b = this.#editor.canvas.getBounds(`${parent}.${neighborIndex}`);
                horizontal = Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top);
                reversed = horizontal ? (index ? b.left > a.left : b.left < a.left) : (index ? b.top > a.top : b.top < a.top);
            }
            const before = this.#button(doc, 'Move before', horizontal ? (reversed ? 'arrow-right' : 'arrow-left') : (reversed ? 'arrow-down' : 'arrow-up'), () => this.#editor.state.moveSelected('up'), 'move-before'); before.disabled = index === 0;
            const after = this.#button(doc, 'Move after', horizontal ? (reversed ? 'arrow-left' : 'arrow-right') : (reversed ? 'arrow-up' : 'arrow-down'), () => this.#editor.state.moveSelected('down'), 'move-after'); after.disabled = index === siblings.length - 1;
            const selectParent = this.#button(doc, 'Select parent', 'turn-up', () => this.#editor.select(parentPath), 'select-parent'); selectParent.disabled = parentPath === null;
            toolbar.append(before, after, selectParent, this.#button(doc, 'Duplicate block', 'copy', () => this.#editor.duplicateBlock(path), 'duplicate'));
            const tag = block.descriptor.name.toLowerCase();
            if (['div', 'section', 'article', 'main', 'aside', 'header', 'footer', 'nav', 'form'].includes(tag)) {
                const layout = computed.display.includes('grid') ? 'grid' : computed.display.includes('flex') ? (computed.flexDirection.includes('column') ? 'column' : 'row') : 'block';
                toolbar.append(this.#select(doc, 'Layout', layout, { block: 'Block', row: 'Row', column: 'Column', grid: 'Grid' }, value => {
                    const style = this.#editor.getStyleDeclarations(path); style.display = ['row', 'column'].includes(value) ? 'flex' : value;
                    if (['row', 'column'].includes(value)) style.flexDirection = value; else style.removeProperty('flex-direction');
                    this.#editor.setStyleDeclarations(style.cssText, path);
                }));
                if (/flex|grid/.test(computed.display)) toolbar.append(this.#select(doc, 'Align items', computed.alignItems, { '': 'Align', start: 'Start', center: 'Center', end: 'End', stretch: 'Stretch' }, value => this.#editor.setStyle('align-items', value, path)));
            } else if (block.content && !['script', 'style'].includes(tag)) {
                let alignment = computed.textAlign;
                if (alignment === 'start') alignment = computed.direction === 'rtl' ? 'right' : 'left';
                if (alignment === 'end') alignment = computed.direction === 'rtl' ? 'left' : 'right';
                toolbar.append(this.#select(doc, 'Text alignment', alignment, { '': 'Align', left: 'Left', center: 'Center', right: 'Right', justify: 'Justify' }, value => this.#editor.setStyle('text-align', value, path)));
            }
            if (tag === 'img') toolbar.append(this.#button(doc, 'Replace image', 'image', async () => {
                const panel = await this.#editor.ui.properties.showCategory('image'); const field = panel.querySelector('[data-attribute=src]'); field?.focus(); field?.select();
            }, 'replace-image'));
            const more = this.#button(doc, 'More block options', 'ellipsis-vertical', () => {
                const open = this.#menu.hidden; this.#menu.hidden = !open; more.setAttribute('aria-expanded', String(open));
                if (open) { this.#positionMenu(); this.#menu.querySelector('button:not(:disabled)')?.focus(); }
            }, 'more');
            more.setAttribute('aria-haspopup', 'menu'); more.setAttribute('aria-expanded', 'false'); this.#more = more; toolbar.append(more);
            const menu = doc.createElement('div'); menu.id = `quill-block-options-${path.replaceAll('.', '-')}`; more.setAttribute('aria-controls', menu.id); menu.role = 'menu'; menu.className = 'quill-toolbar-menu'; menu.hidden = true;
            const item = (title, icon, callback, command) => {
                const button = this.#button(doc, title, icon, callback, command); button.role = 'menuitem'; button.tabIndex = -1;
                const text = doc.createElement('span'); text.textContent = title; button.append(text); menu.append(button); return button;
            };
            item('Insert before', 'plus', () => this.#editor.ui.picker.open({ sectionIndex, parentPath, index }), 'insert-before');
            item('Insert after', 'plus', () => this.#editor.ui.picker.open({ sectionIndex, parentPath, index: index + 1 }), 'insert-after');
            const outdent = item('Move out of parent', 'arrow-turn-up', () => this.#editor.state.moveSelected('outdent'), 'outdent'); outdent.disabled = parentPath === null;
            item('Copy style', 'paintbrush', () => this.#editor.copyStyle(path), 'copy-style').title = 'Copy block styles for all viewports into Quill';
            const paste = item('Paste style', 'paste', () => this.#editor.pasteStyle(path), 'paste-style'); paste.disabled = !this.#editor.hasCopiedStyle; paste.title = 'Paste styles from the Quill style clipboard';
            item('Convert to component', 'cubes', () => this.#editor.ui.components.open(path), 'convert-component');
            item('Delete block', 'trash-can', () => this.#editor.removeBlock(path), 'delete');
            toolbar.append(menu); this.#menu = menu;
            menu.addEventListener('keydown', event => {
                const items = [...menu.querySelectorAll('button:not(:disabled)')]; const at = items.indexOf(doc.activeElement);
                const index = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : event.key === 'ArrowDown' ? (at + 1) % items.length : event.key === 'ArrowUp' ? (at - 1 + items.length) % items.length : null;
                if (index !== null) { event.preventDefault(); items[index].focus(); }
            });
            toolbar.addEventListener('pointerdown', event => { if (event.target.closest('button:not([data-drag-path])')) event.preventDefault(); });
            toolbar.addEventListener('keydown', event => {
                if (event.target.closest('[role=menu]') || ['SELECT', 'INPUT'].includes(event.target.tagName)) return;
                if (event.key === 'ArrowDown' && event.target === more) { event.preventDefault(); more.click(); return; }
                if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
                const buttons = [...toolbar.querySelectorAll('button:not(:disabled), select, input')].filter(button => !button.closest('[hidden]'));
                const at = buttons.indexOf(doc.activeElement); if (at < 0) return;
                event.preventDefault(); buttons[(at + (event.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length].focus();
            });
            doc.body.append(toolbar); this.#toolbar = toolbar; this.#addTextControls(doc, toolbar); this.#position();
            const dataDefinition = this.#editor.properties.get('Quill:connect-data');
            if (dataDefinition) this.#editor.modules.matches(dataDefinition.condition, block, this.#editor).then(matches => {
                if (!matches || this.#toolbar !== toolbar) return;
                const connect = this.#button(doc, 'Connect data', 'database', async () => {
                    const panel = await this.#editor.ui.properties.showCategory('connect-data');
                    panel.querySelector('[data-data-field=source]')?.focus();
                }, 'connect-data');
                toolbar.insertBefore(connect, more); this.#showText(this.#textMode);
            }).catch(error => this.#editor.reportError(error));
            const definition = this.#editor.properties.get('Quill:clickable');
            if (definition) this.#editor.modules.matches(definition.condition, block, this.#editor).then(matches => {
                if (!matches || this.#toolbar !== toolbar) return;
                const actions = this.#button(doc, 'Manage actions', 'bolt', async () => {
                    const panel = await this.#editor.ui.properties.showCategory('clickable'); const assignments = panel.querySelector('.editor-action-assignment');
                    if (assignments) { assignments.closest('details').open = true; assignments.scrollIntoView({ block: 'nearest' }); assignments.querySelector('select, button')?.focus(); }
                }, 'actions'); toolbar.insertBefore(actions, more); actions.hidden = this.#textMode; this.#position();
            }).catch(error => this.#editor.reportError(error));
        }
        #closeMenu() { if (this.#menu) this.#menu.hidden = true; this.#more?.setAttribute('aria-expanded', 'false'); }
        #visibleBounds() {
            const scale = this.#editor.ui.viewport?.getViewport().scale || 1;
            const frame = this.#frame.getBoundingClientRect(), area = document.querySelector('.editor-canvas-area').getBoundingClientRect();
            const view = this.#frame.contentWindow;
            return { scale, left: Math.max(0, (area.left - frame.left) / scale), top: Math.max(0, (area.top - frame.top) / scale), right: Math.min(view.innerWidth, (area.right - frame.left) / scale), bottom: Math.min(view.innerHeight, (area.bottom - frame.top) / scale) };
        }
        #position() {
            const toolbar = this.#toolbar, selected = this.#editor.canvas.getElement(this.#path);
            if (!toolbar?.isConnected || !selected) return;
            const view = this.#visibleBounds(), bounds = this.#editor.canvas.getBounds(this.#path), gap = 6 / view.scale;
            toolbar.hidden = this.#editor.canvas.picking || bounds.bottom < view.top || bounds.top > view.bottom || bounds.right < view.left || bounds.left > view.right;
            if (toolbar.hidden) return;
            toolbar.style.transform = `scale(${1 / view.scale})`;
            toolbar.style.maxWidth = `${Math.max(40, (view.right - view.left) * view.scale - 12)}px`;
            toolbar.style.left = '0px'; toolbar.style.top = '0px';
            const size = toolbar.getBoundingClientRect();
            const left = Math.max(view.left + gap, Math.min(bounds.left, view.right - size.width - gap));
            let top = bounds.top - size.height - gap;
            if (top < view.top + gap) top = bounds.bottom + gap;
            top = Math.max(view.top + gap, Math.min(top, view.bottom - size.height - gap));
            toolbar.dataset.placement = top >= bounds.bottom ? 'below' : top + size.height <= bounds.top ? 'above' : 'inside';
            toolbar.style.left = `${left}px`; toolbar.style.top = `${top}px`;
            this.#positionMenu();
        }
        #positionMenu() {
            if (!this.#menu || this.#menu.hidden || !this.#toolbar?.isConnected) return;
            const view = this.#visibleBounds(), bounds = this.#toolbar.getBoundingClientRect(), gap = 6 / view.scale;
            this.#menu.style.maxWidth = `${Math.max(40, (view.right - view.left) * view.scale - 12)}px`;
            this.#menu.style.maxHeight = `${Math.max(60, (view.bottom - view.top) * view.scale - this.#toolbar.offsetHeight - 18)}px`;
            const left = Math.max((view.left + gap - bounds.left) * view.scale, Math.min(this.#more.offsetLeft + this.#more.offsetWidth - this.#menu.offsetWidth, (view.right - gap - bounds.left) * view.scale - this.#menu.offsetWidth));
            this.#menu.style.left = `${left}px`; this.#menu.style.top = '100%'; this.#menu.style.bottom = 'auto';
            if (this.#menu.getBoundingClientRect().bottom > view.bottom - gap) { this.#menu.style.top = 'auto'; this.#menu.style.bottom = '100%'; }
        }
    }
    window.QuillEditorBlockToolbar = QuillEditorBlockToolbar;
})();
