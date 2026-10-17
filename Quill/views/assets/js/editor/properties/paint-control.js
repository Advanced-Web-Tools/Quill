export function splitCss(value, separator = ',') {
    const result = []; let depth = 0, start = 0, quote = '';
    for (let index = 0; index <= value.length; index++) {
        const character = value[index];
        if (quote) { if (character === quote && value[index - 1] !== '\\') quote = ''; }
        else if (character === '"' || character === "'") quote = character;
        else if (character === '(') depth++;
        else if (character === ')') depth--;
        if (index === value.length || (!quote && !depth && (separator === ' ' ? /\s/.test(character) : character === separator))) {
            if (index > start) result.push(value.slice(start, index).trim()); start = index + 1;
        }
    }
    return result;
}
export function paintContext(editor) {
    const style = editor.getStyleDeclarations ? editor.getStyleDeclarations() : document.createElement('div').style;
    if (!editor.getStyleDeclarations) style.cssText = editor.getSelectedBlock().attributes.style || '';
    const node = editor.canvas.getElement(editor.selectedPath);
    const computed = node ? node.ownerDocument.defaultView.getComputedStyle(node) : style;
    return { style, computed };
}
export function commitPaint(editor, change) {
    try {
        const {style, computed} = paintContext(editor); change(style, computed);
        if (editor.setStyleDeclarations) editor.setStyleDeclarations(style.cssText); else editor.setAttribute('style', style.cssText || null);
        editor.reportError(null);
    } catch (error) { editor.reportError(error); }
}
// Seed an unset picker with an opaque color; authored transparent colors remain intact.
const defaultColor = property => property === 'background-color' ? '#ffffff' : property === 'border-color' ? '#d1d1d6' : '#1d1d1f';
const isTransparent = value => {
    const color = document.createElement('div');
    color.style.color = value;
    return color.style.color === 'transparent' || /^(?:rgba|hsla)\(.*,\s*0\s*\)$/.test(color.style.color) || /\/\s*0%?\s*\)$/.test(color.style.color);
};
const hasTextPaint = (style, computed) => (style.backgroundClip || computed.backgroundClip || '').split(',').some(value => value.trim() === 'text');
const backgroundPaint = (style, computed) => hasTextPaint(style, computed)
    ? style.getPropertyValue('--quill-background-paint') || computed.getPropertyValue('--quill-background-paint') || 'none'
    : style.backgroundImage || computed.backgroundImage || 'none';
