(() => {
    'use strict';
    const make = (tag, className = '', text = '') => { const node = document.createElement(tag); node.className = className; node.textContent = text; return node; };
    const triggers = { load: 'Page load', click: 'Click', hover: 'Hover', focus: 'Focus' };
    class QuillAnimationLibrary extends EventTarget {
        #editor; #url; #items = []; #loaded = false;
        constructor(editor, bootstrap) { super(); this.#editor = editor; this.#url = bootstrap.animationUrl; }
        all() { return structuredClone(this.#items).sort((a,b) => a.name.localeCompare(b.name)); }
        #request(suffix = '', fields = null) { return window.QuillLibraryClient.request(this.#url + suffix, fields); }
        #remember(item) {
            if (!Number.isSafeInteger(item?.id) || item.id < 1 || typeof item.name !== 'string' || !Number.isSafeInteger(item.version)) throw new Error('Invalid animation library response.');
            this.#items = [...this.#items.filter(value => value.id !== item.id), item]; this.dispatchEvent(new Event('change')); return item;
        }
        async refresh() {
            const data = await this.#request();
            if (!Array.isArray(data?.animations)) throw new Error('Invalid animation library response.');
            this.#items = data.animations; this.#loaded = true; this.dispatchEvent(new Event('change')); return this.all();
        }
        async get(id) {
            const { animation } = await this.#request('/' + id);
            window.QuillAnimations.validate({ [animation?.definition?.id]: animation?.definition });
            return this.#remember(animation);
        }
        async save(definition, original = null, mode = 'new') {
            window.QuillAnimations.validate({ [definition.id]: definition });
            const fields = { definition: JSON.stringify(definition) };
            if (original && mode === 'replace') fields.lock_version = original.version;
            const { animation } = await this.#request(original && mode === 'replace' ? '/' + original.id : '', fields);
            return this.#remember(animation);
        }
        #ask(title, message, choices) { return window.QuillLibraryDialog.ask(title, message, choices, 'editor-animation-confirm'); }
        chooseSave(item) {
            return this.#ask('Save animation', `Replace “${item.name}” everywhere it is used, or save a new animation and use it on this element.`, [['cancel','Cancel'],['new','Save as new'],['replace','Replace','button-primary']]);
        }
        async delete(item) {
            if (await this.#ask('Delete animation?', `Are you sure you want to delete “${item.name}”? Elements referencing it will remain visible and will no longer play this animation.`, [['cancel','Cancel'],['delete','Delete','editor-animation-delete']]) !== 'delete') return;
            await this.#request('/' + item.id + '/delete', { lock_version: item.version });
            this.#items = this.#items.filter(value => value.id !== item.id); this.dispatchEvent(new Event('change'));
        }
        mount(container, signal) {
            const editor = this.#editor;
            const active = make('div','editor-animation-active'); active.setAttribute('role','listbox'); active.setAttribute('aria-label','Active animations'); active.tabIndex = 0;
            const toolbar = make('div','editor-animation-tools'); toolbar.setAttribute('role','group'); toolbar.setAttribute('aria-label','Manage element animations');
            const button = (text, label, data) => { const node = window.QuillEditorIcon.set(make('button','editor-action-icon'), text); node.type = 'button'; node.title = label; node.setAttribute('aria-label',label); node.dataset[data] = ''; toolbar.append(node); return node; };
            const plus = button('plus','Add animation','animationAdd'), minus = button('minus','Remove selected animation from this element','animationRemove'), edit = button('pen','Edit selected animation','animationEdit');
            const trigger = make('select'); trigger.setAttribute('aria-label','Selected animation trigger'); for (const [value,label] of Object.entries(triggers)) trigger.add(new Option(label,value)); toolbar.append(trigger);
            const picker = make('div','editor-animation-picker'); picker.popover = 'auto'; picker.role = 'dialog'; picker.setAttribute('aria-label','Animation library');
            const top = make('div','editor-animation-picker-heading'); top.append(make('h3','','Animation library'));
            const create = make('button','','New animation'); create.type = 'button'; create.dataset.animationNew = ''; top.append(create);
            const search = make('input'); search.type = 'search'; search.placeholder = 'Search animations'; search.setAttribute('aria-label','Search animations');
            const choices = make('div','editor-animation-choices'), error = make('p','editor-action-error'); error.role = 'alert'; error.hidden = true;
            picker.append(top,search,choices,error); document.body.append(picker); container.append(active,toolbar);
            let selected = null, path = null, alive = true;
            const attempt = async callback => { try { error.hidden = true; await callback(); } catch (failure) { if (alive) { error.textContent = failure.message; error.hidden = false; editor.reportError(failure); } } };
            const references = () => editor.getSelectedBlock()?.animations || [];
            const legacy = () => {
                if (!editor.selectedPath) return [];
                const [section,...indices] = editor.selectedPath.split('.').map(Number), doc = editor.getDocument();
                return (doc.settings?.animation_bindings || []).filter(binding => binding.target.section === doc.sections[section]?.name && JSON.stringify(binding.target.path) === JSON.stringify(indices));
            };
            const open = id => { picker.hidePopover(); return editor.ui.animations.open(id); };
            const renderChoices = () => {
                choices.replaceChildren();
                const items = this.all().filter(item => item.name.toLowerCase().includes(search.value.toLowerCase()));
                const pageAnimations = Object.values(editor.getDocument().settings?.animations || {}).filter(item => item.name.toLowerCase().includes(search.value.toLowerCase()));
                if (!items.length && !pageAnimations.length) choices.append(make('p','studio-hint',this.#loaded ? 'No matching animations. Create a new animation to get started.' : 'Loading animations…'));
                for (const item of items) {
                    const row = make('div','editor-animation-choice');
                    const use = make('button','',item.name); use.type = 'button'; use.dataset.animationUse = item.id;
                    use.disabled = references().some(binding => binding.animation_id === item.id); use.title = use.disabled ? 'Already used on this element' : 'Use this animation on the selected element';
                    use.addEventListener('click', () => attempt(() => { editor.state.setAnimationBindings(editor.selectedPath,[...references(),{animation_id:item.id,trigger:'load'}]); selected = item.id; picker.hidePopover(); render(); }));
                    const modify = window.QuillEditorIcon.set(make('button', 'editor-action-icon'), 'pen'); modify.type = 'button'; modify.title = `Edit ${item.name}`; modify.setAttribute('aria-label',modify.title); modify.dataset.animationLibraryEdit = item.id; modify.addEventListener('click', () => attempt(() => open(item.id)));
                    const remove = window.QuillEditorIcon.set(make('button', 'editor-action-icon'), 'xmark'); remove.type = 'button'; remove.title = `Delete ${item.name}`; remove.setAttribute('aria-label',remove.title); remove.dataset.animationDelete = item.id; remove.addEventListener('click', () => attempt(() => this.delete(item)));
                    row.append(use,modify,remove); choices.append(row);
                }
                if (pageAnimations.length) choices.append(make('h3','studio-hint','Page animations · Legacy'));
                for (const animation of pageAnimations) {
                    const row=make('div','editor-animation-choice'),use=make('button','',animation.name);use.type='button';use.dataset.legacyAnimationUse=animation.id;
                    use.disabled=legacy().some(binding=>binding.animationId===animation.id);
                    use.addEventListener('click',()=>attempt(()=>{
                        const document=editor.getDocument(),[section,...indices]=editor.selectedPath.split('.').map(Number),binding={id:'qbind-'+crypto.randomUUID(),animationId:animation.id,target:{section:document.sections[section].name,path:indices},trigger:'load'};
                        editor.state.updateSettings({animation_bindings:[...(document.settings?.animation_bindings || []),binding]});selected=binding.id;picker.hidePopover();render();
                    }));
                    const modify=window.QuillEditorIcon.set(make('button', 'editor-action-icon'), 'pen');modify.type='button';modify.setAttribute('aria-label',`Edit ${animation.name}`);modify.title='Edit and save to the shared library';modify.addEventListener('click',()=>attempt(()=>open(animation.id)));row.append(use,modify);choices.append(row);
                }
            };
            const render = () => {
                if (!alive) return;
                if (path !== editor.selectedPath) { path = editor.selectedPath; selected = null; }
                const bindings = references(), oldBindings = legacy();
                if (!bindings.some(binding => binding.animation_id === selected) && !oldBindings.some(binding => binding.id === selected)) selected = null;
                active.replaceChildren();
                for (const binding of [...bindings,...oldBindings]) {
                    const id = binding.animation_id ?? binding.id, item = this.#items.find(item => item.id === id), old = editor.getDocument().settings?.animations?.[binding.animationId];
                    const option = make('button','editor-animation-active-item'); option.type = 'button'; option.role = 'option'; option.dataset.activeAnimation = id;
                    option.setAttribute('aria-selected',String(selected === id));
                    option.append(make('span','',item?.name || old?.name || `Animation #${id}${this.#loaded ? ' · Unavailable' : ''}`),make('small','',triggers[binding.trigger]));
                    option.addEventListener('click', () => { selected = id; render(); });
                    option.addEventListener('dblclick', () => attempt(() => open(binding.animation_id ?? binding.animationId))); active.append(option);
                }
                if (!bindings.length && !oldBindings.length) active.append(make('p','studio-hint','No animations applied. Choose Add animation to get started.'));
                minus.disabled = edit.disabled = trigger.disabled = selected === null;
                const binding = bindings.find(binding => binding.animation_id === selected) || oldBindings.find(binding => binding.id === selected);
                if (binding) trigger.value = binding.trigger;
                renderChoices();
            };
            active.addEventListener('keydown', event => {
                if (!['ArrowDown','ArrowUp','Home','End','Enter'].includes(event.key)) return;
                const options = [...active.querySelectorAll('[role=option]')]; if (!options.length) return;
                event.preventDefault(); let index = options.findIndex(option => option.getAttribute('aria-selected') === 'true');
                if (event.key === 'Enter') { if (selected !== null) edit.click(); return; }
                index = event.key === 'Home' ? 0 : event.key === 'End' ? options.length-1 : (index + (event.key === 'ArrowUp' ? -1 : 1) + options.length) % options.length;
                options[index].click(); active.querySelector('[aria-selected=true]')?.focus();
            },{signal});
            plus.addEventListener('click', () => attempt(async () => {
                if (picker.matches(':popover-open')) { picker.hidePopover(); return; }
                search.value = ''; renderChoices(); picker.showPopover();
                const rect = plus.getBoundingClientRect(), width = Math.min(320,innerWidth-24);
                picker.style.width = width + 'px'; picker.style.left = Math.max(12,rect.left-width-12) + 'px'; picker.style.top = Math.max(12,Math.min(rect.top,innerHeight-picker.offsetHeight-12)) + 'px';
                search.focus(); await this.refresh();
            }),{signal});
            picker.addEventListener('toggle', () => plus.setAttribute('aria-expanded',String(picker.matches(':popover-open'))),{signal}); plus.setAttribute('aria-haspopup','dialog'); plus.setAttribute('aria-expanded','false');
            create.addEventListener('click', () => attempt(() => open()),{signal});
            minus.addEventListener('click', () => attempt(() => {
                if (typeof selected === 'number') editor.state.setAnimationBindings(editor.selectedPath,references().filter(binding => binding.animation_id !== selected));
                else editor.state.updateSettings({animation_bindings:(editor.getDocument().settings?.animation_bindings || []).filter(binding => binding.id !== selected)});
                selected = null; render();
            }),{signal});
            edit.addEventListener('click', () => attempt(() => open(typeof selected === 'number' ? selected : legacy().find(binding => binding.id === selected)?.animationId)),{signal});
            trigger.addEventListener('change', () => attempt(() => {
                if (typeof selected === 'number') editor.state.setAnimationBindings(editor.selectedPath,references().map(binding => binding.animation_id === selected ? {...binding,trigger:trigger.value} : binding));
                else editor.state.updateSettings({animation_bindings:(editor.getDocument().settings?.animation_bindings || []).map(binding => binding.id === selected ? {...binding,trigger:trigger.value} : binding)});
            }),{signal});
            search.addEventListener('input',renderChoices,{signal}); this.addEventListener('change',render,{signal});
            const unsubscribe = editor.on('change', render); signal.addEventListener('abort', unsubscribe, {once:true});
            if (!this.#loaded) this.refresh().catch(failure => { if (alive) { error.textContent = failure.message; error.hidden = false; } });
            signal.addEventListener('abort', () => { alive = false; picker.remove(); },{once:true}); render();
            return { update:render };
        }
    }
    window.QuillAnimationLibrary = QuillAnimationLibrary;
})();
