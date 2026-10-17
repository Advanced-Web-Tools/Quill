import { splitCss } from './paint-control.js';

export function trackControl(property,label,apply,signal) {
    const element=document.createElement('div');element.className='editor-track-control';
    const count=document.createElement('input');count.type='number';count.min='1';count.max='24';count.step='1';count.placeholder='Automatic';count.setAttribute('aria-label',`${label} count`);count.dataset.trackCount=property;
    const more=document.createElement('details');const summary=document.createElement('summary');summary.textContent='More options';
    const input=document.createElement('input');input.type='text';input.placeholder='Custom layout';input.dataset.style=property;input.setAttribute('aria-label',`Custom ${label.toLowerCase()}`);
    more.append(summary,input);element.append(count,more);
    count.addEventListener('input',()=>{
        if(!count.value){apply('');return;}
        const value=Number(count.value);if(!Number.isInteger(value)||value<1||value>24)return;
        apply(`repeat(${value}, minmax(0, 1fr))`);
    },{signal});
    input.addEventListener('input',()=>apply(input.value.trim()),{signal});
    return {element,input:count,set(value,force,computed){
        if(force||input!==document.activeElement)input.value=value;
        const resolved=value||computed?.getPropertyValue(property)||'';
        const repeated=resolved.match(/^repeat\(\s*(\d+)\s*,/);
        const tracks=splitCss(resolved,' ');
        const simple=tracks.length&&tracks.every(value=>/^(?:[\d.]+(?:px|fr|%)|auto|minmax\(.*\))$/.test(value));
        if(force||count!==document.activeElement)count.value=repeated?repeated[1]:simple?String(tracks.length):'';
        if(force)more.open=!!value&&!repeated&&!simple;
    }};
}
export function opacityControl(apply,signal) {
    const element=document.createElement('div');element.className='editor-percent-control';
    const input=document.createElement('input');input.type='number';input.min='0';input.max='100';input.step='1';input.dataset.style='opacity';input.setAttribute('aria-label','Opacity percentage');
    const unit=document.createElement('span');unit.textContent='%';unit.setAttribute('aria-hidden','true');element.append(input,unit);
    input.addEventListener('input',()=>{if(input.value===''){apply('');return;}const value=Number(input.value);if(value>=0&&value<=100)apply(String(value/100));},{signal});
    return {element,input,set(value,force,computed){if(force||input!==document.activeElement)input.value=String(Math.round(Number(value||computed?.opacity||1)*100));}};
}