export function setBackgroundPaint(style, value, computed) {
    if (hasTextPaint(style, computed)) {
        style.setProperty('--quill-background-paint', value || 'none');
        style.backgroundImage = 'var(--quill-text-gradient), var(--quill-background-paint)';
    } else if (value) style.backgroundImage = value; else style.removeProperty('background-image');
}
export function gradientValue(property, style, computed) {
    if (property === 'color') return hasTextPaint(style, computed) ? style.getPropertyValue('--quill-text-gradient') || computed.getPropertyValue('--quill-text-gradient') : '';
    const value = property === 'background-color' ? backgroundPaint(style, computed) : property === 'border-color' ? style.borderImageSource || computed.borderImageSource : '';
    return /^(?:repeating-)?(?:linear|radial|conic)-gradient\(/.test(value.trim()) ? value.trim() : '';
}
export function applyPaint(editor, property, value, gradient = false) {
    // Keep the established single-property path for ordinary solid colors, including
    // page settings, which retain the author's original color notation.
    const current = paintContext(editor);
    if (!gradient && !gradientValue(property, current.style, current.computed)
        && !(property === 'color' && current.computed.webkitTextFillColor === 'rgba(0, 0, 0, 0)')) {
        try { editor.setStyle(property, value); editor.reportError(null); }
        catch (error) { editor.reportError(error); }
        return;
    }
    commitPaint(editor, (style, computed) => {
        if (!gradient && value && !CSS.supports(property, value)) throw new Error('Choose a valid color.');
        if (gradient && (!CSS.supports('background-image', value) || !/^(linear|radial|conic)-gradient\(/.test(value))) throw new Error('Enter a valid gradient.');
        if (property === 'background-color') {
            if (gradient) setBackgroundPaint(style, value, computed);
            else {
                if (gradientValue(property, style, computed)) setBackgroundPaint(style, 'none', computed);
                if (value) style.setProperty(property, value); else style.removeProperty(property);
            }
        } else if (property === 'color') {
            if (gradient) {
                const background = backgroundPaint(style, computed);
                style.setProperty('--quill-background-paint', background);
                style.setProperty('--quill-text-gradient', value);
                style.backgroundImage = 'var(--quill-text-gradient), var(--quill-background-paint)';
                style.backgroundClip = 'text, border-box'; style.webkitBackgroundClip = 'text, border-box';
                style.webkitTextFillColor = 'transparent';
            } else {
                if (hasTextPaint(style, computed)) {
                    style.backgroundImage = backgroundPaint(style, computed);
                    style.backgroundClip = 'border-box'; style.webkitBackgroundClip = 'border-box';
                    style.webkitTextFillColor = 'currentcolor'; style.setProperty('--quill-text-gradient', 'none');
                }
                if (computed.webkitTextFillColor === 'rgba(0, 0, 0, 0)') style.webkitTextFillColor = 'currentcolor';
                if (value) style.color = value; else style.removeProperty('color');
            }
        } else if (property === 'border-color') {
            if (gradient) {
                style.borderImageSource = value; style.borderImageSlice = '1';
                if ((style.borderStyle || computed.borderStyle) === 'none') style.borderStyle = 'solid';
                if (parseFloat(style.borderWidth || computed.borderWidth) === 0) style.borderWidth = '1px';
            } else {
                if (gradientValue(property, style, computed)) style.borderImageSource = 'none';
                if (value) style.borderColor = value; else style.removeProperty('border-color');
            }
        } else if (value) style.setProperty(property, value); else style.removeProperty(property);
    });
}
export function resetPaint(editor, property) {
    commitPaint(editor, style => {
        style.removeProperty(property);
        if (property === 'color' && style.getPropertyValue('--quill-text-gradient')) {
            const background = style.getPropertyValue('--quill-background-paint');
            for (const name of ['--quill-text-gradient', '--quill-background-paint', 'background-image', 'background-clip', '-webkit-background-clip', '-webkit-text-fill-color']) style.removeProperty(name);
            if (background && background !== 'none') style.backgroundImage = background;
        } else if (property === 'background-color') {
            if (style.getPropertyValue('--quill-background-paint')) style.setProperty('--quill-background-paint', 'none');
            else if (/gradient\(/.test(style.backgroundImage)) style.removeProperty('background-image');
        } else if (property === 'border-color') { style.removeProperty('border-image-source'); style.removeProperty('border-image-slice'); }
    });
}

export function colorControl(property, label, editor, signal) {
    const element = document.createElement('div'); element.className = 'editor-paint-control';
    const input = document.createElement('input'); input.type = 'text'; input.dataset.style = property;
    input.setAttribute('data-coloris', ''); input.className = 'editor-color-swatch'; input.setAttribute('aria-label', label); input.spellcheck = false;
    input.addEventListener('input', () => applyPaint(editor, property, input.value.trim()), { signal });
    element.append(window.QuillEditorColorPicker.wrap(input));
    const supportsGradient = ['background-color', 'color', 'border-color'].includes(property);
    if (editor.palette) element.append(editor.palette.button({
        current: () => { const {style, computed} = paintContext(editor); return gradientValue(property, style, computed) || input.value || computed.getPropertyValue(property) || '#2ecc71'; },
        apply: (value, gradient) => {
            applyPaint(editor, property, value, gradient);
            if (!gradient) input.value = value;
            input.style.backgroundColor = gradient ? 'transparent' : value;
            input.style.backgroundImage = gradient ? value : 'none';
            input.title = `${label}: ${value}`;
            window.QuillEditorColorPicker.update(input);
        },
        signal, gradients: supportsGradient, label: `Saved colors: ${label}`,
    }));
    let stopsAbort = new AbortController();
    signal.addEventListener('abort', () => stopsAbort.abort(), {once:true});
    let dialog, launch, render, source, message, preview;
    let type = 'linear', angle = 90, stops = [{color:'#2ecc71', at:0}, {color:'#3498db', at:100}];
    const build = () => `${type}-gradient(${type === 'linear' ? angle + 'deg, ' : type === 'conic' ? 'from ' + angle + 'deg, ' : 'circle, '}${[...stops].sort((a,b) => a.at-b.at).map(stop => `${stop.color} ${stop.at}%`).join(', ')})`;
    const parse = value => {
        const match = value.match(/^(linear|radial|conic)-gradient\((.*)\)$/s); if (!match) return;
        type = match[1]; const entries = splitCss(match[2]);
        const first = entries[0].match(/^(?:from )?(-?[\d.]+)deg$/); if (first) { angle = Number(first[1]); entries.shift(); }
        else if (entries[0] === 'circle') entries.shift();
        const parsed = entries.map((entry,index) => {
            const position = entry.match(/^(.*)\s+(-?[\d.]+)%$/s);
            return {color:position ? position[1] : entry, at:position ? Number(position[2]) : index * 100 / (entries.length-1)};
        });
        if (parsed.length >= 2 && parsed.every(stop => CSS.supports('color', stop.color))) stops = parsed;
    };
    const applyGradient = () => {
        const value = build();
        if (!CSS.supports('background-image',value)) { message.textContent = 'Check the gradient colors and stops.'; return; }
        message.textContent = ''; source.value = value; preview.style.backgroundImage = value; applyPaint(editor, property, value, true);
    };
    if (supportsGradient) {
        launch = document.createElement('button'); launch.type = 'button'; launch.className = 'editor-gradient-launch';
        launch.setAttribute('aria-label', `Edit ${label.toLowerCase()} gradient`); launch.title = 'Gradient'; launch.dataset.gradientFor = property;
        launch.innerHTML = '<i class="fa-solid fa-fill-drip" aria-hidden="true"></i>'; element.append(launch);
        dialog = document.createElement('dialog'); dialog.className = 'editor-gradient-dialog';
        const heading = document.createElement('h2'); heading.textContent = `${label} gradient`;
        dialog.setAttribute('aria-label', heading.textContent);
        preview = document.createElement('div'); preview.className = 'editor-gradient-preview'; preview.setAttribute('aria-hidden', 'true');
        const close = document.createElement('button'); close.type = 'button'; close.className = 'editor-image-close'; window.QuillEditorIcon.set(close, 'xmark'); close.setAttribute('aria-label','Close gradient editor');
        const kind = document.createElement('select'); kind.setAttribute('aria-label','Gradient type');
        ['linear','radial','conic'].forEach(value => kind.add(new Option(({linear:'Linear',radial:'Radial',conic:'Angular'})[value],value)));
        const rotation = document.createElement('input'); rotation.type = 'number'; rotation.setAttribute('aria-label','Gradient angle'); rotation.dataset.gradientAngle = '';
        const list = document.createElement('div'); list.className = 'editor-gradient-stops';
        const add = document.createElement('button'); add.type = 'button'; add.textContent = 'Add color stop';
        const solid = document.createElement('button'); solid.type = 'button'; solid.textContent = 'Use solid color';
        const confirm = document.createElement('button'); confirm.type = 'button'; confirm.className = 'button-primary'; confirm.textContent = 'Apply gradient';
        source = document.createElement('textarea'); source.rows = 3; source.setAttribute('aria-label','Custom gradient value'); source.dataset.gradientCss = '';
        message = document.createElement('p'); message.setAttribute('role','status');
        const hint = document.createElement('p'); hint.className = 'editor-viewport-note'; hint.textContent = property === 'border-color' ? 'Gradient outlines have square corners.' : 'Changes appear immediately. Choose colors and adjust where they blend.';
        const advanced=document.createElement('details');const summary=document.createElement('summary');summary.textContent='More options';advanced.append(summary,source);
        dialog.append(heading,close,preview,kind,rotation,list,add,advanced,confirm,solid,hint,message); document.body.append(dialog);
        render = () => {
            stopsAbort.abort(); stopsAbort = new AbortController();
            kind.value = type; rotation.value = angle; rotation.hidden = type === 'radial';
            const fragment = document.createDocumentFragment();
            stops.forEach((stop,index) => {
                const row = document.createElement('div'); row.className = 'editor-gradient-stop';
                const color = document.createElement('input'); color.type = 'text'; color.value = stop.color; color.setAttribute('aria-label',`Stop ${index+1} color`);
                const picker = document.createElement('input'); picker.type = 'text'; picker.className = 'editor-color-swatch'; picker.setAttribute('data-coloris', ''); picker.value = stop.color; picker.style.backgroundColor = stop.color; picker.setAttribute('aria-label',`Choose stop ${index+1} color`);
                const position = document.createElement('input'); position.type = 'number'; position.value = stop.at; position.min='0'; position.max='100'; position.setAttribute('aria-label',`Stop ${index+1} position (%)`);
                const remove = document.createElement('button'); remove.type='button'; window.QuillEditorIcon.set(remove, 'xmark'); remove.setAttribute('aria-label',`Remove stop ${index+1}`); remove.disabled=stops.length<=2;
                color.addEventListener('input',()=>{stop.color=color.value;if(CSS.supports('color',stop.color)){picker.value=stop.color;picker.style.backgroundColor=stop.color;window.QuillEditorColorPicker.update(picker);}applyGradient();});
                picker.addEventListener('input',()=>{stop.color=picker.value;color.value=picker.value;picker.style.backgroundColor=picker.value;applyGradient();});
                position.addEventListener('input',()=>{stop.at=Number(position.value);applyGradient();});
                remove.addEventListener('click',()=>{stops.splice(index,1);render();applyGradient();});
                row.append(window.QuillEditorColorPicker.wrap(picker),color,position);
                if (editor.palette) row.append(editor.palette.button({current:()=>stop.color, apply:value=>{stop.color=value;color.value=value;picker.value=value;picker.style.backgroundColor=value;window.QuillEditorColorPicker.update(picker);applyGradient();}, signal:stopsAbort.signal, label:`Saved colors: gradient stop ${index+1}`}));
                row.append(remove);fragment.append(row);
            });
            list.replaceChildren(fragment);source.value=build();preview.style.backgroundImage=source.value;message.textContent='';
        };
        launch.addEventListener('click',()=>{
            const {style,computed}=paintContext(editor), existing=gradientValue(property,style,computed);
            if(existing) parse(existing);
            render(); dialog.showModal(); kind.focus();
        },{signal});
        kind.addEventListener('change',()=>{type=kind.value;rotation.hidden=type==='radial';applyGradient();},{signal});
        rotation.addEventListener('input',()=>{angle=Number(rotation.value);applyGradient();},{signal});
        add.addEventListener('click',()=>{stops.push({color:'#ffffff',at:50});render();applyGradient();},{signal});
        source.addEventListener('input',()=>{
            const value=source.value.trim();
            if (!CSS.supports('background-image',value) || !/^(linear|radial|conic)-gradient\(/.test(value)) {message.textContent='Check the custom gradient value.';return;}
            message.textContent='';parse(value);preview.style.backgroundImage=value;applyPaint(editor,property,value,true);
        },{signal});
        confirm.addEventListener('click',()=>{const value=source.value.trim();if(CSS.supports('background-image',value)&&/^(linear|radial|conic)-gradient\(/.test(value)){applyPaint(editor,property,value,true);dialog.close();}else message.textContent='Check the custom gradient value.';},{signal});
        solid.addEventListener('click',()=>{applyPaint(editor,property,input.value||'#2ecc71');dialog.close();},{signal});
        close.addEventListener('click',()=>dialog.close(),{signal});
        signal.addEventListener('abort',()=>{dialog.close();dialog.remove();},{once:true});
    }
    return {element,input,reset:()=>resetPaint(editor,property),set(value,force,computed){
        const {style,computed:rendered}=paintContext(editor),gradient=gradientValue(property,style,computed||rendered);
        if(force && dialog?.open) dialog.close();
        if(force || input!==document.activeElement) {
            const resolved = value || (computed || rendered).getPropertyValue(property);
            input.value = !value && (!resolved || isTransparent(resolved)) ? defaultColor(property) : resolved;
        }
        input.style.backgroundColor=value||(computed||rendered).getPropertyValue(property)||input.value||'transparent';input.style.backgroundImage=gradient||'none';input.title=`${label}: ${gradient||input.value||'Default'}`;
        window.QuillEditorColorPicker.update(input);
        launch?.setAttribute('aria-pressed',String(!!gradient));
    }};
}
