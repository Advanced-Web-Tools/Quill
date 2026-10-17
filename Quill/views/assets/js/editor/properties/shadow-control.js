import { splitCss, paintContext, commitPaint } from './paint-control.js';

export function shadowControl(property, editor, signal) {
    const element = document.createElement('div'); element.className = 'editor-shadow-control'; element.dataset.shadows = property;
    const box = property === 'box-shadow';
    const name = box ? 'Outer shadow' : 'Text shadow';
    const list = document.createElement('div');
    const add = document.createElement('button'); add.type='button'; add.textContent='Add shadow'; add.dataset.addShadow=property;
    const reset = document.createElement('button'); reset.type='button'; reset.textContent='Reset shadows';
    const raw = document.createElement('input'); raw.type='text';raw.placeholder='Custom shadow';raw.setAttribute('aria-label',`${name} custom value`);raw.dataset.style=property;
    const advanced = document.createElement('details'); const summary=document.createElement('summary');summary.textContent='More options';advanced.append(summary,raw);
    const hint=document.createElement('p');hint.className='editor-viewport-note';hint.textContent=box?'Add depth around this item, or choose Inside for a recessed effect.':'Shadow follows the letters and icon shapes.';
    element.append(hint,list,add,reset,advanced);
    let shadows=[], last='';
    let rowsAbort = new AbortController();
    signal.addEventListener('abort',()=>rowsAbort.abort(),{once:true});
    const makeShadow=()=>({x:'0px',y:'4px',blur:'12px',spread:'0px',color:'rgba(0, 0, 0, 0.25)',inset:false});
    const serialize=()=>shadows.map(shadow=>`${box&&shadow.inset?'inset ':''}${shadow.x} ${shadow.y} ${shadow.blur}${box?' '+shadow.spread:''} ${shadow.color}`).join(', ');
    const save=()=>{
        const value=serialize()||'none';
        if(!CSS.supports(property,value)){editor.reportError(new Error('Check the shadow distances and color.'));return;}
        const normalized=document.createElement('div').style;normalized.setProperty(property,value);last=normalized.getPropertyValue(property);raw.value=last;
        commitPaint(editor,style=>style.setProperty(property,value));
    };
    const render=()=>{
        rowsAbort.abort();rowsAbort=new AbortController();
        const fragment=document.createDocumentFragment();
        shadows.forEach((shadow,index)=>{
            const card=document.createElement('fieldset');card.className='editor-shadow-card';
            const legend=document.createElement('legend');legend.textContent=`Shadow ${index+1}`;card.append(legend);
            const grid=document.createElement('div');grid.className='editor-shadow-fields';
            for(const [key,label] of [['x','Horizontal'],['y','Vertical'],['blur','Softness'],...(box?[['spread','Reach']]:[])]){
                const caption=document.createElement('label');caption.textContent=`${label} (px)`;
                const input=document.createElement('input');input.type='text';input.inputMode='decimal';input.value=shadow[key];input.setAttribute('aria-label',`${name} ${index+1} ${label.toLowerCase()}`);
                input.addEventListener('input',()=>{const value=input.value.trim();shadow[key]=value?/^-?(?:\d*\.)?\d+$/.test(value)?value+'px':value:'0px';save();});
                caption.append(input);grid.append(caption);
            }
            const color=document.createElement('input');color.type='text';color.value=shadow.color;color.setAttribute('data-coloris','');color.setAttribute('aria-label',`${name} ${index+1} color`);color.className='editor-color-swatch';color.style.backgroundColor=shadow.color;
            color.addEventListener('input',()=>{if(!CSS.supports('color',color.value)){editor.reportError(new Error('Enter a valid shadow color.'));return;}shadow.color=color.value;color.style.backgroundColor=color.value;save();});
            const actions=document.createElement('div');actions.className='editor-shadow-actions';actions.append(window.QuillEditorColorPicker.wrap(color));
            if(editor.palette)actions.append(editor.palette.button({current:()=>shadow.color,apply:value=>{shadow.color=value;color.value=value;color.style.backgroundColor=value;window.QuillEditorColorPicker.update(color);save();},signal:rowsAbort.signal,label:`Saved colors: ${name} ${index+1}`}));
            if(box){
                const caption=document.createElement('label');const inset=document.createElement('input');inset.type='checkbox';inset.checked=shadow.inset;inset.setAttribute('aria-label',`Shadow ${index+1} inside`);
                inset.addEventListener('change',()=>{shadow.inset=inset.checked;save();});caption.append(inset,' Inside');actions.append(caption);
            }
            const remove=document.createElement('button');remove.type='button';remove.textContent='Remove';remove.setAttribute('aria-label',`Remove ${name} ${index+1}`);
            remove.addEventListener('click',()=>{shadows.splice(index,1);render();save();});actions.append(remove);card.append(grid,actions);fragment.append(card);
        });
        list.replaceChildren(fragment);
    };
    const parse=value=>{
        if(!value||value==='none')return [];
        return splitCss(value).map(layer=>{
            const tokens=splitCss(layer,' '),lengths=[];let color='currentcolor',inset=false;
            for(const token of tokens){if(token==='inset')inset=true;else if(CSS.supports('color',token))color=token;else lengths.push(token);}
            if(lengths.length<2||lengths.length>(box?4:3))return null;
            return {x:lengths[0],y:lengths[1],blur:lengths[2]||'0px',spread:lengths[3]||'0px',color,inset};
        });
    };
    add.addEventListener('click',()=>{
        const {style,computed}=paintContext(editor),existing=style.getPropertyValue(property)||computed.getPropertyValue(property);
        const parsed=parse(existing);if(parsed.some(value=>value===null)){editor.reportError(new Error('Edit this custom shadow in More options before adding another.'));return;}
        shadows=parsed;shadows.push(makeShadow());render();save();
    },{signal});
    const clear=()=>commitPaint(editor,style=>style.removeProperty(property));
    reset.addEventListener('click',clear,{signal});
    raw.addEventListener('input',()=>{
        if(raw.value && !CSS.supports(property,raw.value)){editor.reportError(new Error('Check the custom shadow value.'));return;}
        commitPaint(editor,style=>{if(raw.value)style.setProperty(property,raw.value);else style.removeProperty(property);});
    },{signal});
    return {element,input:raw,reset:clear,set(value,force,computed){
        const resolved=value||computed?.getPropertyValue(property)||'';
        if(!force&&element.contains(document.activeElement)&&raw!==document.activeElement)return;
        if(force||resolved!==last){
            last=resolved;shadows=parse(resolved);
            if(shadows.some(shadow=>shadow===null)){shadows=[];advanced.open=true;}
            render();if(force||raw!==document.activeElement)raw.value=resolved;
        }
        reset.disabled=!value;
    }};
}
