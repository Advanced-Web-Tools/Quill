const common = ['id','class','title','aria-label','name','value','href','src','alt','role'];
const contextual = {
    img: ['src','alt','title','width','height','loading','decoding','fetchpriority','crossorigin','referrerpolicy'],
    a: ['href','target','title','rel','download','aria-label','id','class','hreflang','type'],
    video: ['src','poster','controls','autoplay','loop','muted','preload','playsinline','width','height'],
    input: ['type','name','value','placeholder','required','disabled','readonly','autocomplete','min','max'],
    button: ['type','disabled','name','value','title','aria-label','id','class','form','formaction'],
};
export function mountAttributeNamePicker(select, { names, tag, signal }) {
    const make = (tag, cls = '', text = '') => { const node = document.createElement(tag); node.className = cls; node.textContent = text; return node; };
    const top = [...new Set([...(contextual[tag?.toLowerCase()] || []), ...common])].filter(name => names.includes(name)).slice(0,10);
    const wrapper = make('div','editor-attribute-name-control'); select.after(wrapper); select.hidden = true;
    const trigger = make('button','editor-attribute-name-trigger'); trigger.type = 'button'; trigger.id=`${select.id}-picker`;select.parentElement.querySelector('label')?.setAttribute('for',trigger.id); trigger.setAttribute('aria-haspopup','menu'); trigger.setAttribute('aria-expanded','false'); trigger.setAttribute('aria-label','Choose attribute');
    const caption = make('span'), arrow = make('i','fa-solid fa-chevron-down'); arrow.setAttribute('aria-hidden','true'); trigger.append(caption,arrow);
    const popup = make('div','editor-attribute-name-popup'); popup.id = `attributes-${crypto.randomUUID()}`; popup.setAttribute('popover','auto'); trigger.setAttribute('aria-controls',popup.id);
    const main = make('div','editor-attribute-name-main'), more = make('div','editor-attribute-name-others'); more.hidden = true;
    main.role = 'menu'; main.setAttribute('aria-label','Common attributes');
    const back = make('button','editor-attribute-name-back','‹ Attributes'); back.type = 'button';
    const search = make('input'); search.type = 'search'; search.placeholder = 'Find an attribute…'; search.setAttribute('aria-label','Search other attributes');
    const choices = make('div','editor-attribute-name-results'); choices.role = 'menu'; choices.setAttribute('aria-label','Other attributes');
    const empty = make('p','editor-group-description','No matching attributes.'); empty.hidden = true;
    more.append(back,search,choices,empty); popup.append(main,more); wrapper.append(trigger,popup);
    const update = () => { caption.textContent = select.value === '__custom__' ? 'Custom attribute…' : select.value || 'Choose an attribute…'; };
    const choose = value => {
        select.value = value; select.dispatchEvent(new Event('change',{bubbles:true})); update();
        if (popup.matches(':popover-open')) popup.hidePopover();
        if (value === '__custom__') select.closest('.editor-data-attribute')?.querySelector('[data-data-attribute-custom]')?.focus();
        else trigger.focus();
    };
    function option(parent, value) {
        const button = make('button','editor-attribute-name-option',value); button.type = 'button'; button.role = 'menuitem'; button.dataset.attributeOption = value;
        button.addEventListener('click',()=>choose(value),{signal}); parent.append(button); return button;
    }
    for (const name of top) { const button=option(main,name); button.dataset.attributeTop=''; }
    const others = make('button','editor-attribute-name-more'); others.type='button'; others.role='menuitem'; others.setAttribute('aria-haspopup','menu'); others.setAttribute('aria-expanded','false');
    others.append(make('span','','Others'),make('i','fa-solid fa-chevron-right')); main.append(others);
    const customButton=make('button','editor-attribute-name-option','Custom attribute…');customButton.type='button';customButton.role='menuitem';customButton.addEventListener('click',()=>choose('__custom__'),{signal});main.append(customButton);
    const showOthers = show => { main.hidden=show; more.hidden=!show; others.setAttribute('aria-expanded',String(show)); if(show){search.value='';filter();search.focus();}else others.focus(); };
    function filter() {
        choices.replaceChildren();
        const matches=names.filter(name=>!top.includes(name)&&name.toLowerCase().includes(search.value.toLowerCase()));
        matches.forEach(name=>option(choices,name)); empty.hidden=matches.length>0;
    }
    others.addEventListener('click',()=>showOthers(true),{signal}); back.addEventListener('click',()=>showOthers(false),{signal}); search.addEventListener('input',filter,{signal});
    const open = () => {
        if(popup.matches(':popover-open')){popup.hidePopover();return;}
        main.hidden=false;more.hidden=true;others.setAttribute('aria-expanded','false');popup.showPopover();
        const rect=trigger.getBoundingClientRect(),width=Math.min(280,innerWidth-24);popup.style.width=`${width}px`;
        popup.style.left=`${Math.max(12,Math.min(rect.left,innerWidth-width-12))}px`;
        popup.style.top=`${rect.bottom+4}px`;const height=popup.getBoundingClientRect().height;
        if(rect.bottom+height+8>innerHeight)popup.style.top=`${Math.max(12,rect.top-height-4)}px`;
        main.querySelector('button')?.focus();
    };
    trigger.addEventListener('click',open,{signal}); trigger.addEventListener('keydown',event=>{if(event.key==='ArrowDown'){event.preventDefault();open();}},{signal});
    popup.addEventListener('toggle',()=>trigger.setAttribute('aria-expanded',String(popup.matches(':popover-open'))),{signal});
    popup.addEventListener('keydown',event=>{
        if(event.key==='Escape'&&!more.hidden){event.preventDefault();showOthers(false);return;}
        if(!['ArrowDown','ArrowUp','Home','End'].includes(event.key)||event.target===search&&event.key!=='ArrowDown')return;
        const list=[...(more.hidden?main:choices).querySelectorAll('button')];if(!list.length)return;
        const index=list.indexOf(document.activeElement),next=event.key==='Home'?0:event.key==='End'?list.length-1:(index+(event.key==='ArrowDown'?1:-1)+list.length)%list.length;
        event.preventDefault();list[next].focus();
    },{signal});
    select.addEventListener('change',update,{signal}); signal.addEventListener('abort',()=>{if(popup.matches(':popover-open'))popup.hidePopover();wrapper.remove();},{once:true});update();
}
