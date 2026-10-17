import { mailDestination, readMail, routeDestination, matchRoute, assertLink } from './link-destinations.js';

const kinds = [['web','Web address'],['route','Page on this site'],['email','Email'],['phone','Phone call'],['sms','Text message'],['calendar','Calendar'],['app','Open an app'],['anchor','Section on this page']];
const targets = [
    ['','Browser default','Uses the current tab, unless the page defines another default.'],
    ['_self','Current tab','Replaces the page in the current tab. Inside an embedded page, only that frame changes.'],
    ['_blank','New tab or window','Opens a separate tab or window, according to the visitor’s browser settings.'],
    ['_parent','Parent frame','If this page is embedded, opens in its containing frame. Otherwise, uses the current tab.'],
    ['_top','Full browser window','If this page is embedded, replaces the entire browser page, including the surrounding frames.'],
];
const apps = [['custom','Another app'],['whatsapp','WhatsApp'],['telegram','Telegram'],['maps','Google Maps'],['facetime','FaceTime']];

export default function ({container, editor, signal}) {
    let routes = editor.routes || [];
    let kind='web', lastHref=null, routeSuffix='', smsSuffix='', appSuffix='', mailExtras={}, selectedPath=null, activeRoute=null;
    const fields = new Map(), areas = new Map();
    let refreshing = false;
    const section = (title, open=true) => {
        const group=document.createElement('details');group.className='editor-style-group';group.open=open;
        const summary=document.createElement('summary');const caption=document.createElement('span');caption.textContent=title;
        const chevron=document.createElement('i');chevron.className='fa-solid fa-chevron-down';chevron.setAttribute('aria-hidden','true');
        summary.append(caption,chevron);group.append(summary);container.append(group);return group;
    };
    const note = (parent,text) => {const element=document.createElement('p');element.className='editor-group-description';element.textContent=text;parent.append(element);return element;};
    const field = (parent,key,title,options=null,multiline=false) => {
        const row=document.createElement('div');row.className='editor-link-field';
        const label=document.createElement('label');label.textContent=title;
        const input=document.createElement(options?'select':multiline?'textarea':'input');
        input.id=`${container.id}-link-${key}`;input.dataset.linkField=key;input.setAttribute('aria-label',title);label.htmlFor=input.id;
        if(options)options.forEach(([value,text])=>input.add(new Option(text,value)));
        else {if(multiline)input.rows=3;else input.type='text';input.autocomplete='off';}
        row.append(label,input);parent.append(row);fields.set(key,input);return input;
    };
    const destination=section('Destination');
    const type=field(destination,'type','Link to',kinds);
    const makeArea = key => {const area=document.createElement('div');area.className='editor-link-fields';destination.append(area);areas.set(key,area);return area;};
    const web=field(makeArea('web'),'url','Address');web.dataset.attribute='href';web.placeholder='https://example.com or /about';
    const routeArea=makeArea('route');
    const routeChoice=field(routeArea,'route','Choose a page',[['','Choose a route…'],...routes.map(route=>[route.path,route.name===route.path?route.path:`${route.name} — ${route.path}`])]);
    const routeFields=document.createElement('div');routeFields.className='editor-link-fields';routeArea.append(routeFields);
    const routeHint=note(routeArea,routes.length?'Fill in any details to create the final address.':'No page routes are registered. You can enter a web address instead.');
    routeChoice.disabled=!routes.length;
    editor.on?.('routeschange',()=>{
        routes=editor.routes||[];const selected=routeChoice.value;
        routeChoice.replaceChildren(new Option('Choose a route…',''),...routes.map(route=>new Option(route.name===route.path?route.path:`${route.name} — ${route.path}`,route.path)));
        routeChoice.value=selected;routeChoice.disabled=!routes.length;
        routeHint.textContent=routes.length?'Fill in any details to create the final address.':'No page routes are registered. You can enter a web address instead.';
    },{signal});
    const email=makeArea('email');field(email,'email','Email address').placeholder='hello@example.com';field(email,'subject','Subject (optional)');field(email,'body','Message (optional)',null,true);
    note(email,'Opens a new message in the visitor’s email app.');
    field(makeArea('phone'),'phone','Phone number').placeholder='+381 64 123 4567';
    note(areas.get('phone'),'Include the country code. Opens the visitor’s calling app.');
    field(makeArea('sms'),'sms','Phone number').placeholder='+381 64 123 4567';
    note(areas.get('sms'),'Opens the visitor’s messaging app with this recipient.');
    const calendar=makeArea('calendar');
    field(calendar,'calendar-mode','Calendar action',[['subscribe','Subscribe to a calendar'],['file','Open a calendar file']]);
    field(calendar,'calendar','Calendar address').placeholder='https://example.com/calendar.ics';
    const calendarHint=note(calendar,'');
    const app=makeArea('app');field(app,'app','Application',apps);const appAddress=field(app,'app-address','App link');
    const appHint=note(app,'');
    const anchor=field(makeArea('anchor'),'anchor','Section name');anchor.placeholder='contact';
    note(areas.get('anchor'),'Use the section’s identifier from Details. The link scrolls to that section on this page.');
    const preview=document.createElement('output');preview.className='editor-link-preview';preview.setAttribute('aria-label','Link destination');destination.append(preview);
    const opening=section('Open in');const target=field(opening,'target','Where to open',targets.map(([value,label])=>[value,label]));target.dataset.attribute='target';
    const targetHint=note(opening,'');
    const advanced=section('More options',false);
    const rel=field(advanced,'rel','Link relationship (optional)');rel.dataset.attribute='rel';rel.placeholder='e.g. nofollow sponsored';
    note(advanced,'Optional instructions for browsers and search engines. Opening a new tab also adds “noopener” to keep the pages separate.');
    const downloadLabel=document.createElement('label');downloadLabel.className='editor-link-toggle';
    const download=document.createElement('input');download.type='checkbox';download.dataset.linkDownload='';downloadLabel.append(download,' Download this file');advanced.append(downloadLabel);
    const filename=field(advanced,'download','Save as (optional)');filename.dataset.attribute='download';
    note(advanced,'For file links. The browser may ignore downloads from another website; the server can also choose a filename.');
    const get=key=>fields.get(key).value;
    const put=(key,value)=>{const input=fields.get(key);if(refreshing||input!==document.activeElement)input.value=value??'';};
    const attempt = action => {try{action();editor.reportError(null);}catch(error){editor.reportError(error);}};
    function saveHref(value) {
        assertLink(value);
        if (kind==='app' && get('app')==='custom' && value && !/^[a-z][a-z\d+.-]*:/i.test(value)) throw new Error('Paste the complete link provided by the app, including its prefix.');
        lastHref=value;
        if((editor.getSelectedBlock()?.attributes.href||'')!==value)editor.setAttribute('href',value||null);
        preview.value=value||'Choose a destination';
    }
    function build() {
        const number=value=>value.trim().replace(/[\s()\-]/g,'');
        if(kind==='web')return get('url').trim();
        if(kind==='route')return routeDestination(activeRoute,Object.fromEntries([...routeFields.querySelectorAll('input')].map(input=>[input.dataset.routeParameter,input.value])),routeSuffix);
        if(kind==='email')return mailDestination(get('email'),get('subject'),get('body'),mailExtras);
        if(kind==='phone'||kind==='sms')return get(kind).trim()?`${kind==='phone'?'tel':'sms'}:${number(get(kind))}${kind==='sms'?smsSuffix:''}`:'';
        if(kind==='calendar') {
            const value=get('calendar').trim();if(!value)return '';
            if(!/^(?:https?|webcals?):\/\//i.test(value))throw new Error('Enter the complete calendar address, starting with https://.');
            return get('calendar-mode')==='subscribe'?value.replace(/^(?:https?|webcals?):/i,'webcal:'):value.replace(/^webcal:/i,'https:').replace(/^webcals:/i,'https:');
        }
        if(kind==='anchor')return get('anchor').trim()?'#'+encodeURIComponent(get('anchor').trim().replace(/^#/,'')):'';
        const value=get('app-address').trim();if(!value)return '';
        switch(get('app')) {
            case 'whatsapp':{const phone=number(value).replace(/^\+/,'');if(!/^\d+$/.test(phone))throw new Error('Enter a phone number with its country code.');return 'https://wa.me/'+phone+appSuffix;}
            case 'telegram':return 'https://t.me/'+encodeURIComponent(value.replace(/^@/,''))+appSuffix;
            case 'maps':return 'https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(value);
            case 'facetime':return 'facetime:'+value.replace(/\s/g,'');
            default:return value;
        }
    }
    function visibility() {
        type.value=kind;
        areas.forEach((area,key)=>{area.hidden=kind!==key;});
        const native=['email','phone','sms','app'].includes(kind)||(kind==='calendar'&&get('calendar-mode')==='subscribe');
        opening.hidden=native;
        targetHint.textContent=targets.find(([value])=>value===target.value)?.[2]||'Opens the named browser window or frame.';
        calendarHint.textContent=get('calendar-mode')==='subscribe'?'Opens a calendar subscription in a compatible calendar app.':'Opens an .ics calendar file. The visitor’s browser decides whether to open or download it.';
        const appType=get('app');
        const labels={custom:['App link','spotify:track:…'],whatsapp:['Phone number','Country code and phone number'],telegram:['Username','username'],maps:['Place or address','Belgrade, Serbia'],facetime:['Email or phone number','hello@example.com']};
        const [label,placeholder]=labels[appType];appAddress.previousElementSibling.textContent=label;appAddress.setAttribute('aria-label',label);appAddress.placeholder=placeholder;
        appHint.textContent=appType==='facetime'?'Opens FaceTime on a compatible device with the app installed.':appType==='custom'?'Paste a link from the app. The visitor needs an app that supports it.':'The visitor’s device chooses the app or web version. Availability depends on the device and installed apps.';
        fields.get('download').parentElement.hidden=!download.checked;
    }
    function renderRoute(route, values={}) {
        activeRoute=route;routeChoice.value=route?.path||'';routeFields.replaceChildren();
        for(const parameter of route?.parameters||[]) {
            const input=field(routeFields,'route-'+parameter,parameter.replace(/[_-]/g,' ').replace(/^./,value=>value.toUpperCase()));
            input.dataset.routeParameter=parameter;input.value=values[parameter]||'';
        }
    }
    type.addEventListener('change',()=>attempt(()=>{kind=type.value;routeSuffix='';smsSuffix='';appSuffix='';mailExtras={};visibility();saveHref(build());}),{signal});
    routeChoice.addEventListener('change',()=>attempt(()=>{routeSuffix='';renderRoute(routes.find(route=>route.path===routeChoice.value));saveHref(build());}),{signal});
    destination.addEventListener('input',event=>{
        if([type,routeChoice].includes(event.target))return;
        if(event.target===fields.get('app')) { appSuffix=''; appAddress.value=''; }
        attempt(()=>{visibility();saveHref(build());});
    },{signal});
    target.addEventListener('input',()=>attempt(()=>{
        const attributes={...editor.getSelectedBlock().attributes};
        if(target.value)attributes.target=target.value;else delete attributes.target;
        if(target.value==='_blank')attributes.rel=[...new Set((attributes.rel||'').split(/\s+/).filter(Boolean).concat('noopener'))].join(' ');
        editor.updateBlock({attributes});visibility();
    }),{signal});
    rel.addEventListener('input',()=>attempt(()=>editor.setAttribute('rel',rel.value.trim()||null)),{signal});
    const saveDownload=()=>attempt(()=>{editor.setAttribute('download',download.checked?filename.value:null);visibility();});
    download.addEventListener('change',saveDownload,{signal});filename.addEventListener('input',saveDownload,{signal});
    function read(href) {
        routeSuffix='';smsSuffix='';appSuffix='';mailExtras={};kind='web';put('url',href);
        if(/^mailto:/i.test(href)){kind='email';const mail=readMail(href);put('email',mail.address);put('subject',mail.subject);put('body',mail.body);mailExtras=mail.extras;}
        else if(/^(tel|sms):/i.test(href)){kind=/^tel:/i.test(href)?'phone':'sms';const recipient=href.slice(href.indexOf(':')+1);const split=kind==='sms'?recipient.indexOf('?'):-1;smsSuffix=split>=0?recipient.slice(split):'';put(kind,split>=0?recipient.slice(0,split):recipient);}
        else if(/^webcals?:\/\//i.test(href)){kind='calendar';put('calendar-mode','subscribe');put('calendar',href);}
        else if(/^https?:\/\/[^?#]+\.ics(?:[?#]|$)/i.test(href)){kind='calendar';put('calendar-mode','file');put('calendar',href);}
        else if(href.startsWith('#')){kind='anchor';try{put('anchor',decodeURIComponent(href.slice(1)));}catch{put('anchor',href.slice(1));}}
        else {
            let url;try{url=new URL(href);}catch{}
            if(url?.hostname==='wa.me'){kind='app';put('app','whatsapp');put('app-address',url.pathname.slice(1));appSuffix=url.search+url.hash;}
            else if(url?.hostname==='t.me'){kind='app';put('app','telegram');put('app-address',url.pathname.slice(1));appSuffix=url.search+url.hash;}
            else if(url?.hostname==='www.google.com'&&url.pathname==='/maps/search/'){kind='app';put('app','maps');put('app-address',url.searchParams.get('query')||'');}
            else if(/^facetime:/i.test(href)){kind='app';put('app','facetime');put('app-address',href.slice(9));}
            else if(/^[a-z][a-z\d+.-]*:/i.test(href)&&! /^(https?|javascript|data|vbscript|file|blob):/i.test(href)){kind='app';put('app','custom');put('app-address',href);}
            else {const matched=matchRoute(routes,href);if(matched){kind='route';renderRoute(matched.route,matched.values);routeSuffix=matched.suffix;}}
        }
    }
    return {update(block,context) {
        const href=String(block.attributes.href||'');
        if(context.selectionChanged||selectedPath!==editor.selectedPath||href!==lastHref) {
            refreshing=true;
            if(selectedPath!==editor.selectedPath||context.selectionChanged) {fields.forEach(input=>{if(input.tagName!=='SELECT')input.value='';});renderRoute(null);}
            read(href);refreshing=false;lastHref=href;selectedPath=editor.selectedPath;
        }
        const value=block.attributes.target||'';
        if(value&&![...target.options].some(option=>option.value===value))target.add(new Option(`Named window: ${value}`,value));
        put('target',value);put('rel',block.attributes.rel||'');download.checked=Object.hasOwn(block.attributes,'download')&&block.attributes.download!==false;
        put('download',typeof block.attributes.download==='string'?block.attributes.download:'');
        preview.value=href||'Choose a destination';visibility();
    }};
}
