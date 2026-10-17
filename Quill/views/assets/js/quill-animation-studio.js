(() => {
    'use strict';
    const make = (tag, cls = '', text = '') => { const node = document.createElement(tag); node.className = cls; node.textContent = text; return node; };
    const uid = prefix => prefix + '-' + crypto.randomUUID();
    class QuillAnimationStudio {
        #editor; #dialog; #lease; #session; #preview; #adapter; #properties; #abort; #animationFrame;
        #colorPickerHome = null; #propertyChoices; #libraryRecord = null; #saving = false;
        #sourcePath; #target; #binding; #bindingInitial; #sourceDocument; #opener;
        constructor(editor, assetBase) {
            this.#editor = editor; this.assetBase = assetBase;
            this.#dialog = document.getElementById('animation-studio');
            this.#propertyChoices = [...this.#get('animated-properties').options].map(option => option.value);
            this.#dialog.addEventListener('cancel', event => { event.preventDefault(); this.requestClose(); });
            this.#dialog.addEventListener('close', () => {
                if (!this.#session) return;
                if (this.dirty) { this.#dialog.showModal(); this.requestClose(); } else this.discard();
            });
            this.#dialog.querySelector('[data-studio-close]').addEventListener('click', () => this.requestClose());
            this.#dialog.querySelector('[data-studio-save]').addEventListener('click', () => this.#attempt(() => this.save()));
            this.#dialog.querySelector('[data-studio-discard]').addEventListener('click', () => this.discard());
            const confirmation = document.getElementById('studio-close-confirm');
            confirmation.querySelector('[data-studio-continue]').addEventListener('click', () => confirmation.close());
            confirmation.querySelector('[data-studio-save]').addEventListener('click', () => { confirmation.close(); this.#attempt(() => this.save()); });
            confirmation.querySelector('[data-studio-discard]').addEventListener('click', () => this.discard());
            // Shared controls handle edits locally. Keep them away from page-level
            // delegates that may still retain a normal editor control.
            for (const name of ['input', 'change']) this.#dialog.addEventListener(name, event => event.stopPropagation());
            this.#dialog.addEventListener('keydown', event => {
                if (event.key === 'Escape') event.stopPropagation();
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
                    event.stopImmediatePropagation();
                    if (!event.target.closest('input,textarea,select,[contenteditable]')) { event.preventDefault(); event.shiftKey ? this.#session?.redo() : this.#session?.undo(); }
                }
            });
            window.addEventListener('beforeunload', event => { if (this.active && this.dirty) { event.preventDefault(); event.returnValue = ''; } });
        }
        get active() { return !!this.#session || !!this.#lease; }
        get dirty() { return !!this.#session && (this.#session.dirty || JSON.stringify(this.#bindingState()) !== this.#bindingInitial); }
        get session() { return this.#session; }
        get adapter() { return this.#adapter; }
        #get(id) { return this.#dialog.querySelector(`#studio-${id}`); }
        #error(error) { const node = this.#get('error'); node.textContent = error?.message || ''; node.hidden = !error; }
        #attempt(callback) { try { const result = callback(); if (result?.catch) result.catch(error => this.#error(error)); else this.#error(null); } catch (error) { this.#error(error); } }
        async open(id = null) {
            if (this.#editor.type !== 'page') throw new Error('Create keyframe animations from a page. Component definitions are edited separately.');
            if (this.active) throw new Error('Animation Studio is already open.');
            if (this.#editor.ui.save.saving) throw new Error('Wait for the current save to finish before opening Animation Studio.');
            this.#sourcePath = this.#editor.selectedPath;
            const source = this.#editor.canvas.getElement(this.#sourcePath);
            if (!source) throw new Error('Select an element first.');
            if (source.querySelector('[contenteditable]') || source.isContentEditable) throw new Error('Finish text editing before opening Animation Studio.');
            const documentSnapshot = this.#editor.getDocument(), registry = documentSnapshot.settings?.animations || {};
            if (id && typeof id !== 'number' && !Object.hasOwn(registry, id)) throw new Error('This animation no longer exists.');
            this.#sourceDocument = documentSnapshot; this.#libraryRecord = null; this.#busy(false);
            const [section, ...path] = this.#sourcePath.split('.').map(Number);
            this.#target = { section: documentSnapshot.sections[section].name, path };
            this.#binding = documentSnapshot.settings?.animation_bindings?.find(binding => binding.animationId === id && JSON.stringify(binding.target) === JSON.stringify(this.#target));
            this.#opener = document.activeElement;
            this.#lease = this.#editor.state.beginIsolatedSession();
            this.#abort = new AbortController();
            const signal = this.#abort.signal;
            this.#dialog.querySelector('[data-studio-save]').disabled = true;
            document.getElementById('quill-editor').inert = true;
            this.#dialog.showModal(); this.#get('error').hidden = true;
            this.#get('preview').textContent = 'Preparing isolated preview…';
            try {
                if (typeof id === 'number') {
                    if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid animation identity.');
                    const record = await this.#editor.ui.animationLibrary.get(id);
                    if (signal.aborted) return;
                    this.#libraryRecord = record;
                }
                this.#preview = new window.QuillAnimationPreview(this.#get('preview'), source);
                const element = await this.#preview.mount(signal);
                if (signal.aborted) return;
                const definition = this.#libraryRecord ? this.#libraryRecord.definition : id ? registry[id] : { id: uid('qanim'), name: 'New animation', duration: 1200, delay: 0, iterations: 1, direction: 'normal', fillMode: 'both', easing: 'ease-in-out', tracks: {} };
                this.#session = new window.QuillAnimationSession(definition, element);
                this.#get('animated-properties').replaceChildren(...this.#propertyChoices.filter(property => window.QuillAnimations.supports(property)).map(property => new Option(property, property)));
                this.#adapter = new window.QuillAnimationAdapter(this.#editor, this.#session, element, error => this.#error(error));
                this.#adapter.properties.add({ key: 'Quill:studio-effects', category: 'style', categoryLabel: 'Appearance', elements: [this.assetBase + 'studio-effects.js'] });
                this.#get('properties').replaceChildren();
                const tabs = make('div', 'editor-property-tabs'); tabs.role = 'tablist'; tabs.setAttribute('aria-label', 'Animation properties');
                const panels = make('div'); panels.id = 'studio-property-panels'; this.#get('properties').append(make('p', 'studio-hint', 'Only visual styles are recorded. Content, attributes and layout mode are unavailable. Gradients and other discrete values switch at keyframes.'), tabs, panels);
                this.#properties = new window.QuillEditorProperties(this.#get('properties'), this.#adapter, { prefix: 'studio', scopeControls: false });
                const existing = this.#editor.getSelectedBlock().animations?.find(binding => binding.animation_id === this.#libraryRecord?.id);
                this.#get('bind').checked = id ? !!(existing || this.#binding) : true;
                this.#get('trigger').value = this.#binding?.trigger || existing?.trigger || 'load';
                this.#bindingInitial = JSON.stringify(this.#bindingState());
                this.#get('context').textContent = `Isolated preview · ${this.#editor.styleViewport === 'all' ? 'Current viewport' : this.#editor.styleViewport} · ${this.#editor.styleState} appearance`;
                this.#wire(); this.#render();
                this.#dialog.querySelector('[data-studio-save]').disabled = false;
                await this.#properties.refresh();
                const picker = document.getElementById('clr-picker');
                if (picker && window.Coloris) { this.#colorPickerHome = picker.parentElement; window.Coloris.close(); window.Coloris({ parent: this.#dialog }); }
                if (this.#adapter.properties.all().some(definition => definition.category === 'style')) await this.#properties.showCategory('style');
                if (signal.aborted) return;
                this.#get('name').focus(); this.#get('name').select();
            } catch (error) { if (signal.aborted) return; this.discard(); throw error; }
        }
        #bindingState() { return { bound: this.#get('bind').checked, trigger: this.#get('trigger').value }; }
        #wire() {
            const options = { signal: this.#abort.signal }, session = this.#session;
            const setting = (id, key, read = input => input.value) => this.#get(id).addEventListener(id === 'name' ? 'input' : 'change', event => this.#attempt(() => session.configure({ [key]: read(event.target) })), options);
            setting('name', 'name'); setting('duration', 'duration', input => Number(input.value)); setting('delay', 'delay', input => Number(input.value));
            setting('iterations', 'iterations', input => Number(input.value)); setting('direction', 'direction'); setting('fill', 'fillMode'); setting('easing', 'easing');
            this.#get('infinite').addEventListener('change', event => this.#attempt(() => session.configure({ iterations: event.target.checked ? 'infinite' : Number(this.#get('iterations').value) || 1 })), options);
            this.#get('playhead').addEventListener('input', event => { session.seek(Number(event.target.value)); this.#renderTime(); }, options);
            this.#get('play').addEventListener('click', () => {
                if (session.playing) session.pause(); else session.play(); this.#renderTime(); this.#playback();
            }, options);
            this.#get('reset').addEventListener('click', () => { session.seek(0); this.#renderTime(); }, options);
            this.#get('undo').addEventListener('click', () => session.undo(), options);
            this.#get('redo').addEventListener('click', () => session.redo(), options);
            this.#get('add-keyframe').addEventListener('click', () => this.#attempt(() => session.add(this.#get('property').value.trim())), options);
            this.#get('delete-keyframe').addEventListener('click', () => session.remove(), options);
            const edit = () => this.#attempt(() => session.editKeyframe({ time: Number(this.#get('keyframe-time').value), value: this.#get('keyframe-value').value, easing: this.#get('keyframe-easing').value }));
            for (const id of ['keyframe-time','keyframe-value','keyframe-easing']) this.#get(id).addEventListener(id === 'name' ? 'input' : 'change', edit, options);
            session.addEventListener('change', () => { this.#render(); this.#preview.fit(); }, options);
            session.addEventListener('selectionchange', () => this.#render(), options);
            session.addEventListener('seek', () => this.#renderTime(), options);
        }
        #renderTime() {
            if (!this.#session) return;
            const session = this.#session; this.#get('playhead').max = session.definition.duration;
            this.#get('playhead').value = session.time; this.#get('time').textContent = `${Math.round(session.time)} / ${session.definition.duration} ms`;
            this.#get('play').textContent = session.playing ? 'Pause' : 'Play';
            for (const marker of this.#get('tracks').querySelectorAll('.studio-track-playhead')) marker.style.left = `${session.time / session.definition.duration * 100}%`;
        }
        #playback() {
            cancelAnimationFrame(this.#animationFrame);
            const tick = () => { if (!this.#session) return; this.#session.tick(); this.#renderTime(); if (this.#session.playing) this.#animationFrame = requestAnimationFrame(tick); };
            this.#animationFrame = requestAnimationFrame(tick);
        }
        #render() {
            const session = this.#session; if (!session) return;
            const animation = session.definition;
            for (const [id, value] of Object.entries({ name: animation.name, duration: animation.duration, delay: animation.delay, iterations: animation.iterations === 'infinite' ? 1 : animation.iterations, direction: animation.direction, fill: animation.fillMode, easing: animation.easing })) {
                const input = this.#get(id); if (document.activeElement !== input) input.value = value;
            }
            this.#get('infinite').checked = animation.iterations === 'infinite'; this.#get('iterations').disabled = animation.iterations === 'infinite';
            this.#get('undo').disabled = !session.canUndo; this.#get('redo').disabled = !session.canRedo;
            this.#get('play').disabled = !Object.keys(animation.tracks).length;
            const singleTracks = Object.entries(animation.tracks).filter(([, frames]) => frames.length === 1);
            const hint = this.#get('track-hint');
            const explanation = singleTracks.length === 1
                ? `${window.QuillAnimations.label(singleTracks[0][0])} has only one keyframe.`
                : `${singleTracks.length} tracks have only one keyframe.`;
            const hintText = singleTracks.length ? `${explanation} Move the playhead and change the property to add a second value. Missing endpoints use the element’s original appearance.` : '';
            if (hint.textContent !== hintText) hint.textContent = hintText;
            hint.hidden = !singleTracks.length;
            const focusedKeyframe = document.activeElement?.classList.contains('studio-keyframe');
            const tracks = this.#get('tracks'); tracks.replaceChildren();
            if (!Object.keys(animation.tracks).length) tracks.append(make('p', 'studio-hint', 'Move the playhead, then change a visual property to create its first keyframe.'));
            for (const [property, frames] of Object.entries(animation.tracks)) {
                const row = make('div', 'studio-track'); row.dataset.track = property;
                const label = make('button', 'studio-track-label'); label.type = 'button'; label.title = `Add a ${property} keyframe at the playhead`; label.addEventListener('click', () => this.#attempt(() => session.add(property)));
                row.dataset.singleKeyframe = String(frames.length === 1);
                const count = make('span', 'studio-track-count', `${frames.length} ${frames.length === 1 ? 'keyframe · Add another' : 'keyframes'}`);
                label.append(make('span', 'studio-track-name', window.QuillAnimations.label(property)), count);
                if (frames.length === 1) label.title += '. Only one value is defined; move the playhead to add another.';
                const line = make('div', 'studio-track-line'); line.setAttribute('aria-label', `${property} keyframes`);
                const playhead = make('span', 'studio-track-playhead'); line.append(playhead);
                for (const frame of frames) {
                    const point = make('button', 'studio-keyframe'); point.type = 'button'; point.dataset.time = frame.time;
                    point.style.left = `${frame.time / animation.duration * 100}%`;
                    point.title = `${property} · ${Math.round(frame.time)}ms · ${frame.value}`; point.setAttribute('aria-label', point.title);
                    point.setAttribute('aria-pressed', String(session.selected?.property === property && session.selected?.time === frame.time));
                    let moved = false;
                    point.addEventListener('click', () => { if (!moved) session.select(property, frame.time); });
                    point.addEventListener('keydown', event => {
                        if (!['ArrowLeft','ArrowRight','Delete','Backspace'].includes(event.key)) return;
                        event.preventDefault(); session.select(property, frame.time);
                        if (['Delete','Backspace'].includes(event.key)) session.remove();
                        else this.#attempt(() => session.editKeyframe({ ...frame, time: Math.max(0, Math.min(animation.duration, frame.time + (event.key === 'ArrowLeft' ? -1 : 1) * (event.shiftKey ? 100 : 10))) }));
                    });
                    point.addEventListener('pointerdown', event => {
                        if (event.button !== 0) return;
                        event.preventDefault(); const start = event.clientX, width = line.getBoundingClientRect().width;
                        point.setPointerCapture(event.pointerId);
                        point.onpointermove = event => { moved = Math.abs(event.clientX-start) > 2; point.style.left = `${Math.max(0, Math.min(100, frame.time / animation.duration * 100 + (event.clientX-start) / width * 100))}%`; };
                        point.onpointerup = event => {
                            point.onpointermove = point.onpointerup = null; session.select(property, frame.time);
                            this.#attempt(() => session.editKeyframe({ ...frame, time: Math.max(0, Math.min(animation.duration, Math.round(frame.time + (event.clientX-start) / width * animation.duration))) }));
                        };
                    });
                    line.append(point);
                }
                const remove = window.QuillEditorIcon.set(make('button', 'editor-action-icon'), 'xmark'); remove.type = 'button'; remove.setAttribute('aria-label', `Delete ${property} track`); remove.addEventListener('click', () => session.removeTrack(property));
                row.append(label, line, remove); tracks.append(row);
            }
            const selected = session.selected, frame = selected && animation.tracks[selected.property]?.find(frame => frame.time === selected.time);
            this.#get('keyframe').hidden = !frame;
            if (frame) {
                this.#get('keyframe-property').textContent = window.QuillAnimations.label(selected.property);
                for (const [id, value] of Object.entries({ 'keyframe-time': frame.time, 'keyframe-value': frame.value, 'keyframe-easing': frame.easing || '' })) if (document.activeElement !== this.#get(id)) this.#get(id).value = value;
            }
            this.#renderTime();
            if (focusedKeyframe) tracks.querySelector('[aria-pressed="true"]')?.focus();
        }
        requestClose() { if (!this.#lease || this.#saving) return; if (this.dirty) document.getElementById('studio-close-confirm').showModal(); else this.discard(); }
        #busy(value) {
            this.#dialog.querySelector('.studio-workspace').inert = value;
            this.#dialog.setAttribute('aria-busy', String(value));
            this.#dialog.querySelectorAll(':scope > header button, :scope > footer button').forEach(button => { button.disabled = value; });
            this.#dialog.querySelector('[data-studio-save]').textContent = value ? 'Saving…' : 'Save Animation';
        }
        async save(mode = null) {
            if (!this.#session || this.#saving) return;
            window.Coloris?.close();
            const invalid = this.#dialog.querySelector('input:invalid,select:invalid');
            if (invalid) { invalid.reportValidity(); throw new Error('Complete the animation settings before saving.'); }
            const animation = this.#session.definition; animation.name = this.#get('name').value.trim();
            window.QuillAnimations.validate({ [animation.id]: animation });
            this.#saving = true; this.#busy(true);
            try {
                const library = this.#editor.ui.animationLibrary;
                if (this.#libraryRecord && !mode) mode = await library.chooseSave(this.#libraryRecord);
                if (this.#libraryRecord && (!mode || mode === 'cancel')) return;
                if (mode && !['new','replace'].includes(mode)) throw new Error('Choose Save as new or Replace.');
                const saved = await library.save(animation, this.#libraryRecord, mode || 'new');
                const current = this.#editor.getSelectedBlock().animations || [];
                const bindings = current.filter(binding => binding.animation_id !== this.#libraryRecord?.id && binding.animation_id !== saved.id);
                if (this.#get('bind').checked) bindings.push({ animation_id: saved.id, trigger: this.#get('trigger').value });
                this.#lease.commitAnimationBindings(this.#sourcePath, bindings, this.#binding?.id);
                this.#finish();
            } finally { this.#saving = false; if (this.active) this.#busy(false); }
        }
        discard() { if (!this.#saving) this.#finish(); }
        #finish() {
            cancelAnimationFrame(this.#animationFrame); this.#abort?.abort();
            if (this.#colorPickerHome && window.Coloris) { window.Coloris.close(); window.Coloris({ parent: this.#colorPickerHome }); this.#colorPickerHome = null; }
            this.#properties?.destroy(); this.#adapter?.destroy(); this.#session?.destroy(); this.#preview?.destroy();
            this.#session = this.#adapter = this.#properties = this.#preview = null;
            this.#lease?.release(); this.#lease = null;
            document.getElementById('studio-close-confirm').close(); this.#dialog.close();
            document.getElementById('quill-editor').inert = false;
            this.#opener?.isConnected && this.#opener.focus();
        }
    }
    window.QuillAnimationStudio = QuillAnimationStudio;
})();
