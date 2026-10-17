import { weightLabel as fontWeightLabel } from './property-language.js';
export function fontControl(editor, signal) {
    const element = document.createElement('div'); element.className = 'editor-font-control';
    const input = document.createElement('input'); input.type = 'text'; input.dataset.style = 'font-family'; input.placeholder = 'Default / system font';
    input.addEventListener('input', () => {
        try { editor.setStyle('font-family', input.value.trim()); editor.reportError(null); }
        catch (error) { editor.reportError(error); }
    }, { signal });
    const choose = document.createElement('button'); choose.type = 'button'; choose.className = 'editor-font-choose';
    choose.innerHTML = '<i class="fa-solid fa-font" aria-hidden="true"></i> Browse Google Fonts';
    element.append(input, choose);
    const dialog = document.createElement('dialog'); dialog.className = 'editor-font-dialog'; dialog.setAttribute('aria-label', 'Choose a Google font');
    const heading = document.createElement('h2'); heading.textContent = 'Google Fonts';
    const close = document.createElement('button'); close.type = 'button'; close.className = 'editor-image-close'; window.QuillEditorIcon.set(close, 'xmark'); close.setAttribute('aria-label', 'Close font picker');
    const filters = document.createElement('div'); filters.className = 'editor-font-filters';
    const search = document.createElement('input'); search.type = 'search'; search.placeholder = 'Search font families…'; search.setAttribute('aria-label', 'Search fonts'); search.dataset.fontSearch = '';
    const category = document.createElement('select'); category.setAttribute('aria-label', 'Font category'); category.add(new Option('All categories', ''));
    [...new Set(editor.fonts.catalog.map(font => font.category))].sort().forEach(value => category.add(new Option(value,value)));
    const subset = document.createElement('select'); subset.setAttribute('aria-label', 'Font script');
    [['','All scripts'],['latin-ext','Extended Latin (ČĆŠŽĐ)'],['cyrillic','Cyrillic'],['greek','Greek']].forEach(([value,label]) => subset.add(new Option(label,value)));
    filters.append(search,category,subset);
    const count = document.createElement('p'); count.setAttribute('role','status');
    const results = document.createElement('div'); results.className = 'editor-font-results';
    const more = document.createElement('button'); more.type='button';more.textContent='Load more fonts';
    const preview = document.createElement('section'); preview.className = 'editor-font-preview';
    const name = document.createElement('h3');
    const sampleLabel = document.createElement('label'); sampleLabel.textContent = 'Preview text';
    const sample = document.createElement('textarea'); sample.rows=3; sample.setAttribute('aria-label','Font preview text');sampleLabel.append(sample);
    const variants = document.createElement('div'); variants.className='editor-font-variants';
    const weightLabel=document.createElement('label');weightLabel.textContent='Weight';
    const weight=document.createElement('select');weight.setAttribute('aria-label','Google font weight');weightLabel.append(weight);
    const styleLabel=document.createElement('label');styleLabel.textContent='Style';
    const style=document.createElement('select');style.setAttribute('aria-label','Google font style');styleLabel.append(style);
    variants.append(weightLabel,styleLabel);
    const status=document.createElement('p');status.className='editor-viewport-note';status.setAttribute('role','status');
    const retry=document.createElement('button');retry.type='button';retry.textContent='Retry preview';retry.hidden=true;
    const apply=document.createElement('button');apply.type='button';apply.className='button-primary';apply.textContent='Use this font';
    preview.append(name,sampleLabel,variants,status,retry,apply);
    const hint=document.createElement('p');hint.className='editor-viewport-note';hint.textContent='Only previewed and used fonts are loaded. Your system font appears while the selected font loads.';
    dialog.append(heading,close,filters,count,results,more,preview,hint);document.body.append(dialog);
    let candidate=null, limit=40, targetWeight=400, targetItalic=0, ticket=0, sheet=null, openPath=null, openScope=null;
    const valid=()=>dialog.open&&!signal.aborted&&editor.selectedPath===openPath&&editor.styleScope===openScope;
    const loadPreview=()=>{
        if(!candidate||!valid())return;
        const request=++ticket;sheet?.remove();retry.hidden=true;status.textContent='Loading preview…';
        const italic=Number(style.value), value=Number(weight.value);
        sample.style.fontFamily=`${JSON.stringify(candidate.family)}, ${editor.fonts.fallback(candidate)}`;
        sample.style.fontWeight=String(value);sample.style.fontStyle=italic?'italic':'normal';
        const link=document.createElement('link');link.rel='stylesheet';link.dataset.quillFontPreview='';
        link.href=editor.fonts.url(candidate.family,[editor.fonts.variant(candidate,value,italic)]);
        const fail=()=>{if(request===ticket&&valid()){status.textContent='Preview unavailable. System fallback shown.';retry.hidden=false;}};
        link.addEventListener('error',fail,{once:true});
        link.addEventListener('load',async()=>{
            try{
                const faces=await document.fonts.load(`${italic?'italic ':''}${value} 28px ${JSON.stringify(candidate.family)}`,sample.value.slice(0,120)||'Aa');
                if(request!==ticket||!valid())return;
                if(!faces.length){fail();return;}
                status.textContent=`${candidate.family} · ${value}${italic?' Italic':''}`;
            }catch{fail();}
        },{once:true});
        sheet=link;document.head.append(link);
    };
    const setWeights=()=>{
        const italic=Number(style.value);let values=editor.fonts.weights(candidate,!!italic);
        if(candidate.weightRange&&targetWeight>=candidate.weightRange[0]&&targetWeight<=candidate.weightRange[1])values=[...new Set([...values,targetWeight])].sort((a,b)=>a-b);
        weight.replaceChildren(...values.map(value=>new Option(fontWeightLabel(value),String(value))));
        const selected=values.slice().sort((a,b)=>Math.abs(a-targetWeight)-Math.abs(b-targetWeight)||a-b)[0];weight.value=String(selected);
    };
    const selectFont=font=>{
        candidate=font;name.textContent=font.family;
        style.replaceChildren();
        if(editor.fonts.weights(font,false).length)style.add(new Option('Upright','0'));
        if(editor.fonts.weights(font,true).length)style.add(new Option('Italic','1'));
        style.value=[...style.options].some(option=>option.value===String(targetItalic))?String(targetItalic):style.options[0].value;
        setWeights();loadPreview();
        results.querySelectorAll('[data-font-family]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.fontFamily===font.family)));
    };
    const render=()=>{
        const terms=search.value.toLowerCase().trim().split(/\s+/);
        const matches=editor.fonts.catalog.filter(font=>(!category.value||category.value===font.category)&&(!subset.value||font.subsets.includes(subset.value))&&terms.every(term=>font.family.toLowerCase().includes(term)));
        count.textContent=matches.length?`${Math.min(limit,matches.length)} of ${matches.length} fonts`:'No fonts found.';
        const fragment=document.createDocumentFragment();
        for(const font of matches.slice(0,limit)){
            const button=document.createElement('button');button.type='button';button.dataset.fontFamily=font.family;button.setAttribute('aria-pressed',String(candidate?.family===font.family));
            const title=document.createElement('span');title.textContent=font.family;
            const category=document.createElement('small');category.textContent=font.category;button.append(title,category);
            button.addEventListener('click',()=>selectFont(font));fragment.append(button);
        }
        results.replaceChildren(fragment);more.hidden=limit>=matches.length;
    };
    choose.addEventListener('click',()=>{
        openPath=editor.selectedPath;openScope=editor.styleScope;
        const node=editor.canvas.getElement(editor.selectedPath),computed=node?node.ownerDocument.defaultView.getComputedStyle(node):null;
        const declarations=editor.getStyleDeclarations();
        targetWeight=parseFloat(declarations.fontWeight||computed?.fontWeight)||400;
        targetItalic=/^(italic|oblique)/.test(declarations.fontStyle||computed?.fontStyle||'')?1:0;
        sample.value=(node?.textContent||editor.getSelectedBlock().content||'Aa Bb Čć Ćć Šš Žž Đđ').trim().slice(0,160);
        limit=40;dialog.showModal();render();
        selectFont(editor.fonts.find(declarations.fontFamily||computed?.fontFamily)||editor.fonts.byName.get('Inter')||editor.fonts.catalog[0]);search.focus();
    },{signal});
    for(const filter of [search,category,subset])filter.addEventListener(filter===search?'input':'change',()=>{limit=40;render();},{signal});
    more.addEventListener('click',()=>{limit+=40;render();},{signal});
    style.addEventListener('change',()=>{targetItalic=Number(style.value);setWeights();loadPreview();},{signal});
    weight.addEventListener('change',()=>{targetWeight=Number(weight.value);loadPreview();},{signal});
    // Font loading is not repeated while the user types the preview sample.
    sample.addEventListener('change',loadPreview,{signal});retry.addEventListener('click',loadPreview,{signal});
    apply.addEventListener('click',()=>{
        if(!candidate||!valid()||editor.isReadOnly())return;
        try{
            const declarations=editor.getStyleDeclarations();
            declarations.fontFamily=`${JSON.stringify(candidate.family)}, ${editor.fonts.fallback(candidate)}`;
            declarations.fontWeight=weight.value;declarations.fontStyle=Number(style.value)?'italic':'normal';
            editor.setStyleDeclarations(declarations.cssText);editor.reportError(null);dialog.close();
        }catch(error){editor.reportError(error);}
    },{signal});
    close.addEventListener('click',()=>dialog.close(),{signal});
    dialog.addEventListener('close',()=>{ticket++;sheet?.remove();sheet=null;},{signal});
    signal.addEventListener('abort',()=>{ticket++;sheet?.remove();dialog.close();dialog.remove();},{once:true});
    return {element,input,set(value,force,computed){
        if(force&&dialog.open)dialog.close();
        if(force||input!==document.activeElement)input.value=value||computed?.fontFamily||'';
    }};
}
