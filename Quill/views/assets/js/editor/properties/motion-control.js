const curves = { smooth:'cubic-bezier(0.2, 0.8, 0.2, 1)', gentle:'ease-in-out', spring:'cubic-bezier(0.34, 1.56, 0.64, 1)', linear:'linear' };
const animated = 'all';
const milliseconds = value => {const first=String(value||'0s').split(',')[0].trim();return Math.round(parseFloat(first)*(first.endsWith('ms')?1:1000))||0;};

export function motionControl(editor,signal) {
    const element=document.createElement('div');element.className='editor-change-motion';
    const label=document.createElement('label');label.className='editor-motion-link';
    const enabled=document.createElement('input');enabled.type='checkbox';enabled.dataset.animateChanges='';label.append(enabled,' Animate changes');element.append(label);
    const fields=document.createElement('div');fields.className='editor-change-motion-fields';
    const make=(title,key,options=null)=>{
        const caption=document.createElement('label');caption.className='editor-action-field';caption.textContent=title;
        const input=document.createElement(options?'select':'input');input.dataset.changeMotion=key;input.setAttribute('aria-label',title);
        if(options)Object.entries(options).forEach(([value,label])=>input.add(new Option(label,value)));
        else {input.type='number';input.min='0';input.max='10000';input.step='10';}
        caption.append(input);fields.append(caption);return input;
    };
    const duration=make('Duration (ms)','duration');duration.value='250';
    const easing=make('Motion','easing',{smooth:'Smooth',gentle:'Gentle',spring:'Spring',linear:'Steady'});
    const delay=make('Delay (ms)','delay');delay.value='0';
    const hint=document.createElement('p');hint.className='editor-group-description';hint.textContent='Smooth changes to size, position, colors and appearance. Set this in Default to animate both entering and leaving hover or focus.';
    const reset=document.createElement('button');reset.type='button';reset.textContent='Reset animation';
    element.append(fields,hint,reset);
    const save=()=>{
        try {
            for(const input of [duration,delay])if(!Number.isFinite(Number(input.value))||Number(input.value)<0||Number(input.value)>10000)throw new Error('Choose a duration or delay between 0 and 10000 ms.');
            const style=editor.getStyleDeclarations();
            style.transitionProperty=enabled.checked?animated:'none';
            style.transitionDuration=`${Number(duration.value)}ms`;style.transitionDelay=`${Number(delay.value)}ms`;style.transitionTimingFunction=curves[easing.value];
            editor.setStyleDeclarations(style.cssText);editor.reportError(null);fields.hidden=!enabled.checked;
        } catch(error){editor.reportError(error);}
    };
    enabled.addEventListener('change',save,{signal});
    [duration,easing,delay].forEach(input=>input.addEventListener('input',save,{signal}));
    reset.addEventListener('click',()=>{try{const style=editor.getStyleDeclarations();style.removeProperty('transition');for(const property of ['property','duration','delay','timing-function'])style.removeProperty('transition-'+property);editor.setStyleDeclarations(style.cssText);editor.reportError(null);}catch(error){editor.reportError(error);}},{signal});
    return {element,update(style,force,computed){
        if(!force&&element.contains(document.activeElement))return;
        const property=style.transitionProperty||computed.transitionProperty;
        const time=style.transitionDuration||computed.transitionDuration;
        const authored=!!(style.transitionProperty||style.transitionDuration||style.transition);
        enabled.checked=property!=='none'&&(authored||String(time).split(',').some(value=>milliseconds(value)>0));
        duration.value=String(authored?milliseconds(time):milliseconds(time)||250);delay.value=String(milliseconds(style.transitionDelay||computed.transitionDelay));
        const curve=style.transitionTimingFunction||computed.transitionTimingFunction;
        easing.value=Object.keys(curves).find(key=>curves[key]===curve)||'smooth';
        fields.hidden=!enabled.checked;
    }};
}
