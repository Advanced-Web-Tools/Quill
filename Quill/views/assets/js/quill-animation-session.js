/* Independent temporary state and an explicit, capability-limited property adapter. */
(() => {
    'use strict';
    const copy = value => structuredClone(value);
    const styleObject = css => { const value = document.createElement('div').style; value.cssText = css; return value; };
    const reject = () => { throw new Error('Animation Studio edits visual styles only. Content, attributes, actions and structure are unavailable.'); };
    class QuillAnimationSession extends EventTarget {
        #animation; #initial; #baseline; #element; #effects = []; #undo = []; #redo = [];
        time = 0; selected = null; playing = false;
        constructor(animation, element) {
            super(); this.#animation = copy(animation); this.#initial = JSON.stringify(animation); this.#element = element;
            this.#baseline = styleObject(element.style.cssText);
            for (const [property, axis] of Object.entries(window.QuillAnimations.axes)) {
                try { element.ownerDocument.defaultView.CSS.registerProperty({ name: property, syntax: axis.syntax, inherits: false, initialValue: axis.initial }); } catch { /* Already registered in this preview document. */ }
                const values = window.QuillAnimations.components(this.#baseline.getPropertyValue(axis.group) === 'none' ? '' : this.#baseline.getPropertyValue(axis.group));
                let value = values[['x','y','z'].indexOf(axis.axis)] || (axis.group === 'scale' && axis.axis === 'y' ? values[0] : '') || axis.initial;
                if (axis.group === 'scale' && value.endsWith('%')) value = String(parseFloat(value)/100);
                this.#baseline.setProperty(property, value); element.style.setProperty(property, value);
            }

            this.seek(0);
        }
        get definition() { return copy(this.#animation); }
        get dirty() { return JSON.stringify(this.#animation) !== this.#initial; }
        get canUndo() { return !!this.#undo.length; }
        get canRedo() { return !!this.#redo.length; }
        #remember() { this.#undo.push(this.definition); if (this.#undo.length > 100) this.#undo.shift(); this.#redo = []; }
        #notify() { this.seek(this.time); this.dispatchEvent(new Event('change')); }
        configure(changes) {
            const next = { ...this.definition, ...changes };
            if (!window.QuillAnimations.validEasing(next.easing)) throw new Error('Use a CSS easing such as ease-in-out or cubic-bezier(0.2, 0.8, 0.2, 1).');
            if (!Number.isFinite(next.duration) || next.duration < 1 || next.duration > 600000 || !Number.isFinite(next.delay) || next.delay < 0 || next.delay > 600000
                || !(next.iterations === 'infinite' || (Number.isInteger(next.iterations) && next.iterations >= 1 && next.iterations <= 1000))) throw new Error('Enter valid timing values (up to 600000ms and 1000 iterations).');
            if (next.duration !== this.#animation.duration) {
                for (const frames of Object.values(next.tracks)) for (const frame of frames) frame.time = frame.time / this.#animation.duration * next.duration;
                this.time = this.time / this.#animation.duration * next.duration;
            }
            if (JSON.stringify(next) === JSON.stringify(this.#animation)) return;
            this.#remember(); this.#animation = next; this.#notify();
        }
        declarations() {
            const style = styleObject(''), computed = this.#element.ownerDocument.defaultView.getComputedStyle(this.#element);
            for (const property of this.#baseline) if (window.QuillAnimations.supports(property)) style.setProperty(property, computed.getPropertyValue(property) || this.#baseline.getPropertyValue(property));
            for (const [property, frames] of Object.entries(this.#animation.tracks)) style.setProperty(property, frames.find(frame => Math.abs(frame.time-this.time)<.001)?.value || computed.getPropertyValue(property));
            const origin = this.#animation.tracks['transform-origin']?.find(frame => Math.abs(frame.time-this.time)<.001)?.value || (!this.#animation.tracks['transform-origin'] && this.#baseline.transformOrigin);
            if (origin) style.transformOrigin = origin;
            return style;
        }
        #expand(value, style, depth = 0) {
            if (depth > 12) throw new Error('Circular or unresolved CSS variable in animation.');
            if (!value.includes('var(')) return value;
            return this.#expand(value.replace(/var\(\s*(--[\w-]+)\s*(?:,\s*([^()]+))?\)/g, (all, name, fallback) => style.getPropertyValue(name) || this.#baseline.getPropertyValue(name) || fallback || all), style, depth + 1);
        }
        apply(css) {
            const before = this.declarations(), after = styleObject(css);
            const changes = new Map();
            for (const property of new Set([...before, ...after])) {
                const value = after.getPropertyValue(property);
                if (value === before.getPropertyValue(property)) continue;
                if (!window.QuillAnimations.supports(property)) throw new Error(`${property} is not available in Animation Studio.`);
                // Translation helpers are an implementation detail of the shared spatial control.
                if (/^--quill-(?:move|center)-[xy]$/.test(property)) continue;
                const resolved = value ? this.#expand(value, after) : this.#baseline.getPropertyValue(property) || 'initial';
                if (!CSS.supports(property, resolved)) throw new Error(`Invalid animation value for ${property}.`);
                changes.set(property, resolved);
            }
            if (!changes.size) return;
            this.#remember();
            for (const [property, value] of changes) this.#put(property, value);
            this.#notify();
        }
        #put(property, value) {
            if (['translate','scale'].includes(property)) {
                const values = window.QuillAnimations.components(value === 'none' ? '' : value), before = window.QuillAnimations.components(this.declarations().getPropertyValue(property));
                let selected = null;
                for (const [index, axis] of ['x','y','z'].entries()) {
                    const key = `--quill-animation-${property}-${axis}`, fallback = window.QuillAnimations.axes[key].initial;
                    let part = values[index] || (property === 'scale' && axis === 'y' ? values[0] : '') || fallback;
                    if (property === 'scale' && part.endsWith('%')) part = String(parseFloat(part)/100);
                    if (axis === 'z' && !values[2] && this.#baseline.getPropertyValue(key) === fallback) continue;
                    if (!this.#animation.tracks[key] || part !== (before[index] || fallback)) this.#putScalar(key, part);
                    if (part !== (before[index] || fallback)) selected = key;
                }
                this.selected = { property: selected || `--quill-animation-${property}-x`, time: this.time };
                return;
            }
            this.#putScalar(property, value);
        }
        #putScalar(property, value) {
            const frames = this.#animation.tracks[property] ||= [];
            if (!frames.length && this.time > 0) frames.push({ time: 0, value: this.#baseline.getPropertyValue(property) || 'initial' });
            const frame = frames.find(frame => Math.abs(frame.time - this.time) < .001);
            if (frame) frame.value = value; else frames.push({ time: this.time, value });
            frames.sort((a,b) => a.time-b.time); this.selected = { property, time: this.time };
        }
        add(property) {
            if (!window.QuillAnimations.supports(property)) throw new Error('Choose an animatable CSS property.');
            this.#remember(); this.#put(property, this.declarations().getPropertyValue(property) || this.#baseline.getPropertyValue(property) || 'initial'); this.#notify();
        }
        select(property, time) { this.selected = { property, time }; this.seek(time); this.dispatchEvent(new Event('selectionchange')); }
        editKeyframe({ time, value, easing }) {
            if (!this.selected) return;
            const {property, time: oldTime} = this.selected, frames = this.#animation.tracks[property];
            const frame = frames?.find(frame => frame.time === oldTime); if (!frame) return;
            if (!Number.isFinite(time) || time < 0 || time > this.#animation.duration || frames.some(item => item !== frame && Math.abs(item.time-time) < .001)) throw new Error('Choose a unique keyframe time within the duration.');
            if (!CSS.supports(property, value) || /[{};<>\x00-\x1f\x7f]|\/\*|\*\//.test(value) || (easing && !window.QuillAnimations.validEasing(easing))) throw new Error('Enter a valid keyframe value and easing.');
            this.#remember(); frame.time = time; frame.value = value;
            if (easing) frame.easing = easing; else delete frame.easing;
            frames.sort((a,b) => a.time-b.time); this.selected.time = time; this.time = time; this.#notify();
        }
        remove() {
            if (!this.selected) return;
            const {property, time} = this.selected; this.#remember();
            const frames = this.#animation.tracks[property].filter(frame => frame.time !== time);
            if (frames.length) this.#animation.tracks[property] = frames; else delete this.#animation.tracks[property];
            this.selected = null; this.#notify();
        }
        removeTrack(property) { if (!this.#animation.tracks[property]) return; this.#remember(); delete this.#animation.tracks[property]; this.selected = null; this.#notify(); }
        undo() { if (!this.canUndo) return; this.#redo.push(this.definition); this.#animation = this.#undo.pop(); this.selected = null; this.#notify(); }
        redo() { if (!this.canRedo) return; this.#undo.push(this.definition); this.#animation = this.#redo.pop(); this.selected = null; this.#notify(); }
        #cancel() { this.#effects.forEach(effect => effect.cancel()); this.#effects = []; }
        seek(time) {
            this.playing = false; this.#cancel(); this.time = Math.max(0, Math.min(this.#animation.duration, Number(time) || 0));
            const animation = this.#animation;
            for (const group of ['translate','scale']) {
                if (Object.keys(animation.tracks).some(property => window.QuillAnimations.axes[property]?.group === group)) this.#element.style.setProperty(group, ['x','y','z'].map(axis => `var(--quill-animation-${group}-${axis})`).join(' '));
                else this.#element.style.setProperty(group, this.#baseline.getPropertyValue(group));
            }
            for (const [property, frames] of Object.entries(animation.tracks)) {
                const effect = this.#element.animate(frames.map(frame => ({ [property]: frame.value, offset: frame.time / animation.duration, easing: frame.easing || animation.easing })), {
                    duration: animation.duration, delay: animation.delay, iterations: animation.iterations === 'infinite' ? Infinity : animation.iterations,
                    direction: animation.direction, fill: animation.fillMode, easing: 'linear',
                });
                effect.pause(); effect.currentTime = animation.delay + this.time; this.#effects.push(effect);
            }
            this.dispatchEvent(new Event('seek'));
        }
        play() {
            this.seek(this.time >= this.#animation.duration ? 0 : this.time);
            this.playing = true;
            for (const effect of this.#effects) { effect.currentTime = this.time ? this.#animation.delay + this.time : 0; effect.play(); }
        }
        tick() {
            const effect = this.#effects[0]; if (!this.playing || !effect) return;
            const elapsed = Math.max(0, Number(effect.currentTime) - this.#animation.delay);
            this.time = effect.playState === 'finished' ? this.#animation.duration : elapsed % this.#animation.duration;
            if (this.#effects.every(effect => effect.playState === 'finished')) this.playing = false;
        }
        pause() { this.seek(this.time); }
        destroy() { this.#cancel(); this.playing = false; }
    }

    class QuillAnimationAdapter extends EventTarget {
        constructor(editor, session, element, reportError) {
            super(); const path = editor.selectedPath, snapshot = editor.getSelectedBlock(), documentSnapshot = editor.getDocument();
            const definitions = editor.properties.all().filter(definition => !['Quill:advanced','Quill:clickable','Quill:link','Quill:image','Quill:icon','Quill:heading','Quill:list','Quill:table','Quill:motion'].includes(definition.key));
            this.properties = new window.QuillEditorRegistry(definitions); this.type = editor.type;
            this.modules = editor.modules; this.fonts = editor.fonts; this.selectedPath = path;
            this.styleViewport = editor.styleViewport; this.styleState = 'default'; this.styleScope = 'base'; this.breakpoints = copy(editor.breakpoints);
            this.editingContext = Object.freeze({ type: 'animation', isolated: true });
            this.supportsProperty = property => property === 'move-transform' || (window.QuillAnimations.supports(property) && !['font-family'].includes(property));
            this.reportError = reportError; this.isReadOnly = () => false;
            this.canvas = Object.freeze({ getElement: target => target === path ? element : null, picking: false });
            this.getStyleDeclarations = () => session.declarations();
            this.setStyleDeclarations = css => session.apply(css);
            this.setStyle = (property, value) => {
                if (!this.supportsProperty(property)) throw new Error(`${property} cannot be animated safely.`);
                if (value && !CSS.supports(property, value)) throw new Error(`Invalid animation value for ${property}.`);
                const style = session.declarations(); if (value) style.setProperty(property, value); else style.removeProperty(property);
                session.apply(style.cssText);
            };
            this.setAttribute = (name, value) => name === 'style' ? session.apply(value || '') : reject();
            this.getSelectedBlock = () => ({ ...copy(snapshot), styles: { base: {} }, attributes: { ...copy(snapshot.attributes), style: session.declarations().cssText } });
            this.getBlock = target => target === path ? this.getSelectedBlock() : null;
            this.getDocument = () => copy(documentSnapshot);
            this.on = (name, callback, options) => { this.addEventListener(name, callback, options); return () => this.removeEventListener(name, callback, options); };
            const localState = new EventTarget();
            localState.getDocument = () => copy(documentSnapshot);
            localState.updateSettings = changes => {
                if (Object.keys(changes).some(key => !['color_palette','color_palettes','default_color_palette'].includes(key))) reject();
                if (changes.color_palette !== undefined) window.QuillEditorPalette.validate(changes.color_palette);
                window.QuillEditorPalette.validateCollections({...(documentSnapshot.settings || {}),...changes});
                documentSnapshot.settings = {...(documentSnapshot.settings || {}),...copy(changes)}; localState.dispatchEvent(new Event('change'));
            };
            for (const method of ['updateBlock','updateActions','select','insertBlocks','removeBlock','moveBlock','restoreDocument']) localState[method] = reject;
            this.state = Object.freeze(localState); this.palette = new window.QuillEditorPalette(localState, { url: editor.palette.libraryUrl, globalPalettes: editor.palette.globals(), externalPalettes: editor.palette.externals(), isolated: true });
            for (const method of ['updateBlock','setContent','setProps','select','insertBlock','duplicateBlock','removeBlock','save','setStyleViewport','setStyleState','registerRenderer']) this[method] = reject;
            this.ui = Object.freeze({});
            session.addEventListener('seek', () => this.dispatchEvent(new Event('canvasrendered')));
        }
        destroy() { this.palette.close(); }
    }
    window.QuillAnimationSession = QuillAnimationSession;
    window.QuillAnimationAdapter = QuillAnimationAdapter;
})();
