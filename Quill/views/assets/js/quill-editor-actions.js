(() => {
    'use strict';
    const make = (tag, className = '', text = '') => {
        const element = document.createElement(tag); element.className = className; element.textContent = text; return element;
    };
    const uid = prefix => prefix + '-' + crypto.randomUUID();
    const newStep = () => ({ id: uid('qs'), target: { type: 'self' }, condition: null, operation: { type: 'set-style', property: 'background-color', value: '' } });
    const operationNames = { 'set-style': 'Change style', 'reset-style': 'Reset style', 'add-class': 'Add class', 'remove-class': 'Remove class', 'toggle-class': 'Toggle class', show: 'Show element', hide: 'Hide element', 'toggle-visibility': 'Toggle visibility', 'remove-element': 'Remove element' };
    class QuillEditorActions {
        #editor; #dialog; #form; #steps; #name; #error; #trigger; #keyFields; #draft = null; #bindPath = null; #revision;
        #motionPreviews = [];
        #targetEdits = new Map(); #picking = null; #banner;
        constructor(editor) {
            this.#editor = editor;
            const dialog = make('dialog', 'editor-actions-dialog'); dialog.id = 'editor-actions-dialog';
            dialog.setAttribute('aria-labelledby', 'editor-actions-dialog-title');
            const form = make('form'); form.noValidate = true; this.#form = form;
            const header = make('header', 'editor-action-dialog-header');
            const heading = make('h2', '', 'Action editor'); heading.id = 'editor-actions-dialog-title';
            header.append(heading, this.#button('Close action editor', 'xmark', () => dialog.close()));
            const body = make('div', 'editor-action-dialog-body');
            this.#name = this.#input(body, 'Action name', '', value => { this.#draft.name = value; }, 'editor-action-name');
            this.#name.maxLength = 255; this.#name.required = true;
            const entry = make('div', 'editor-action-entry');
            const icon = make('i', 'fa-solid fa-hand-pointer'); icon.setAttribute('aria-hidden', 'true');
            entry.append(icon, make('span', '', 'Trigger'));
            this.#trigger = this.#select(entry, 'When', 'click', window.QuillActions.triggers, value => {
                this.#draft.trigger = value;
                if (value === 'keydown') this.#draft.key = 'Enter'; else delete this.#draft.key;
                this.#renderKey();
            }, 'editor-action-trigger');
            this.#keyFields = make('div', 'editor-action-key-fields');
            this.#steps = make('div', 'editor-action-steps');
            const add = make('button', 'editor-action-add-step', 'Add step'); add.type = 'button'; add.id = 'editor-action-add-step';
            add.addEventListener('click', () => { if (this.#draft.steps.length < 50) { this.#draft.steps.push(newStep()); this.#renderSteps(); } });
            this.#error = make('p', 'editor-action-error'); this.#error.id = 'editor-action-error'; this.#error.role = 'alert'; this.#error.hidden = true;
            body.append(entry, this.#keyFields, this.#steps, add, this.#error);
            const footer = make('footer', 'editor-action-dialog-footer');
            const cancel = make('button', '', 'Cancel'); cancel.type = 'button'; cancel.addEventListener('click', () => dialog.close());
            const save = make('button', 'button-primary', 'Save action'); save.type = 'submit'; save.id = 'editor-action-save'; footer.append(cancel, save);
            form.append(header, body, footer); dialog.append(form); document.body.append(dialog); this.#dialog = dialog;
            form.addEventListener('submit', event => { event.preventDefault(); this.#save(); });
            dialog.addEventListener('close', () => { this.#clearMotionPreviews(); if (!this.#picking && !dialog.open) { this.#draft = null; this.#targetEdits.clear(); } });
            this.#banner = make('div', 'editor-action-pick'); this.#banner.id = 'editor-action-pick'; this.#banner.hidden = true;
            this.#banner.append(make('span', '', 'Choose a target element on the canvas'), this.#textButton('Cancel selection', () => this.#stopPicking()));
            document.body.append(this.#banner);
            const escape = event => { if (event.key === 'Escape' && this.#picking) { event.preventDefault(); this.#stopPicking(); } };
            document.addEventListener('keydown', escape);
            const installed = new WeakSet();
            editor.on('canvasrendered', () => {
                const doc = document.getElementById('editor-canvas').contentDocument;
                if (!installed.has(doc)) { installed.add(doc); doc.addEventListener('keydown', escape); }
            });
            document.getElementById('editor-actions-new').addEventListener('click', () => this.open());
            editor.on('change', () => this.#renderList()); this.#renderList();
        }
        get actions() { return this.#editor.getDocument().settings?.actions || []; }
        open(id = null, bindPath = null, duplicate = false) {
            if (this.#dialog.open) this.#dialog.close();
            const original = id ? this.actions.find(action => action.id === id) : null;
            if (id && !original) { this.#editor.reportError(new Error('This action no longer exists.')); return; }
            this.#draft = original ? structuredClone(original) : { id: uid('qa'), name: '', trigger: 'click', steps: [newStep()] };
            if (duplicate) {
                this.#draft.id = uid('qa'); this.#draft.name += ' copy';
                this.#draft.steps.forEach(step => { step.id = uid('qs'); });
            }
            this.#bindPath = bindPath; this.#revision = this.#editor.state.revision; this.#targetEdits.clear();
            this.#name.value = this.#draft.name; this.#trigger.value = this.#draft.trigger; this.#renderKey(); this.#error.hidden = true;
            this.#renderSteps(); this.#dialog.showModal(); this.#name.focus();
        }
        assign(id, path = this.#editor.selectedPath, trigger = null) {
            const action = id ? this.actions.find(action => action.id === id) : null;
            if (id && !action) throw new Error('Choose an existing action.');
            trigger ||= action?.trigger || 'click';
            const field = window.QuillActions.bindings[trigger];
            if (!field || (action && action.trigger !== trigger)) throw new Error('Choose an action matching this event.');
            const metadata = { ...this.#editor.getBlock(path).editor };
            if (id) metadata[field] = id; else delete metadata[field];
            this.#editor.updateBlock({ editor: metadata }, path);
        }
        #renderKey() {
            this.#keyFields.replaceChildren();
            const trigger = this.#draft.trigger;
            this.#keyFields.hidden = !['keydown', 'focus', 'blur'].includes(trigger);
            if (trigger === 'keydown') this.#select(this.#keyFields, 'Key', this.#draft.key || '', window.QuillActions.keys, value => { this.#draft.key = value; }, 'editor-action-key');
            if (!this.#keyFields.hidden) this.#keyFields.append(make('p', 'editor-clickable-hint', 'Use a focusable target, such as a button or input, for keyboard and focus events.'));
        }
        remove(id) { this.#editor.state.updateActions(this.actions.filter(action => action.id !== id), [], id); }
        #button(label, icon, click) {
            const button = make('button', 'editor-action-icon'); button.type = 'button'; button.title = label; button.setAttribute('aria-label', label);
            const symbol = make('i', `fa-solid fa-${icon}`); symbol.setAttribute('aria-hidden', 'true'); button.append(symbol); button.addEventListener('click', click); return button;
        }
        #textButton(label, click) { const button = make('button', '', label); button.type = 'button'; button.addEventListener('click', click); return button; }
        #input(container, label, value, change, id = uid('field')) {
            const field = make('label', 'editor-action-field', label); field.htmlFor = id;
            const input = make('input'); input.id = id; input.type = 'text'; input.value = value || ''; input.autocomplete = 'off';
            input.addEventListener('input', () => change(input.value)); field.append(input); container.append(field); return input;
        }
        #select(container, label, value, choices, change, id = uid('field')) {
            const field = make('label', 'editor-action-field', label); field.htmlFor = id;
            const select = make('select'); select.id = id;
            Object.entries(choices).forEach(([key, title]) => select.add(new Option(title, key))); select.value = value || '';
            select.addEventListener('change', () => change(select.value)); field.append(select); container.append(field); return select;
        }
        #elements() {
            const elements = [];
            const visit = (blocks, parent) => blocks.forEach((block, index) => {
                const path = `${parent}.${index}`;
                if (block._quill?.component) {
                    for (const id of Object.keys(block._quill.component.externalTargets || {})) {
                        elements.push({ path: `shared-target:${path}:${id}`, id, shared: true, label: `${block._quill.component.name} — shared target` });
                    }
                    return;
                }
                const label = block._quill?.editor?.label || block.attributes.id || block.content.trim().slice(0, 35) || block.descriptor.name;
                elements.push({ path, id: this.#targetEdits.get(path) || block._quill?.editor?.actionTargetId, label: `${'· '.repeat(path.split('.').length - 2)}${block.descriptor.name} — ${label}` });
                visit(block.children, path);
            });
            this.#editor.getDocument().sections.forEach((section, index) => visit(section.blocks, String(index)));
            return elements;
        }
        #targetId(path) {
            const shared = this.#elements().find(element => element.shared && element.path === path);
            if (shared) return shared.id;
            const existing = this.#editor.getBlock(path).editor?.actionTargetId;
            if (existing) return existing;
            if (!this.#targetEdits.has(path)) this.#targetEdits.set(path, uid('qt'));
            return this.#targetEdits.get(path);
        }
        #pick(step) {
            this.#picking = step; this.#dialog.close(); this.#banner.hidden = false;
            this.#editor.canvas.setPickHandler(path => {
                step.target = { type: 'element', value: this.#targetId(path) }; this.#stopPicking();
            });
        }
        #stopPicking() {
            this.#editor.canvas.setPickHandler(null); this.#picking = null; this.#banner.hidden = true;
            if (this.#draft) { this.#renderSteps(); this.#dialog.showModal(); }
        }
        #clearMotionPreviews() { this.#motionPreviews.splice(0).forEach(stop => stop()); }
        #animationControls(container, step) {
            const group = make('div', 'editor-action-animation');
            const defaults = () => ({ effect: 'none', duration: 250, delay: 0, easing: 'smooth', direction: 'up', distance: 24 });
            const settings = step.operation.animation || defaults();
            const renderSettings = (parent, value, prefix, label, save) => {
                const controls = make('div', 'editor-motion-settings');
                const effect = this.#select(controls, label, value.effect, { none: 'None', fade: 'Fade', slide: 'Slide', scale: 'Scale' }, effect => { value.effect = effect; save(); this.#renderSteps(); }, `${prefix}-effect`);
                effect.dataset.motionEffect = prefix;
                if (value.effect !== 'none') {
                    const grid = make('div', 'editor-motion-grid');
                    for (const [key,title,max,fallback] of [['duration','Duration (ms)',10000,250],['delay','Delay (ms)',10000,0]]) {
                        const input = this.#input(grid,title,value[key] ?? fallback,raw => { value[key] = raw === '' ? fallback : Number(raw); save(); }, `${prefix}-${key}`);
                        input.value = String(value[key] ?? fallback); input.type = 'number'; input.min = '0'; input.max = String(max); input.step = '10';
                    }
                    controls.append(grid);
                    this.#select(controls, 'Motion', value.easing || 'smooth', {smooth:'Smooth',gentle:'Gentle',spring:'Spring',linear:'Steady'}, easing => { value.easing = easing; save(); }, `${prefix}-easing`);
                    if (value.effect === 'slide') {
                        this.#select(controls, 'Direction', value.direction || 'up', {up:'Up',down:'Down',left:'Left',right:'Right'}, direction => { value.direction = direction; save(); }, `${prefix}-direction`);
                        const distance = this.#input(controls,'Travel distance (px)',value.distance ?? 24,raw => { value.distance = raw === '' ? 24 : Number(raw); save(); },`${prefix}-distance`);
                        distance.value = String(value.distance ?? 24); distance.type = 'number'; distance.min = '0'; distance.max = '500';
                    }
                }
                parent.append(controls);
            };
            renderSettings(group,settings,`${step.id}-animation`,step.operation.type === 'toggle-visibility' ? 'Appear' : 'Animation',() => { step.operation.animation = settings; });
            if (step.operation.type === 'toggle-visibility') {
                const label = make('label','editor-motion-link');
                const same = make('input'); same.type = 'checkbox'; same.checked = !settings.exit; same.dataset.motionSame = step.id;
                label.append(same,document.createTextNode('Use the same effect when hiding')); group.append(label);
                same.addEventListener('change',() => {
                    step.operation.animation = settings;
                    if (same.checked) delete settings.exit;
                    else { const {exit,...enter} = settings; settings.exit = structuredClone(enter); }
                    this.#renderSteps();
                });
                if (settings.exit) renderSettings(group,settings.exit,`${step.id}-animation-exit`,'Disappear',() => { step.operation.animation = settings; });
            }
            if (settings.effect !== 'none' || settings.exit?.effect && settings.exit.effect !== 'none') {
                const preview = make('div','editor-motion-preview');
                const button = this.#textButton('Preview animation',() => {
                    try {
                        stop?.();
                        if (step.operation.type !== 'toggle-visibility') { sample.style.cssText = ''; sample.hidden = step.operation.type === 'show'; }
                        const action = {id:'qa-motion-preview',name:'Animation preview',trigger:'click',steps:[{id:'qs-motion-preview',target:{type:'selector',value:'.editor-motion-preview-card'},condition:null,operation:{type:step.operation.type,animation:structuredClone(step.operation.animation)}}]};
                        stop = window.QuillActions.mount(preview,{actions:[action]});
                        this.#error.hidden = true;
                    } catch(error) { this.#error.textContent = error.message; this.#error.hidden = false; }
                });
                button.dataset.quillAction = 'qa-motion-preview';
                const sample = make('div','editor-motion-preview-card','Preview'); sample.hidden = true; sample.setAttribute('aria-hidden','true');
                const stage = make('div','editor-motion-preview-stage');stage.append(sample);preview.append(button,stage);group.append(preview);
                let stop; this.#motionPreviews.push(() => stop?.());
                group.append(make('p','editor-clickable-hint','Preview uses a sample. Your page stays unchanged. Motion is reduced when the visitor requests it.'));
            }
            container.append(group);
        }
        #renderSteps() {
            this.#clearMotionPreviews();
            this.#steps.replaceChildren();
            document.getElementById('editor-action-add-step').disabled = this.#draft.steps.length >= 50;
            this.#draft.steps.forEach((step, index) => {
                const card = make('section', 'editor-action-step'); card.dataset.stepId = step.id;
                const header = make('header', 'editor-action-step-header'); header.append(make('h3', '', `Step ${index + 1}`));
                const buttons = make('div', 'editor-action-step-buttons');
                const move = delta => { const steps = this.#draft.steps; [steps[index], steps[index + delta]] = [steps[index + delta], steps[index]]; this.#renderSteps(); };
                const up = this.#button('Move step up', 'arrow-up', () => move(-1)); up.disabled = index === 0;
                const down = this.#button('Move step down', 'arrow-down', () => move(1)); down.disabled = index === this.#draft.steps.length - 1;
                const copy = this.#button('Duplicate step', 'copy', () => { if (this.#draft.steps.length < 50) { const clone = structuredClone(step); clone.id = uid('qs'); this.#draft.steps.splice(index + 1, 0, clone); this.#renderSteps(); } });
                copy.disabled = this.#draft.steps.length >= 50;
                buttons.append(up, down, copy, this.#button('Remove step', 'trash-can', () => { this.#draft.steps.splice(index, 1); this.#renderSteps(); }));
                header.append(buttons); card.append(header);
                const target = make('div', 'editor-action-node');
                target.append(make('h4', 'editor-action-section-label', 'Target'));
                this.#select(target, 'Apply to', step.target.type, { self: 'Clicked element', parent: 'Parent element', element: 'Choose an element', selector: 'CSS selector (advanced)' }, value => { step.target = { type: value }; this.#renderSteps(); }, `${step.id}-target`);
                if (step.target.type === 'element') {
                    const elements = this.#elements(); const current = elements.find(element => element.id && element.id === step.target.value);
                    this.#select(target, 'Element', current?.path || '', { '': step.target.value ? 'Target no longer on this page' : 'Choose an element…', ...Object.fromEntries(elements.map(element => [element.path, element.label])) }, path => {
                        if (path) step.target.value = this.#targetId(path);
                        else delete step.target.value;
                    }, `${step.id}-element`);
                    target.append(this.#textButton('Choose on canvas', () => this.#pick(step)));
                }
                if (step.target.type === 'selector') this.#input(target, 'CSS selector', step.target.value, value => { step.target.value = value; }, `${step.id}-selector`);
                card.append(target);
                if (step.condition) {
                    const condition = make('div', 'editor-action-node editor-action-condition');
                    condition.append(make('h4', 'editor-action-section-label', 'Condition'));
                    condition.append(this.#button('Remove condition', 'xmark', () => { step.condition = null; this.#renderSteps(); }));
                    this.#select(condition, 'Only if', step.condition.type, { 'has-class': 'Target has class', 'missing-class': 'Target does not have class', visible: 'Target is visible', hidden: 'Target is hidden', viewport: 'Viewport is' }, value => { step.condition = { type: value }; if (value === 'viewport') step.condition.value = 'desktop'; this.#renderSteps(); }, `${step.id}-condition`);
                    if (['has-class', 'missing-class'].includes(step.condition.type)) this.#input(condition, 'Class name', step.condition.value, value => { step.condition.value = value; }, `${step.id}-condition-value`);
                    if (step.condition.type === 'viewport') this.#select(condition, 'Viewport', step.condition.value, { desktop: 'Desktop', tablet: 'Tablet', mobile: 'Mobile' }, value => { step.condition.value = value; }, `${step.id}-condition-value`);
                    card.append(condition);
                } else card.append(this.#textButton('Add condition', () => { step.condition = { type: 'has-class', value: '' }; this.#renderSteps(); }));
                const operation = make('div', 'editor-action-node');
                operation.append(make('h4', 'editor-action-section-label', 'Action'));
                this.#select(operation, 'Then', step.operation.type, operationNames, value => { step.operation = { type: value }; if (value.endsWith('style')) step.operation.property = 'background-color'; this.#renderSteps(); }, `${step.id}-operation`);
                if (step.operation.type === 'toggle-visibility') operation.append(make('p', 'editor-clickable-hint', 'Shows a hidden element or hides a visible one each time. No visibility condition is needed.'));
                if (['set-style', 'reset-style'].includes(step.operation.type)) {
                    const properties = { 'background-color': 'Background color', color: 'Text color', display: 'Display', opacity: 'Opacity', width: 'Width', height: 'Height', padding: 'Padding', margin: 'Margin', 'border-radius': 'Corner radius', transform: 'Transform' };
                    this.#select(operation, 'Style', Object.hasOwn(properties, step.operation.property) ? step.operation.property : 'custom', { ...properties, custom: 'Custom CSS property' }, value => { step.operation.property = value === 'custom' ? '' : value; this.#renderSteps(); }, `${step.id}-property`);
                    if (!Object.hasOwn(properties, step.operation.property)) this.#input(operation, 'CSS property', step.operation.property, value => { step.operation.property = value; }, `${step.id}-custom-property`);
                }
                if (step.operation.type === 'set-style') {
                    const value = this.#input(operation, 'Value', step.operation.value, value => { step.operation.value = value; }, `${step.id}-value`); value.placeholder = 'e.g. #2ecc71 or 24px';
                    if (step.operation.property?.includes('color')) {
                        const row = make('div', 'editor-action-color-value');
                        const color = make('input', 'editor-color-swatch'); color.type = 'text'; color.setAttribute('data-coloris', ''); color.setAttribute('aria-label', 'Choose color'); color.spellcheck = false;
                        color.value = CSS.supports('color', step.operation.value || '') ? step.operation.value : '#2ecc71';
                        const update = selected => { color.value = selected; color.style.backgroundColor = selected || 'transparent'; color.title = selected || 'Choose color'; window.QuillEditorColorPicker.update(color); };
                        update(color.value);
                        color.addEventListener('input', () => { value.value = color.value; step.operation.value = color.value; update(color.value); });
                        value.addEventListener('input', () => { if (CSS.supports('color', value.value)) update(value.value); });
                        value.replaceWith(row); row.append(window.QuillEditorColorPicker.wrap(color), value);
                        row.append(this.#editor.palette.button({current:()=>step.operation.value || color.value,apply:selected=>{value.value=selected;step.operation.value=selected;update(selected);},label:'Saved colors: Action color'}));
                    }
                }
                if (['add-class', 'remove-class', 'toggle-class'].includes(step.operation.type)) this.#input(operation, 'Class name', step.operation.value, value => { step.operation.value = value; }, `${step.id}-value`);
                if (['show','hide','toggle-visibility'].includes(step.operation.type)) this.#animationControls(operation,step);
                card.append(operation); this.#steps.append(card);
            });
        }
        #save() {
            try {
                if (this.#revision !== this.#editor.state.revision) throw new Error('The document changed while this action was open. Cancel and reopen the action to use the current elements.');
                const draft = structuredClone(this.#draft); draft.name = this.#name.value.trim();
                window.QuillActions.validate([draft], document.getElementById('editor-canvas').contentDocument);
                const actions = this.actions.filter(action => action.id !== draft.id); actions.push(draft);
                window.QuillActions.validate(actions, document.getElementById('editor-canvas').contentDocument);
                const usedTargets = new Set(draft.steps.filter(step => step.target.type === 'element').map(step => step.target.value));
                const patches = [...this.#targetEdits].filter(([, id]) => usedTargets.has(id)).map(([path, id]) => ({ path, editor: { actionTargetId: id } }));
                if (this.#bindPath !== null) patches.push({ path: this.#bindPath, editor: { [window.QuillActions.bindings[draft.trigger]]: draft.id } });
                this.#editor.state.updateActions(actions, patches); this.#dialog.close(); this.#editor.reportError(null);
            } catch (error) { this.#error.textContent = error.message; this.#error.hidden = false; }
        }
        #renderList() {
            const container = document.getElementById('editor-actions-list'); container.replaceChildren();
            if (!this.actions.length) container.append(make('p', 'editor-clickable-hint', 'No actions yet. Create an action and assign it to a clickable block.'));
            for (const action of this.actions) {
                const row = make('div', 'editor-action-list-row'); row.dataset.actionId = action.id;
                const name = this.#textButton(action.name, () => this.open(action.id)); name.className = 'editor-action-list-name';
                name.append(make('small', 'editor-action-trigger-label', window.QuillActions.triggers[action.trigger]));
                row.append(name, this.#button('Duplicate action', 'copy', () => this.open(action.id, null, true)), this.#button('Delete action', 'trash-can', () => this.remove(action.id))); container.append(row);
            }
        }
    }
    window.QuillEditorActions = QuillEditorActions;
})();
