/* A frozen rendering snapshot, including ancestor dimensions, inheritance and pseudo content. */
(() => {
    'use strict';
    class QuillAnimationPreview {
        #frame; #host; #source; #nodes = 0;
        element = null;
        constructor(host, source) { this.#host = host; this.#source = source; }
        async mount(signal) {
            const source = this.#source, original = source.ownerDocument;
            const frame = document.createElement('iframe'); this.#frame = frame;
            frame.title = 'Isolated animation preview'; frame.tabIndex = -1; frame.sandbox = 'allow-same-origin';
            frame.style.width = `${original.defaultView.innerWidth}px`; frame.style.height = `${original.defaultView.innerHeight}px`;
            const loaded = new Promise((resolve, reject) => { frame.addEventListener('load', resolve, { once: true }); signal?.addEventListener('abort', () => reject(new DOMException('Preview cancelled', 'AbortError')), { once: true }); });
            frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\' http: https:; img-src http: https: data:; font-src http: https: data:; script-src \'none\'; form-action \'none\'; base-uri \'none\'"></head><body></body></html>';
            this.#host.replaceChildren(frame); await loaded;
            const doc = frame.contentDocument; doc.body.inert = true;
            const base = original.createElement('base'); base.href = original.baseURI; doc.head.append(doc.importNode(base, false));
            // Stylesheets may contain font faces; scripts, actions and render hooks are never copied.
            for (const link of original.head.querySelectorAll('link[rel=stylesheet]')) {
                const copy = doc.createElement('link'); copy.rel = 'stylesheet'; copy.href = link.href; doc.head.append(copy);
            }
            const pseudos = doc.createElement('style'); doc.head.append(pseudos);
            const snapshot = (node, deep = false) => {
                if (node.nodeType === Node.TEXT_NODE) return doc.createTextNode(node.textContent);
                if (node.nodeType !== Node.ELEMENT_NODE || node.matches('script,iframe,object,embed,link,style,[data-quill-toolbar],[data-quill-resizer],[data-quill-inserter],[data-quill-component-outline]')) return doc.createTextNode('');
                if (++this.#nodes > 3000) throw new Error('Choose a smaller subtree for the animation preview (up to 3000 elements).');
                const copy = doc.importNode(node.cloneNode(false), false), computed = original.defaultView.getComputedStyle(node);
                for (const attribute of [...copy.attributes]) if (/^on/i.test(attribute.name) || /^(?:data-quill-|contenteditable|autofocus|srcdoc)/.test(attribute.name)) copy.removeAttribute(attribute.name);
                for (const property of computed) copy.style.setProperty(property, computed.getPropertyValue(property));
                copy.style.setProperty('animation', 'none', 'important'); copy.style.setProperty('transition', 'none', 'important');
                // Editor selection outlines are not authored appearance.
                if (node.hasAttribute('data-quill-editor-selected')) { copy.style.removeProperty('outline'); copy.style.outline = 'none'; }
                const marker = `studio-snapshot-${this.#nodes}`; copy.classList.add(marker);
                for (const pseudo of ['::before','::after']) {
                    const style = original.defaultView.getComputedStyle(node, pseudo);
                    if (!style.content || ['none','normal'].includes(style.content)) continue;
                    const declaration = doc.createElement('div').style;
                    for (const property of style) declaration.setProperty(property, style.getPropertyValue(property));
                    declaration.animation = 'none'; declaration.transition = 'none';
                    pseudos.sheet.insertRule(`.${marker}${pseudo}{${declaration.cssText}}`);
                }
                if (node instanceof original.defaultView.HTMLInputElement) { copy.value = node.value; copy.checked = node.checked; }
                if (deep) node.childNodes.forEach(child => copy.append(snapshot(child, true)));
                return copy;
            };
            const ancestors = []; for (let node = source.parentElement; node && node !== original.documentElement; node = node.parentElement) ancestors.unshift(node);
            const space = doc.createElement('div');
            space.style.cssText = `position:absolute;left:0;top:0;width:${original.defaultView.innerWidth}px;height:${original.defaultView.innerHeight}px;transform-origin:0 0`;
            doc.body.append(space);
            let parent = space;
            for (const ancestor of ancestors) {
                if (ancestor === original.body) {
                    for (const property of original.defaultView.getComputedStyle(ancestor)) doc.body.style.setProperty(property, original.defaultView.getComputedStyle(ancestor).getPropertyValue(property));
                    doc.body.style.animation = 'none'; doc.body.style.transition = 'none'; continue;
                }
                const shell = snapshot(ancestor); parent.append(shell); parent = shell;
            }
            this.element = snapshot(source, true); parent.append(this.element);
            this.element.style.flex = 'none';
            if (source.hasAttribute('data-quill-component')) {
                this.element.style.display = 'flow-root'; this.element.style.width = 'max-content'; this.element.style.height = 'max-content';
            }
            const authoredOrigin = source.computedStyleMap?.().get('transform-origin')?.toString();
            if (authoredOrigin) this.element.style.transformOrigin = authoredOrigin;
            await doc.fonts.ready;
            const bounds = this.element.getBoundingClientRect(), diagonal = Math.hypot(bounds.width, bounds.height);
            const scale = Math.min(1, original.defaultView.innerWidth / Math.max(1, diagonal * 3), original.defaultView.innerHeight / Math.max(1, diagonal * 3));
            space.style.transform = `translate(${original.defaultView.innerWidth / 2 - (bounds.left+bounds.width/2)*scale}px, ${original.defaultView.innerHeight / 2 - (bounds.top+bounds.height/2)*scale}px) scale(${scale})`;
            this.fit();
            this.observer = new ResizeObserver(() => this.fit()); this.observer.observe(this.#host);
            return this.element;
        }
        fit() {
            if (!this.element) return;
            const rect = this.element.getBoundingClientRect(), host = this.#host.getBoundingClientRect();
            const scale = Math.min(8, (host.width - 48) / Math.max(rect.width, 40), (host.height - 48) / Math.max(rect.height, 40));
            this.#frame.style.transform = `translate(${host.width / 2 - (rect.left + rect.width / 2) * scale}px, ${host.height / 2 - (rect.top + rect.height / 2) * scale}px) scale(${Math.max(.02, scale)})`;
        }
        destroy() { this.observer?.disconnect(); this.#frame?.remove(); this.element = null; }
    }
    window.QuillAnimationPreview = QuillAnimationPreview;
})();
