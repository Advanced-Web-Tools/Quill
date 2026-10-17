(() => {
    'use strict';
    const copy = value => structuredClone(value);
    const make = (tag, cls = '', text = '') => { const node = document.createElement(tag); node.className = cls; node.textContent = text; return node; };
    const propertyAssetBase = new URL('./editor/properties/', document.currentScript.src).href;
    const key = reference => reference ? `${reference.scope}:${reference.id}` : null;
    const STYLE_ID = 'quill-editor-palette-overrides';
    const injectStyles = () => {
        if (document.getElementById(STYLE_ID)) return;
        const style = make('style');
        style.id = STYLE_ID;
        style.textContent = `
        .editor-palette-dialog {
            height: auto;
            max-height: calc(100dvh - 2rem);
            overflow: visible;
        }
        .editor-palette-dialog .editor-palette-list {
            max-height: min(40vh, 320px);
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 4px;
            scrollbar-width: thin;
            scrollbar-color: var(--editor-line, #3a3f4b) transparent;
        }
        .editor-palette-dialog .editor-palette-naming {
            padding: 4px;
        }
        .editor-palette-dialog .editor-palette-naming input {
            outline-offset: -2px;
        }
        .editor-palette-dialog .editor-palette-naming input:focus,
        .editor-palette-dialog .editor-palette-naming input:focus-visible {
            outline-offset: -2px;
        }
    `;
        document.head.append(style);
    };
    class QuillEditorPalette extends EventTarget {
        #state; #dialog = null; #target = null; #cleanup = null; #render = null; #edit = null;
        #selected = null; #globals = []; #externals = []; #url = null; #isolated = false; #loading = null; #busy = false;
        constructor(state, options = {}) {
            super(); this.#state = state; this.configure(options);
            const signature = () => { const settings=state.getDocument().settings || {}; return JSON.stringify([settings.color_palette,settings.color_palettes,settings.default_color_palette]); };
            let previous = signature();
            state.addEventListener('change', () => { const next=signature(); if(next!==previous){previous=next;this.#render?.();this.dispatchEvent(new Event('change'));} });
            state.addEventListener('selectionchange', () => this.close(true));
        }
        static isGradient(value) { return /^(?:linear|radial|conic)-gradient\(/i.test(value.trim()); }
        static validate(palette) {
            if (!Array.isArray(palette) || palette.length > 100) throw new Error('Use at most 100 saved colors.');
            const ids = new Set(), names = new Set();
            for (const entry of palette) {
                if (!entry || Array.isArray(entry) || Object.keys(entry).some(key => !['id','name','value'].includes(key))
                    || typeof entry.id !== 'string' || !/^qp-[A-Za-z0-9_-]{1,80}$/.test(entry.id) || ids.has(entry.id)
                    || typeof entry.name !== 'string' || !entry.name.trim() || new TextEncoder().encode(entry.name).length > 128 || names.has(entry.name.trim().toLowerCase())
                    || typeof entry.value !== 'string' || !entry.value.trim() || entry.value.length > 2048) throw new Error('Enter a valid, uniquely named saved color.');
                const value = entry.value.trim();
                if (/[{};<>\x00-\x1f\x7f]|\/\*|\*\/|(?:url|var)\s*\(/i.test(value)
                    || /^(?:inherit|initial|unset|revert|revert-layer)$/i.test(value)
                    || !(CSS.supports('color', value) || (this.isGradient(value) && CSS.supports('background-image', value)))) throw new Error('Choose a color or a gradient.');
                ids.add(entry.id); names.add(entry.name.trim().toLowerCase());
            }
        }
        static validateCollections(settings) {
            const palettes = settings.color_palettes ?? [], ids = new Set(), names = new Set();
            if (!Array.isArray(palettes) || palettes.length > 20) throw new Error('Use at most 20 local palettes.');
            for (const palette of palettes) {
                if (!palette || Array.isArray(palette) || Object.keys(palette).some(name=>!['id','name','colors'].includes(name)) || typeof palette.id !== 'string' || !/^qpal-[A-Za-z0-9_-]{1,80}$/.test(palette.id) || palette.id==='qpal-legacy' || ids.has(palette.id)
                    || typeof palette.name!=='string' || !palette.name.trim() || new TextEncoder().encode(palette.name).length>255 || names.has(palette.name.trim().toLowerCase())) throw new Error('Use distinct names and identities for local palettes.');
                this.validate(palette.colors); ids.add(palette.id); names.add(palette.name.trim().toLowerCase());
            }
            const reference = settings.default_color_palette;
            if (reference == null) return;
            if (!reference || Array.isArray(reference) || Object.keys(reference).some(name=>!['scope','id'].includes(name)) || !['local','global','external'].includes(reference.scope)
                || (reference.scope==='global' ? !Number.isSafeInteger(reference.id)||reference.id<1 : reference.scope==='external' ? typeof reference.id!=='string'||!/^[A-Za-z][A-Za-z0-9_.:-]*$/.test(reference.id) : typeof reference.id!=='string'||(!ids.has(reference.id)&&!(reference.id==='qpal-legacy'&&Array.isArray(settings.color_palette))))) throw new Error('Choose an available default palette.');
        }
        configure({url, globalPalettes, externalPalettes, isolated} = {}) {
            if (url !== undefined) this.#url = url;
            if (globalPalettes) this.#globals = copy(globalPalettes);
            if (externalPalettes) this.#externals = copy(externalPalettes);
            if (isolated !== undefined) this.#isolated = isolated;
        }
        get libraryUrl() { return this.#url; }
        globals() { return copy(this.#globals); }
        externals() { return copy(this.#externals); }
        locals() {
            const settings = this.#state.getDocument().settings || {}, palettes = copy(settings.color_palettes || []);
            if (settings.color_palette?.length) palettes.unshift({id:'qpal-legacy',name:'Page colors',colors:copy(settings.color_palette)});
            return palettes;
        }
        collections() { return [...this.locals().map(palette=>({...palette,scope:'local'})),...this.globals().map(palette=>({...palette,scope:'global'})),...copy(this.#externals).map(palette=>({...palette,scope:'external'}))]; }
        defaultPalette() { return copy(this.#state.getDocument().settings?.default_color_palette || null); }
        colors(reference = this.defaultPalette()) { return this.collections().find(palette=>key(palette)===key(reference))?.colors || []; }
        // Legacy flat-palette API remains compatible with existing package controls.
        list() { return this.#state.getDocument().settings?.color_palette || []; }
        save(name, value, id = null) {
            const colors = this.list(), entry = {id:id||'qp-'+crypto.randomUUID(),name:name.trim(),value:value.trim()}, index=colors.findIndex(color=>color.id===entry.id);
            if(id&&index<0)throw new Error('This saved color no longer exists.');
            if(index<0)colors.push(entry);else colors[index]=entry;
            QuillEditorPalette.validate(colors);this.#state.updateSettings({color_palette:colors});return entry;
        }
        remove(id) { this.#state.updateSettings({color_palette:this.list().filter(entry=>entry.id!==id)}); }
        #write(changes) {
            const settings = {...(this.#state.getDocument().settings || {}),...changes}; QuillEditorPalette.validateCollections(settings); this.#state.updateSettings(changes);
        }
        createLocal(name, colors = []) {
            const settings=this.#state.getDocument().settings || {}, palette={id:'qpal-'+crypto.randomUUID(),name:name.trim(),colors:copy(colors)};
            if(this.locals().some(item=>item.name.toLowerCase()===palette.name.toLowerCase()))throw new Error('Use a distinct palette name.');
            this.#write({color_palettes:[...(settings.color_palettes || []),palette]});return {...palette,scope:'local'};
        }
        makeDefault(reference) {
            if(this.#isolated)throw new Error('Page palette settings are unavailable in Animation Studio.');
            const palette=this.collections().find(palette=>key(palette)===key(reference));if(!palette)throw new Error('This palette is unavailable.');
            this.#write({default_color_palette:{scope:palette.scope,id:palette.id}});
        }
        async refresh() {
            if(!this.#url)return this.globals();
            if(this.#loading)return this.#loading;
            this.#loading=(async()=>{
                const result=await window.QuillLibraryClient.request(this.#url);
                if(!Array.isArray(result?.palettes))throw new Error('Invalid global palette response.');
                for(const palette of result.palettes){if(!Number.isSafeInteger(palette.id)||palette.id<1||typeof palette.name!=='string'||!Number.isSafeInteger(palette.version))throw new Error('Invalid global palette response.');QuillEditorPalette.validate(palette.colors);}
                const external=result.externalPalettes || [];
                if(!Array.isArray(external))throw new Error('Invalid external palette response.');
                for(const palette of external){if(typeof palette.id!=='string'||typeof palette.name!=='string'||typeof palette.provider!=='string')throw new Error('Invalid external palette response.');QuillEditorPalette.validate(palette.colors);}
                this.#externals=copy(external);this.#globals=copy(result.palettes);this.#render?.();this.dispatchEvent(new Event('change'));return this.globals();
            })();
            try{return await this.#loading;}finally{this.#loading=null;}
        }
        async #saveGlobal(definition, original = null) {
            if(this.#isolated)throw new Error('Global palettes are read-only in Animation Studio.');
            if(!this.#url)throw new Error('The global palette library is unavailable in this context.');
            this.#state.assertAvailable?.();QuillEditorPalette.validate(definition.colors);
            let mode='new';
            if(original)mode=await window.QuillLibraryDialog.ask('Save global palette',`Replace “${original.name}” in the shared library, or save a new global palette. Existing element colors stay unchanged.`,[['cancel','Cancel'],['new','Save as new'],['replace','Replace','button-primary']]);
            if(!mode||mode==='cancel')return null;
            this.#state.assertAvailable?.();
            const fields={definition:JSON.stringify(definition)};if(original&&mode==='replace')fields.lock_version=original.version;
            const {palette}=await window.QuillLibraryClient.request(this.#url+(original&&mode==='replace'?'/'+original.id:''),fields);
            if(!Number.isSafeInteger(palette?.id)||palette.id<1||typeof palette.name!=='string')throw new Error('Invalid global palette response.');
            QuillEditorPalette.validate(palette.colors);
            this.#globals=[...this.#globals.filter(item=>item.id!==palette.id),palette];this.dispatchEvent(new Event('change'));return {...palette,scope:'global'};
        }
        async makeGlobal(reference) {
            const palette=this.collections().find(palette=>key(palette)===key(reference));if(!palette||palette.scope!=='local')throw new Error('Choose a local palette.');
            const before=JSON.stringify(palette), defaultBefore=JSON.stringify(this.defaultPalette());
            const global=await this.#saveGlobal({name:palette.name,colors:palette.colors});if(!global)return null;
            this.#state.assertAvailable?.();
            const current=this.collections().find(item=>key(item)===key(palette));
            if(current&&JSON.stringify(current)===before){
                const changes=palette.id==='qpal-legacy'?{color_palette:[]}:{color_palettes:this.locals().filter(item=>item.id!==palette.id&&item.id!=='qpal-legacy').map(({scope,...item})=>item)};
                if(key(this.defaultPalette())===key(palette)&&JSON.stringify(this.defaultPalette())===defaultBefore)changes.default_color_palette={scope:'global',id:global.id};
                this.#write(changes);
            }
            this.#selected=key(global);this.#render?.();return global;
        }
        async #updatePalette(palette, name, colors) {
            QuillEditorPalette.validate(colors);
            if(palette.scope==='external')throw new Error('External palettes are managed by their provider.');
            if(palette.scope==='global'){
                const saved=await this.#saveGlobal({name:name.trim(),colors},palette);if(!saved)return false;
                this.#selected=key(saved);this.#render?.();return true;
            }
            const settings=this.#state.getDocument().settings || {};
            if(palette.id==='qpal-legacy'&&name===palette.name){this.#state.updateSettings({color_palette:colors});return true;}
            const id=palette.id==='qpal-legacy'?'qpal-'+crypto.randomUUID():palette.id;
            const changed={id,name:name.trim(),colors}, palettes=(settings.color_palettes || []).filter(item=>item.id!==id);
            const changes={color_palettes:[...palettes,changed]};
            if(palette.id==='qpal-legacy'){changes.color_palette=[];if(key(this.defaultPalette())===key(palette))changes.default_color_palette={scope:'local',id};}
            this.#selected=key({scope:'local',id});this.#write(changes);return true;
        }
        async #deletePalette(palette) {
            if(this.#isolated||palette?.scope==='external')return;
            if(await window.QuillLibraryDialog.ask('Delete palette?',`Are you sure you want to delete “${palette.name}”? Existing element colors will stay unchanged.`,[['cancel','Cancel'],['delete','Delete','editor-library-delete']])!=='delete')return;
            this.#state.assertAvailable?.();
            if(palette.scope==='global'){
                await window.QuillLibraryClient.request(this.#url+'/'+palette.id+'/delete',{lock_version:palette.version});this.#globals=this.#globals.filter(item=>item.id!==palette.id);
                if(key(this.defaultPalette())===key(palette))this.#write({default_color_palette:null});
                this.dispatchEvent(new Event('change'));
            }else{
                const changes=palette.id==='qpal-legacy'?{color_palette:[]}:{color_palettes:this.locals().filter(item=>item.id!==palette.id&&item.id!=='qpal-legacy').map(({scope,...item})=>item)};
                if(key(this.defaultPalette())===key(palette))changes.default_color_palette=null;
                this.#write(changes);
            }
            this.#selected=null;this.#render?.(true);
        }
        #swatch(value) {const swatch=make('span','editor-palette-swatch');swatch.setAttribute('aria-hidden','true');if(QuillEditorPalette.isGradient(value))swatch.style.backgroundImage=value;else swatch.style.backgroundColor=value;return swatch;}
        button({current,apply,signal,gradients=false,label='Saved colors'}) {
            const button=make('button','editor-palette-button');button.type='button';button.title=label;button.setAttribute('aria-label',label);button.dataset.paletteButton=label;
            const icon=make('i','fa-solid fa-swatchbook');icon.setAttribute('aria-hidden','true');button.append(icon);
            button.addEventListener('click',()=>this.open({current:current(),apply,signal,gradients}),{signal});return button;
        }
        mountManager(container) {
            const group=make('details','editor-style-group editor-palette-manager'),summary=make('summary','','Color palettes');group.append(summary);
            const hint=make('p','editor-viewport-note','Local palettes belong to this page. Make global shares a palette across pages. Make default chooses which palette opens first.');
            const list=make('div','editor-palette-collections-manager'),add=make('button','','New palette');add.type='button';add.dataset.paletteManagerNew='';
            add.addEventListener('click',()=>this.open({current:'#2ecc71',gradients:true,manage:true,newPalette:true}));
            const render=()=>{
                list.replaceChildren();for(const palette of this.collections()){
                    const row=make('div','editor-palette-manager-row'),open=make('button','editor-palette-manager-open');open.type='button';open.dataset.paletteManage=key(palette);
                    const label=make('span','',palette.name),detail=make('small','',`${palette.scope==='external'?`External · ${palette.provider}`:palette.scope==='local'?'Local':'Global'} · ${palette.colors.length} ${palette.colors.length===1?'color':'colors'}${key(this.defaultPalette())===key(palette)?' · Default':''}`);open.append(label,detail);
                    open.addEventListener('click',()=>this.open({current:'#2ecc71',gradients:true,manage:true,palette:{scope:palette.scope,id:palette.id}}));
                    const actions=make('div','editor-palette-manager-actions'),defaultButton=make('button','','Make default');defaultButton.type='button';defaultButton.disabled=key(this.defaultPalette())===key(palette);defaultButton.dataset.paletteDefault=key(palette);
                    defaultButton.addEventListener('click',()=>{try{this.makeDefault(palette);}catch(error){hint.textContent=error.message;}});actions.append(defaultButton);
                    if(palette.scope==='local'){const promote=make('button','','Make global');promote.type='button';promote.dataset.paletteGlobal=key(palette);promote.addEventListener('click',async()=>{promote.disabled=true;try{await this.makeGlobal(palette);}catch(error){hint.textContent=error.message;}finally{promote.disabled=false;}});actions.append(promote);}
                    row.append(open,actions);list.append(row);
                }
                if(!list.childNodes.length)list.append(make('p','editor-viewport-note','No palettes yet. Create one for this page.'));
            };
            this.addEventListener('change',render);group.append(hint,list,add);container.append(group);render();this.refresh().catch(error=>{hint.textContent=error.message;});
            return()=>{this.removeEventListener('change',render);group.remove();};
        }
        open(target) {
            if(target.signal?.aborted)return;if(!this.#dialog)this.#buildDialog();this.#cleanup?.();this.#target=target;
            const close=()=>this.close(true);target.signal?.addEventListener('abort',close,{once:true});this.#cleanup=()=>target.signal?.removeEventListener('abort',close);
            this.#selected=key(target.palette || this.defaultPalette());if(target.edit)this.#selected='local:qpal-legacy';
            const needsDefault = this.#selected && !this.collections().some(palette=>key(palette)===this.#selected);
            this.#render();if(!this.#dialog.open)this.#dialog.showModal();
            if(target.newPalette)this.#dialog.querySelector('[data-palette-new]').click();else this.#edit(target.edit?this.list().find(entry=>entry.id===target.edit):null);
            this.#dialog.querySelector('[data-palette-explore]:not([hidden]),[data-palette-open],[data-palette-new]')?.focus();
            this.refresh().then(()=>{if(needsDefault&&this.#target===target)this.#edit(null);this.#render?.();}).catch(error=>{const message=this.#dialog.querySelector('[data-palette-message]');if(this.#target)message.textContent=error.message;});
        }
        close(force=false) {if(this.#busy&&!force)return;this.#cleanup?.();this.#cleanup=null;this.#target=null;this.#dialog?.close();}
        #buildDialog() {
            injectStyles();
            const dialog=make('dialog','editor-palette-dialog');dialog.setAttribute('aria-label','Color palettes');
            const title=make('h2','','Color palettes'),close=window.QuillEditorIcon.set(make('button', 'editor-image-close'), 'xmark');close.type='button';close.setAttribute('aria-label','Close saved colors');close.addEventListener('click',()=>this.close());
            const explore=window.QuillEditorIcon.set(make('button','editor-palette-explore'), 'arrow-left', 'Explore');explore.type='button';explore.dataset.paletteExplore='';
            const scope=make('p','editor-viewport-note'),views=make('div','editor-palette-views'),index=make('div','editor-palette-index'),paletteView=make('div','editor-palette-detail');
            const danger=make('footer','editor-palette-danger'),removeColor=window.QuillEditorIcon.set(make('button','editor-library-delete'),'trash-can','Delete color');removeColor.type='button';removeColor.dataset.paletteDeleteColor='';
            const actions=make('div','editor-palette-actions'),defaultButton=make('button','','Make default'),promote=make('button','','Make global'),rename=make('button','','Rename'),removePalette=make('button','','Delete palette');
            for(const [button,name]of [[defaultButton,'paletteMakeDefault'],[promote,'paletteMakeGlobal'],[rename,'paletteRename'],[removePalette,'paletteDelete']]){button.type='button';button.dataset[name]='';if(button!==removePalette)actions.append(button);}
            removePalette.className='editor-library-delete';window.QuillEditorIcon.set(removePalette,'trash-can','Delete palette');danger.append(removeColor,removePalette);
            window.QuillEditorIcon.set(defaultButton,'star','Make default');window.QuillEditorIcon.set(promote,'globe','Make global');window.QuillEditorIcon.set(rename,'pen','Rename');
            const list=make('div','editor-palette-list'),heading=make('h3'),form=make('form','editor-palette-form');
            const nameLabel=make('label','','Color name'),name=make('input');name.type='text';name.dataset.paletteName='';name.required=true;name.maxLength=128;nameLabel.append(name);
            const valueLabel=make('label','','Color or gradient'),value=make('textarea');value.rows=2;value.dataset.paletteValue='';value.required=true;valueLabel.append(value);
            const preview=make('div','editor-palette-value-preview'),native=make('input');native.type='text';native.className='editor-color-swatch';native.setAttribute('data-coloris','');native.spellcheck=false;native.setAttribute('aria-label','Choose saved color');
            const save=window.QuillEditorIcon.set(make('button','button-primary'),'check','Save color');save.type='submit';const add=window.QuillEditorIcon.set(make('button','editor-palette-secondary'),'plus','New color');add.type='button';
            const message=make('p','editor-palette-message');message.role='status';message.dataset.paletteMessage='';
            const create=window.QuillEditorIcon.set(make('button','button-primary'),'plus','New palette');create.type='button';create.dataset.paletteNew='';
            const namingForm=make('form','editor-palette-naming'),namingLabel=make('label','','Palette name'),paletteName=make('input');paletteName.type='text';paletteName.maxLength=255;paletteName.required=true;paletteName.dataset.paletteCollectionName='';namingLabel.append(paletteName);
            const namingSave=make('button','button-primary','Create palette');namingSave.type='submit';const namingCancel=make('button','','Cancel');namingCancel.type='button';namingForm.append(namingLabel,namingSave,namingCancel);
            const hint=make('p','editor-viewport-note','Choosing a color copies its value. Editing a palette does not recolor existing elements.');
            const gradientEdit=window.QuillEditorIcon.set(make('button','editor-palette-gradient-edit'),'fill-drip','Edit gradient');gradientEdit.type='button';gradientEdit.dataset.paletteGradientEdit='';
            let gradientAbort = null, gradientWidget = null, draftStyle = document.createElement('div').style;
            let editing=null,naming=null,renderedView=null,transition=null;
            const selected=()=>this.collections().find(palette=>key(palette)===this.#selected);
            const updatePreview=()=>{
                const paint=value.value.trim(),gradient=QuillEditorPalette.isGradient(paint);
                preview.replaceChildren(this.#swatch(paint));
                native.value=CSS.supports('color',paint)?paint:'';native.style.backgroundColor=native.value||'transparent';
                gradientEdit.lastChild.textContent=gradient?' Edit gradient':' Create gradient';
                native.hidden=gradient;const field=native.closest('.editor-color-field');if(field)field.hidden=gradient;
                window.QuillEditorColorPicker.update(native);
            };
            const updateDelete=()=>{const entry=selected()?.colors.find(entry=>entry.id===editing);removeColor.hidden=!entry||this.#isolated||selected()?.scope==='external';removeColor.setAttribute('aria-label',entry?`Delete ${entry.name}`:'Delete color');};
            gradientEdit.addEventListener('click',async()=>{
                const selectedColor=editing, selectedPalette=this.#selected, target=this.#target;
                try {
                    const {colorControl}=await import(propertyAssetBase+'paint-control.js');
                    if(!dialog.open||this.#target!==target||this.#isolated||selectedColor!==editing||selectedPalette!==this.#selected)return;
                    if(!gradientWidget){
                        gradientAbort=new AbortController();
                        const changed=()=>{value.value=QuillEditorPalette.isGradient(draftStyle.backgroundImage)?draftStyle.backgroundImage:draftStyle.backgroundColor;updatePreview();};
                        const adapter={selectedPath:'palette',canvas:{getElement:()=>null},reportError:error=>{message.textContent=error?.message||'';},
                            getStyleDeclarations:()=>{const style=document.createElement('div').style;style.cssText=draftStyle.cssText;return style;},
                            setStyle:(property,value)=>{draftStyle.setProperty(property,value);changed();},setStyleDeclarations:css=>{draftStyle.cssText=css;changed();}};
                        gradientWidget=colorControl('background-color','Saved color',adapter,gradientAbort.signal);
                    }
                    draftStyle.cssText='';if(QuillEditorPalette.isGradient(value.value))draftStyle.backgroundImage=value.value;else draftStyle.backgroundColor=value.value;
                    gradientWidget.set(draftStyle.backgroundColor,true,draftStyle);
                    gradientWidget.element.querySelector('[data-gradient-for]').click();
                }catch(error){message.textContent=error.message;}
            });
            const busy=value=>{this.#busy=value;dialog.setAttribute('aria-busy',String(value));dialog.querySelectorAll('button,input,textarea').forEach(control=>{control.disabled=value;});if(!value)this.#render();};
            const attempt=async callback=>{if(this.#busy)return;message.textContent='';busy(true);try{await callback();}catch(error){message.textContent=error.message;}finally{busy(false);if(dialog.open&&(!dialog.contains(document.activeElement)||document.activeElement===dialog))dialog.querySelector('.editor-palette-naming:not([hidden]) input,.editor-palette-detail:not([hidden]) [data-palette-name],[data-palette-open]')?.focus();}};
            this.#edit=entry=>{editing=entry?.id || null;heading.textContent=entry?'Edit saved color':'Save a color';message.textContent='';const used=new Set((selected()?.colors || []).map(color=>color.name.toLowerCase()));let number=1;while(used.has(`color ${number}`))number++;name.value=entry?.name || `Color ${number}`;value.value=entry?.value || this.#target?.current || '#2ecc71';updatePreview();updateDelete();};
            const show=palette=>{naming=null;this.#selected=palette?key(palette):null;this.#edit(null);this.#render(true);(palette?name:index.querySelector('button'))?.focus();};
            explore.addEventListener('click',()=>show(null));add.addEventListener('click',()=>{this.#edit(null);name.focus();});
            native.addEventListener('input',()=>{value.value=native.value;updatePreview();});value.addEventListener('input',updatePreview);
            form.addEventListener('submit',event=>{event.preventDefault();const palette=selected();if(!palette)return;const entry={id:editing || 'qp-'+crypto.randomUUID(),name:name.value.trim(),value:value.value.trim()},colors=copy(palette.colors),position=colors.findIndex(color=>color.id===entry.id);if(position<0)colors.push(entry);else colors[position]=entry;attempt(async()=>{if(await this.#updatePalette(palette,palette.name,colors)){this.#edit(null);message.textContent='Color saved.';}});});
            defaultButton.addEventListener('click',()=>{try{this.makeDefault(selected());message.textContent='Default palette updated.';}catch(error){message.textContent=error.message;}});
            promote.addEventListener('click',()=>attempt(async()=>{await this.makeGlobal(selected());message.textContent='Palette is now global.';}));
            removeColor.addEventListener('click',()=>attempt(async()=>{const palette=selected();if(palette&&editing&&await this.#updatePalette(palette,palette.name,palette.colors.filter(color=>color.id!==editing))){this.#edit(null);}}));
            removePalette.addEventListener('click',()=>attempt(()=>this.#deletePalette(selected())));
            const startNaming=palette=>{naming=palette || 'new';paletteName.value=palette?.name || '';namingSave.textContent=palette?'Save name':'Create palette';this.#render(true);paletteName.focus();};
            create.addEventListener('click',()=>startNaming(null));rename.addEventListener('click',()=>startNaming(selected()));namingCancel.addEventListener('click',()=>{naming=null;this.#render(true);});
            namingForm.addEventListener('submit',event=>{event.preventDefault();const source=naming,text=paletteName.value.trim();if(!text)return;attempt(async()=>{if(source==='new'){const palette=this.createLocal(text);naming=null;show(palette);}else if(await this.#updatePalette(source,text,source.colors)){naming=null;this.#render(true);}});});
            this.#render=(animate=false)=>{
                if(!this.#target)return;const palette=selected(),inDetail=!!palette&&!naming;title.textContent=naming?(naming==='new'?'New palette':'Rename palette'):palette?.name || 'Color palettes';
                danger.hidden=!inDetail||this.#isolated||palette?.scope==='external';updateDelete();
                explore.hidden=!palette&&!naming;index.hidden=!!palette||!!naming;paletteView.hidden=!inDetail;namingForm.hidden=!naming;scope.textContent=inDetail?`${palette.scope==='external'?`External · Provided by ${palette.provider}`:palette.scope==='global'?'Global · Shared across pages':'Local · This page'}${key(this.defaultPalette())===key(palette)?' · Default':''}`:'';
                defaultButton.disabled=this.#isolated||key(this.defaultPalette())===key(palette);promote.hidden=this.#isolated||palette?.scope!=='local';rename.hidden=removePalette.hidden=this.#isolated||palette?.scope==='external';form.hidden=add.hidden=heading.hidden=this.#isolated||palette?.scope==='external';create.hidden=this.#isolated;
                index.replaceChildren();for(const [type,label]of [['local','Local palettes'],['global','Global palettes'],['external','External']]){
                    const section=make('section','editor-palette-collection-group');section.append(make('h3','',label));const palettes=this.collections().filter(item=>item.scope===type);
                    for(const item of palettes){const option=make('button','editor-palette-collection');option.type='button';option.dataset.paletteOpen=key(item);const text=make('span','editor-palette-collection-name',item.name),meta=make('small','',`${item.colors.length} ${item.colors.length===1?'color':'colors'}${key(this.defaultPalette())===key(item)?' · Default':''}`),swatches=make('span','editor-palette-mini-swatches');item.colors.slice(0,6).forEach(color=>swatches.append(this.#swatch(color.value)));option.append(text,meta);if(item.provider)option.append(make('small','editor-resource-provider',`Provided by ${item.provider}`));option.append(swatches);option.addEventListener('click',()=>show(item));section.append(option);}
                    if(!palettes.length)section.append(make('p','editor-viewport-note',type==='local'?'No local palettes yet.':type==='external'?'No external palettes available.':'No global palettes available.'));index.append(section);
                }index.append(create);
                list.replaceChildren();for(const entry of palette?.colors || []){
                    const row=make('div','editor-palette-row'),choose=make('button');choose.type='button';choose.dataset.savedPaint=entry.id;choose.title=`${entry.name}: ${entry.value}`;choose.append(this.#swatch(entry.value),make('span','',entry.name));choose.disabled=!this.#target.manage&&QuillEditorPalette.isGradient(entry.value)&&!this.#target.gradients;if(choose.disabled)choose.title='Choose a solid color here.';
                    choose.addEventListener('click',()=>{if(this.#target?.manage){if(palette.scope==='external')message.textContent=`Managed by ${palette.provider}. Choose Make default to use this palette first.`;else this.#edit(entry);return;}try{this.#target?.apply?.(entry.value,QuillEditorPalette.isGradient(entry.value));this.close();}catch(error){message.textContent=error.message;}});
                    row.append(choose);if(!this.#isolated&&palette.scope!=='external'){const edit=make('button','editor-palette-edit','Edit');edit.type='button';edit.setAttribute('aria-label',`Edit ${entry.name}`);edit.addEventListener('click',()=>this.#edit(entry));row.append(edit);}list.append(row);
                }if(!list.childNodes.length)list.append(make('p','editor-viewport-note','No colors in this palette yet.'));
                const next=naming?'name':palette?key(palette):'explore';if(animate&&next!==renderedView&&!matchMedia('(prefers-reduced-motion: reduce)').matches){transition?.cancel();transition=views.animate([{opacity:0,transform:next==='explore'?'translateX(-10px)':'translateX(10px)'},{opacity:1,transform:'translateX(0)'}],{duration:140,easing:'ease-out'});}renderedView=next;
                if(this.#busy)dialog.querySelectorAll('button,input,textarea').forEach(control=>{control.disabled=true;});
            };
            form.append(nameLabel,preview,window.QuillEditorColorPicker.wrap(native),gradientEdit,valueLabel,save,add);paletteView.append(actions,list,heading,form,danger);views.append(index,paletteView,namingForm);dialog.append(title,close,explore,scope,views,message,hint);
            dialog.addEventListener('cancel',event=>{if(this.#busy)event.preventDefault();});dialog.addEventListener('close',()=>{if(dialog.open)return;gradientAbort?.abort();gradientAbort=null;gradientWidget=null;this.#cleanup?.();this.#cleanup=null;this.#target=null;naming=null;});document.body.append(dialog);this.#dialog=dialog;
        }
    }
    window.QuillEditorPalette = QuillEditorPalette;
})();
