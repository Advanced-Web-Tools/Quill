import { mountQueryBuilder } from './query-builder.js';
import { mountAttributeMapping } from './data-attribute-mapping.js';

const renderer = 'Quill\\services\\rendering\\ConnectedDataRenderer';
const owned = ['inherit','source','select','default_value','bind','mode','show','sort','sort_by','set','query'];
const voidTags = new Set(['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr','script','style']);

export default function ({container, editor, signal}) {
    container.classList.add('editor-connect-data');
    let block = null, refreshing = false, signature = null, renderedPath = null, lastCommittedMappings = null, queryValue = null;
    const attributeViews = new WeakMap();
    let targetPath='',targetId=null,anchorPath=null,renderedTarget=null;
    const nodeAt=(root,path)=>path?path.split('.').reduce((node,index)=>node?.children?.[Number(index)],root):root;
    const targetBlock=()=>nodeAt(block,targetPath)||block;
    const identity=node=>node?.editor?.historyId||node?.editor?.urpcUid||null;
    function connectionOwner(){
        let path=editor.selectedPath,node=block;
        if(node?.descriptor.props.inherit&&editor.getBlock){
            const parts=path.split('.');while(parts.length>2){parts.pop();const candidate=editor.getBlock(parts.join('.'));if(candidate.descriptor.renderer.replace(/^\\/,'')===renderer&&!candidate.descriptor.props.inherit){path=parts.join('.');node=candidate;break;}}
        }
        return {path,node};
    }
    const sources = editor.dataSources || [];
    let routes = editor.dataRoutes || [], parameters = [...new Set(routes.flatMap(route => route.parameters))];
    const make = (tag, className, text) => {const node=document.createElement(tag);if(className)node.className=className;if(text)node.textContent=text;return node;};
    const note = (parent, text, emphasis=false) => {const node=make('p',emphasis?'editor-data-notice':'editor-group-description',text);parent.append(node);return node;};
    const group = title => {const node=make('details','editor-style-group');node.open=true;const summary=make('summary');const icon=make('i','fa-solid fa-chevron-down');icon.setAttribute('aria-hidden','true');summary.append(make('span','',title),icon);node.append(summary);container.append(node);return node;};
    const field = (parent, key, title, options=null) => {
        const row=make('div','editor-link-field'), label=make('label','',title), input=make(options?'select':'input');
        input.id=`${container.id}-data-${key}`;input.dataset.dataField=key;input.setAttribute('aria-label',title);label.htmlFor=input.id;
        if(options)for(const [value,text] of options)input.add(new Option(text,value));
        else input.autocomplete='off';
        row.append(label,input);parent.append(row);return input;
    };
    const fill = (select, options, value) => {
        select.replaceChildren(...options.map(([key,label])=>new Option(label,key)));
        if(value && !options.some(([key])=>key===value))select.add(new Option(`${value} (unavailable)`,value));
        select.value=value??'';
    };
    const sourceGroup=group('Data source');
    note(sourceGroup,'Use saved information to fill this block. The content you design on the canvas stays as the fallback when no matching record is found.');
    const source=field(sourceGroup,'source','Source',[['','Not connected'],...sources.map(item=>[item.name,item.name.replaceAll('_',' ')])]);
    if(!sources.length)note(sourceGroup,'No sources are available. Sources come from the framework’s registered database tables.');
    const status=note(sourceGroup,'',true);status.role='status';
    const ownerHint=note(sourceGroup,'Source and record settings are shared with the parent connection. Fill mappings apply only to the chosen block.');ownerHint.hidden=true;
    const find=group('Find records');
    const select=field(find,'select','Find by',[]);
    note(find,'Choose the field that identifies the record, such as an ID or a page name.');
    const modes=make('fieldset','editor-data-modes');const legend=make('legend','','Get records using');modes.append(legend);find.append(modes);
    const radios=new Map();
    for(const [value,title,description] of [['single','Single value','Always find the same record.'],['route','From route','Use a detail from this page’s address.'],['collection','Collection','Show a list of records from this source.']]) {
        const label=make('label','editor-data-mode'), input=make('input');input.type='radio';input.name=`${container.id}-data-mode`;input.value=value;input.dataset.dataMode=value;
        const text=make('span');text.append(make('strong','',title),make('small','',description));label.append(input,text);modes.append(label);radios.set(value,input);
    }
    const singleArea=make('div');find.append(singleArea);
    const value=field(singleArea,'default_value','Value to find');value.placeholder='For example: 42 or summer-collection';
    const routeArea=make('div');find.append(routeArea);
    const bind=field(routeArea,'bind','Page address detail',[['','Choose a detail…'],...parameters.map(name=>[name,`{${name}}`])]);
    const routeHint=note(routeArea,parameters.length?routes.filter(route=>route.parameters.length).map(route=>route.path).join(' · '):'This page has no route parameters. Add a route with a detail such as /products/{slug} in Quill → Routes.');
    routeHint.dataset.dataRouteHint='';
    const routeFallback=field(routeArea,'route-fallback','Default value (optional)');
    note(routeArea,'Used only when the address detail is missing, for example in a draft preview.');
    const collectionArea=make('div');find.append(collectionArea);
    const show=field(collectionArea,'show','Show');show.type='number';show.min='1';show.step='1';show.value='5';
    const allLabel=make('label','editor-link-toggle'),all=make('input');all.type='checkbox';all.dataset.dataAll='';allLabel.append(all,make('span','','All records'));collectionArea.append(allLabel);
    const sortBy=field(collectionArea,'sort_by','Sort by',[]);
    const sort=field(collectionArea,'sort','Order',[['asc','Ascending (A–Z, first to last)'],['desc','Descending (Z–A, last to first)']]);
    const repeat=note(collectionArea,'This block and its nested content will be repeated once for each record. The canvas shows your original design; the published page shows the records.',true);repeat.dataset.dataRepeat='';
    const filters=group('Filter records');filters.open=false;
    const queryHost=make('div');queryHost.id=`${container.id}-query`;filters.append(queryHost);
    const queryBuilder=mountQueryBuilder({container:queryHost,getColumns:()=>currentColumns(),getParameters:()=>parameters,
        onApply:query=>{const previous=queryValue;queryValue=query;try{commit();}catch(error){queryValue=previous;throw error;}},onError:error=>editor.reportError(error),signal});
    const output=group('Fill this block');
    note(output,'Choose which fields to display. Values are shown as text, not HTML.');
    const target=field(output,'target','Fill',[]);target.dataset.dataTarget='';
    const inheritedHint=note(output,'Each child uses its own mappings from the same record.');
    function targets(){
        const options=[{path:'',node:block,label:'This block',disabled:false}];
        const visit=(children,prefix='',depth=1)=>children.forEach((child,index)=>{
            const path=prefix?`${prefix}.${index}`:String(index), own=child.descriptor.renderer&&!(child.descriptor.renderer.replace(/^\\/,'')===renderer&&child.descriptor.props.inherit);
            const disabled=!!child.component||!!own;
            const name=child.editor?.label||child.component?.name||child.descriptor.name;
            options.push({path,node:child,label:`${'  '.repeat(depth)}↳ ${name}${disabled?' · Edit separately':''}`,disabled});
            if(!disabled)visit(child.children,path,depth+1);
        });visit(block?.children||[]);return options;
    }
    const content=field(output,'content','Text content',[]);
    const composer=make('div','editor-data-content-composer');content.parentElement.before(composer);composer.append(content.parentElement);
    const extraContent=make('div','editor-data-content-fields');composer.append(extraContent);
    const moreContent=window.QuillEditorIcon.set(make('button','editor-action-icon'),'plus');moreContent.type='button';moreContent.title='Add content field';moreContent.setAttribute('aria-label','Add content field');moreContent.dataset.dataAddContent='';content.parentElement.append(moreContent);content.parentElement.classList.add('editor-data-content-primary');content.parentElement.append(make('span','editor-data-content-index','1'));
    function numberContentFields(){[...extraContent.children].forEach((row,index)=>{row.querySelector('.editor-data-content-index').textContent=String(index+2);row.querySelector('label').textContent=`Field ${index+2}`;row.querySelector('select').setAttribute('aria-label',`Content field ${index+2}`);row.querySelector('button').setAttribute('aria-label',`Remove field ${index+2}`);});}
    function addContentField(column='') {
        const row=make('div','editor-data-content-row'),input=field(row,'content-'+crypto.randomUUID(),'Additional content',[['','Choose a field…'],...columnOptions()]);input.dataset.dataContentAdditional='';input.value=column;
        const remove=window.QuillEditorIcon.set(make('button','editor-action-icon'),'xmark');remove.type='button';remove.setAttribute('aria-label','Remove content field');remove.addEventListener('click',()=>attempt(()=>{row.remove();numberContentFields();commit();}),{signal});row.prepend(make('span','editor-data-content-index'));row.append(remove);extraContent.append(row);numberContentFields();
    }
    moreContent.addEventListener('click',()=>{addContentField();extraContent.lastElementChild.querySelector('select').focus();},{signal});
    const affixes=make('div','editor-data-affixes');output.append(affixes);
    const before=field(affixes,'before','Before'),after=field(affixes,'after','After');
    before.placeholder='For example: Price: ';after.placeholder='For example: €';
    note(affixes,'Before and After wrap the result; spaces you type are kept.');
    const contentSpacesLabel=make('label','editor-link-toggle'),contentSpaces=make('input');contentSpaces.type='checkbox';contentSpaces.checked=true;contentSpaces.dataset.dataContentSpaces='';contentSpacesLabel.append(contentSpaces,make('span','','With spaces'));output.append(contentSpacesLabel);
    const attributes=make('div','editor-data-attributes');output.append(attributes);
    const add=make('button','','Add attribute');add.type='button';add.dataset.dataAddAttribute='';output.append(add);
    note(output,'Attributes fill details such as an image’s src or alt, or a link’s href. Unmapped details keep their existing values.');
    const disconnect=make('button','editor-data-disconnect','Disconnect data');disconnect.type='button';disconnect.dataset.dataDisconnect='';sourceGroup.append(disconnect);
    const currentColumns=()=>sources.find(item=>item.name===source.value)?.columns||[];
    const mode=()=>[...radios].find(([,input])=>input.checked)?.[0]||'single';
    const columnOptions=()=>currentColumns().map(column=>[column,column.replaceAll('_',' ')]);
    function addAttribute(mapping={name:'',value:''}) {
        const row=make('div','editor-data-attribute');
        const view=mountAttributeMapping({container:row,mapping:{...mapping,tag:targetBlock()?.descriptor.name,existingAttributes:targetBlock()?.attributes||{}},columns:columnOptions,routes:()=>editor.routes||[],signal,
            onChange:()=>attempt(commit),onRemove:()=>attempt(()=>{row.remove();commit();})});
        attributeViews.set(row,view);attributes.append(row);
    }
    function visibility() {
        const connected=!!source.value, current=mode();
        ownerHint.hidden=connectionOwner().path===editor.selectedPath;
        find.hidden=output.hidden=filters.hidden=!connected;disconnect.hidden=!connected;
        singleArea.hidden=current!=='single';routeArea.hidden=current!=='route';collectionArea.hidden=current!=='collection';
        show.disabled=all.checked;repeat.hidden=!all.checked&&Number(show.value)===1;
        content.disabled=voidTags.has(targetBlock()?.descriptor.name.toLowerCase());
        inheritedHint.hidden=!targetPath&&!block?.descriptor.props.inherit;
        contentSpacesLabel.hidden=affixes.hidden=extraContent.hidden=moreContent.hidden=before.disabled=after.disabled=content.disabled||!content.value;
        status.hidden=!connected;
        status.textContent=current==='collection'?(all.checked?'Connected · all records':`Connected · ${show.value||5} record${Number(show.value)===1?'':'s'}`):current==='route'?'Connected · follows the page address':'Connected · one record';
        bind.disabled=!parameters.length;
    }
    function commit() {
        if(refreshing||!block)return;
        if(!source.value){disconnectData();return;}
        const current=editor.getSelectedBlock();if(!current)return;
        const selectedMode=mode(), count=Number(show.value);
        if(!all.checked&&(!Number.isSafeInteger(count)||count<1))throw new Error('Show must be a whole number of at least 1.');
        const maps=[...attributes.children].map(row=>attributeViews.get(row).read()).filter(Boolean);
        const names=new Set();for(const map of maps){const name=map.name.toLowerCase();if(names.has(name))throw new Error('Map each attribute only once.');names.add(name);}
        const textMapping={content:content.disabled?'':content.value};
        const additional=[...extraContent.querySelectorAll('[data-data-content-additional]')].map(input=>input.value).filter(Boolean);
        if(!content.disabled&&content.value&&additional.length)textMapping.additional=additional;
        if(!before.disabled&&before.value)textMapping.before=before.value;
        if(!after.disabled&&after.value)textMapping.after=after.value;
        if(!content.disabled&&content.value&&!contentSpaces.checked)textMapping.with_spaces=false;
        const owner=connectionOwner(), absolute=targetPath?`${editor.selectedPath}.${targetPath}`:editor.selectedPath;
        const newSet={attributes:maps,content:textMapping};
        const ownerProps={...owner.node.descriptor.props,source:source.value,select:select.value,mode:selectedMode,
            default_value:selectedMode==='single'?value.value:selectedMode==='route'?routeFallback.value:'',bind:selectedMode==='route'?bind.value:'',
            show:all.checked?'all':count,sort:sort.value,sort_by:sortBy.value,
            set:absolute===owner.path?newSet:(owner.node.descriptor.props.set||{attributes:[],content:{content:''}})};
        delete ownerProps.inherit;
        if(queryValue)ownerProps.query=queryValue;else delete ownerProps.query;
        lastCommittedMappings=JSON.stringify(maps);
        if(absolute===owner.path){editor.updateBlock({descriptor:{...owner.node.descriptor,renderer,props:ownerProps}},owner.path);}
        else{
            const root=editor.state.getBlock(owner.path),relative=absolute.slice(owner.path.length+1),child=nodeAt(root,relative);
            if(!child)throw new Error('This child is no longer available.');
            const props={...child.descriptor.props};owned.forEach(key=>delete props[key]);
            const connected=maps.length||!!textMapping.content;
            child.descriptor={...child.descriptor,renderer:connected?renderer:'',props:connected?{...props,inherit:true,set:newSet}:props};
            root.descriptor={...root.descriptor,renderer,props:ownerProps};
            editor.state.updateBlock(owner.path,{descriptor:root.descriptor,children:root.children},'properties');
        }
        visibility();
    }
    function disconnectData() {
        const current=editor.getSelectedBlock();if(!current)return;
        const props={...current.descriptor.props};owned.forEach(key=>delete props[key]);
        editor.updateBlock({descriptor:{...current.descriptor,renderer:'',props}});
    }
    function attempt(action){try{action();editor.reportError(null);}catch(error){editor.reportError(error);}}
    container.addEventListener('change',event=>attempt(()=>{
        if(event.target===target){targetPath=target.value;targetId=identity(targetBlock());signature=null;renderedTarget=null;view.update(editor.getSelectedBlock());return;}
        if(event.target===source){
            queryValue=null;
            const options=columnOptions(), first=currentColumns().includes('id')?'id':options[0]?.[0]||'';
            fill(select,options,first);fill(sortBy,options,first);fill(content,[['','Keep existing content'],...options],'');before.value=after.value='';contentSpaces.checked=true;extraContent.replaceChildren();attributes.replaceChildren();
        }
        visibility();commit();
    }),{signal});
    add.addEventListener('click',()=>addAttribute(),{signal});
    disconnect.addEventListener('click',()=>attempt(disconnectData),{signal});
    const view = {update(next) {
        block=next;
        if(anchorPath!==editor.selectedPath){targetPath='';targetId=null;anchorPath=editor.selectedPath;}
        const choices=targets();
        if(targetId){const match=choices.find(item=>identity(item.node)===targetId&&!item.disabled);targetPath=match?.path||'';if(!match)targetId=null;}
        if(!choices.some(item=>item.path===targetPath&&!item.disabled))targetPath='';
        target.replaceChildren(...choices.map(item=>{const option=new Option(item.label,item.path);option.disabled=item.disabled;return option;}));target.value=targetPath;
        const owner=connectionOwner();
        const nextSignature=JSON.stringify([editor.selectedPath,next,owner.node.descriptor,targetPath]);
        if(signature===nextSignature){visibility();return;}signature=nextSignature;
        refreshing=true;
        try {
            const connected=owner.node.descriptor.renderer.replace(/^\\/,'')===renderer, props=connected?owner.node.descriptor.props:{};
            const selected=targetBlock(),selectedSet=selected.descriptor.renderer.replace(/^\\/,'')===renderer?(selected.descriptor.props.set||{}):{};
            queryValue=props.query??null;
            fill(source,[['','Not connected'],...sources.map(item=>[item.name,item.name.replaceAll('_',' ')])],props.source||'');
            const options=columnOptions();fill(select,options,props.select||'');fill(sortBy,options,props.sort_by||props.select||'');
            const selectedMode=props.mode||(props.bind?'route':'single');for(const [key,radio]of radios)radio.checked=key===selectedMode;
            value.value=routeFallback.value=props.default_value??'';fill(bind,[['','Choose a detail…'],...parameters.map(name=>[name,`{${name}}`])],props.bind||'');
            all.checked=props.show==='all';show.value=all.checked?5:props.show||5;sort.value=props.sort||'asc';
            fill(content,[['','Keep existing content'],...options],selectedSet.content?.content||'');
            const additional=selectedSet.content?.additional||[];
            if(renderedPath!==editor.selectedPath||renderedTarget!==targetPath||JSON.stringify([...extraContent.querySelectorAll('[data-data-content-additional]')].map(input=>input.value).filter(Boolean))!==JSON.stringify(additional)){extraContent.replaceChildren();additional.forEach(addContentField);}
            before.value=selectedSet.content?.before??'';after.value=selectedSet.content?.after??'';contentSpaces.checked=selectedSet.content?.with_spaces!==false;
            const mappings=selectedSet.attributes||[];
            // Keep an unfinished mapping while another field is edited.
            const keepDraft=renderedPath===editor.selectedPath&&renderedTarget===targetPath&&JSON.stringify(mappings)===lastCommittedMappings&&document.activeElement?.closest('.editor-data-attributes')?.parentElement===output;
            if(!keepDraft){attributes.replaceChildren();mappings.forEach(addAttribute);}
            lastCommittedMappings=JSON.stringify(mappings);renderedPath=editor.selectedPath;renderedTarget=targetPath;
            queryBuilder.update(queryValue,{path:editor.selectedPath,source:props.source||''});
            const conditionCount=where=>where.conditions.reduce((sum,item)=>sum+(item.conditions?conditionCount(item):1),0);
            const total=queryValue?conditionCount(queryValue.where):0;
            filters.querySelector('summary span').textContent=total?`Filter records · ${total}`:'Filter records';
            visibility();
        } finally {refreshing=false;}
    }};
    editor.on?.('routeschange',()=>{
        routes=editor.dataRoutes||[];parameters=[...new Set(routes.flatMap(route=>route.parameters))];signature=null;
        routeHint.textContent=parameters.length?routes.filter(route=>route.parameters.length).map(route=>route.path).join(' · '):'Add an address detail in Page settings to connect data from the route.';
        if(block)view.update(block);for(const row of attributes.children)attributeViews.get(row).refreshRoutes();queryBuilder.refresh();
    },{signal});
    return view;
}
