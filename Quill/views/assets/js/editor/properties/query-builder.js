const operators = [
    ['eq','Is equal to'],['ne','Is not equal to'],['gt','Is greater than'],['gte','Is at least'],['lt','Is less than'],['lte','Is at most'],
    ['contains','Contains text'],['not_contains','Does not contain text'],['starts_with','Starts with'],['ends_with','Ends with'],
    ['in','Is one of'],['not_in','Is not one of'],['between','Is between'],['is_null','Is not set'],['not_null','Is set'],['is_empty','Is empty'],['not_empty','Is not empty'],
];
const unary = new Set(['is_null','not_null','is_empty','not_empty']);
const list = new Set(['in','not_in','between']);
const textOnly = new Set(['contains','not_contains','starts_with','ends_with']);
const empty = () => ({match:'all',conditions:[]});
const copy = value => structuredClone(value);

/** Reusable draft UI: only Apply passes a validated, data-only query to the property adapter. */
export function mountQueryBuilder({container, getColumns, getParameters, onApply, onError, signal}) {
    let draft=empty(), committed=null, contextKey=null, dirty=false, sequence=0;
    const make=(tag,className,text)=>{const node=document.createElement(tag);if(className)node.className=className;if(text)node.textContent=text;return node;};
    const icon=name=>{const node=make('i',`fa-solid fa-${name}`);node.setAttribute('aria-hidden','true');return node;};
    const description=make('p','editor-group-description','Choose what should match. Connect conditions with AND or OR.');
    const summary=make('p','editor-data-notice');summary.dataset.querySummary='';summary.role='status';
    const tree=make('div','editor-query-tree');
    const preview=make('div','editor-query-preview');preview.dataset.queryPreview='';preview.setAttribute('aria-label','Filter preview');
    const footer=make('div','editor-query-footer');
    const button=(parent,label,action,key)=>{const node=make('button','',label);node.type='button';node.dataset[key]='';node.addEventListener('click',action,{signal});parent.append(node);return node;};
    const apply=button(footer,'Apply filters',()=>attempt(()=>{const query=serialize();onApply(query);committed=copy(query);draft=copy(query?.where||empty());dirty=false;render();}),'queryApply');apply.className='button-primary';
    const cancel=button(footer,'Cancel changes',()=>{draft=copy(committed?.where||empty());dirty=false;onError(null);render();},'queryCancel');
    const clear=button(footer,'Remove filters',()=>attempt(()=>{onApply(null);committed=null;draft=empty();dirty=false;render();}),'queryClear');
    const message=make('p','editor-group-description');message.dataset.queryFeedback='';message.role='status';
    container.append(description,summary,preview,tree,footer,message);
    function attempt(action){try{action();onError(null);}catch(error){message.textContent=error.message;message.setAttribute('role','alert');onError(error);}}
    const count=group=>group.conditions.reduce((sum,condition)=>sum+(condition.conditions?count(condition):1),0);
    const nodes=group=>group.conditions.reduce((sum,condition)=>sum+1+(condition.conditions?nodes(condition):0),0);
    function changed(){dirty=true;onError(null);feedback();}
    function feedback(){
        const total=committed?count(committed.where):0;
        summary.textContent=total?`${total} applied condition${total===1?'':'s'} · combined with the selection above`:'No filters applied. Records follow the selection above.';
        summary.prepend(icon(dirty?'pen':'filter'));summary.dataset.draft=String(dirty);
        paintPreview();
        apply.disabled=!dirty;cancel.hidden=!dirty;clear.hidden=!committed;
        message.setAttribute('role','status');message.textContent=dirty?'You have unapplied changes. Choose Apply filters when ready.':'Changes to filters are applied with Apply filters.';
    }
    function paintPreview(){
        preview.replaceChildren();
        const heading=make('div','editor-query-preview-heading');heading.append(icon('diagram-project'),make('span','',dirty?'Your filter · draft':'Your filter'));preview.append(heading);
        const expression=make('div','editor-query-expression');preview.append(expression);
        const token=(text,kind)=>expression.append(make('span',`editor-query-token editor-query-token-${kind}`,text));
        const symbols={eq:'=',ne:'≠',gt:'>',gte:'≥',lt:'<',lte:'≤',contains:'contains',not_contains:'does not contain',starts_with:'starts with',ends_with:'ends with',in:'is one of',not_in:'is not one of',between:'is between',is_null:'is not set',not_null:'is set',is_empty:'is empty',not_empty:'is not empty'};
        const walk=(group,nested=false)=>{
            const conditions=group.conditions.filter(condition=>!condition.conditions||count(condition));
            if(nested)token('(','bracket');
            conditions.forEach((condition,index)=>{
                if(index)token(group.match==='all'?'AND':'OR','logic');
                if(condition.conditions){walk(condition,true);return;}
                token(condition.column.replaceAll('_',' ')||'Choose a field','field');token(symbols[condition.operator]||condition.operator,'operator');
                if(condition.value){const operand=condition.value;token(operand.source==='route'?`{${operand.parameter||'page detail'}}`:Array.isArray(operand.value)?operand.value.join(', '):String(operand.value??'')||'…',operand.source==='route'?'route':'value');}
            });
            if(nested)token(')','bracket');
        };
        if(count(draft))walk(draft);else token('All selected records','operator');
    }
    function field(parent,key,title,options=null,value='') {
        const row=make('div','editor-link-field'),label=make('label','',title),input=make(options?'select':'input');
        input.id=`${container.id||'connect-query'}-${sequence++}`;input.dataset.queryField=key;input.setAttribute('aria-label',title);label.htmlFor=input.id;
        if(options){for(const [id,text]of options)input.add(new Option(text,id));if(value&&!options.some(([id])=>id===value))input.add(new Option(`${value} (unavailable)`,value));}
        else input.autocomplete='off';input.value=value??'';row.append(label,input);parent.append(row);return input;
    }
    function defaultCondition(){return {column:getColumns()[0]||'',operator:'eq',value:{source:'literal',type:'text',value:''}};}
    function renderGroup(group,parent,depth=0,path='root') {
        const box=make('fieldset','editor-query-group');box.dataset.queryGroup='';
        box.dataset.queryPath=path;
        const legend=make('legend','',depth?'Condition group':'Match records');box.append(legend);
        const header=make('div','editor-query-group-header');box.append(header);
        const match=field(header,'match','Match',[['all','All conditions'],['any','Any condition']],group.match);
        match.parentElement.classList.add('visually-hidden');match.tabIndex=-1;match.setAttribute('aria-hidden','true');
        const logic=make('div','editor-query-logic');logic.role='group';logic.setAttribute('aria-label','Match conditions');header.append(logic);
        const choices=new Map();
        for(const [value,label,symbol]of [['all','All','AND'],['any','Any','OR']]){
            const choice=button(logic,label,()=>{match.value=value;match.dispatchEvent(new Event('change',{bubbles:true}));},'queryMatch');choice.dataset.queryMatch=value;
            choice.title=value==='all'?'Match every condition (AND)':'Match at least one condition (OR)';
            choice.setAttribute('aria-label',value==='all'?'All conditions (AND)':'Any condition (OR)');
            choice.append(make('span','editor-query-logic-key',symbol));choices.set(value,choice);
        }
        const syncLogic=()=>{
            box.dataset.match=group.match;
            for(const [value,choice]of choices)choice.setAttribute('aria-pressed',String(value===group.match));
            box.querySelectorAll(':scope > .editor-query-join span').forEach(node=>node.textContent=group.match==='all'?'AND':'OR');
        };
        match.addEventListener('change',()=>{group.match=match.value;changed();},{signal});
        match.addEventListener('change',syncLogic,{signal});
        const hint=make('p','editor-group-description',group.match==='all'?'Every condition in this group must match.':'At least one condition in this group must match.');box.append(hint);
        match.addEventListener('change',()=>{hint.textContent=match.value==='all'?'Every condition in this group must match.':'At least one condition in this group must match.';},{signal});
        group.conditions.forEach((condition,index)=>{
            if(index){const join=make('div','editor-query-join');join.setAttribute('aria-hidden','true');join.append(make('span','',group.match==='all'?'AND':'OR'));box.append(join);}
            const item=make('div',condition.conditions?'editor-query-nested':'editor-query-condition');box.append(item);
            item.dataset.queryPath=`${path}.${index}`;
            const remove=button(item,condition.conditions?'Remove group':'Remove condition',()=>{group.conditions.splice(index,1);changed();render();},'queryRemove');
            remove.className='editor-query-remove';
            if(condition.conditions){renderGroup(condition,item,depth+1,`${path}.${index}.group`);return;}
            const cardHeader=make('div','editor-query-card-header');cardHeader.append(icon('sliders'),make('span','',`Condition ${index+1}`));
            remove.setAttribute('aria-label','Remove condition');remove.title='Remove condition';remove.replaceChildren(icon('xmark'));cardHeader.append(remove);item.prepend(cardHeader);
            const column=field(item,'column','Field',getColumns().map(name=>[name,name.replaceAll('_',' ')]),condition.column);
            column.addEventListener('change',()=>{condition.column=column.value;changed();},{signal});
            const operator=field(item,'operator','Comparison',operators,condition.operator);
            operator.addEventListener('change',()=>{
                condition.operator=operator.value;
                if(unary.has(operator.value))delete condition.value;
                else {
                    condition.value??={source:'literal',type:'text',value:''};
                    if(list.has(operator.value)){condition.value.source='literal';condition.value.value=operator.value==='between'?['','']:[''];}
                    else if(Array.isArray(condition.value.value))condition.value.value=condition.value.value[0]??'';
                    if(textOnly.has(operator.value))condition.value.type='text';
                }
                changed();render();
            },{signal});
            if(unary.has(condition.operator)){
                const descriptions={is_null:'Matches records with no stored value in this field.',not_null:'Requires a stored value. Blank text still counts as set.',is_empty:'Matches a field that is unset or contains no text.',not_empty:'Requires a stored value that is not blank.'};
                item.append(make('p','editor-group-description',descriptions[condition.operator]));return;
            }
            const operand=condition.value;
            const from=field(item,'source','Compare with',list.has(condition.operator)?[['literal','Entered values']]:[['literal','Entered value'],['route','Page address detail']],operand.source);
            from.addEventListener('change',()=>{operand.source=from.value;if(from.value==='route'){operand.parameter=getParameters()[0]||'';delete operand.value;}else{operand.value='';delete operand.parameter;delete operand.default_value;}changed();render();},{signal});
            const type=field(item,'type','Value type',textOnly.has(condition.operator)?[['text','Text']]:[['text','Text'],['number','Number'],['boolean','Yes / No']],operand.type);
            type.addEventListener('change',()=>{operand.type=type.value;if(operand.source==='literal')operand.value=list.has(condition.operator)?(condition.operator==='between'?['','']:['']):'';changed();render();},{signal});
            if(operand.source==='route') {
                const parameter=field(item,'parameter','Page address detail',getParameters().length?getParameters().map(name=>[name,`{${name}}`]):[['','No address details available']],operand.parameter);
                parameter.disabled=!getParameters().length;
                parameter.addEventListener('change',()=>{operand.parameter=parameter.value;changed();},{signal});
                const fallback=field(item,'default_value','Fallback value (optional)',null,operand.default_value??'');
                fallback.addEventListener('input',()=>{if(fallback.value==='')delete operand.default_value;else operand.default_value=fallback.value;changed();},{signal});
                const note=make('p','editor-group-description','Uses a detail from this page’s route. If it is missing and no fallback is entered, no records are returned.');item.append(note);
            } else if(list.has(condition.operator)) {
                const value=field(item,'value',condition.operator==='between'?'From and to (one per line)':'Values (one per line)',null,'');
                const area=make('textarea');area.id=value.id;area.dataset.queryField='value';area.rows=condition.operator==='between'?2:3;area.setAttribute('aria-label',condition.operator==='between'?'From and to (one per line)':'Values (one per line)');area.value=(Array.isArray(operand.value)?operand.value:[operand.value]).join('\n');value.replaceWith(area);
                area.addEventListener('input',()=>{operand.value=area.value.split('\n');changed();},{signal});
            } else {
                const value=field(item,'value','Value',operand.type==='boolean'?[['','Choose…'],['true','Yes'],['false','No']]:null,operand.value);
                if(operand.type==='number'){value.type='number';value.step='any';}
                value.addEventListener(operand.type==='boolean'?'change':'input',()=>{operand.value=value.value;changed();},{signal});
            }
        });
        if(!group.conditions.length){const blank=make('div','editor-query-empty');blank.append(icon('filter'),make('strong','',depth?'Build this group':'Start with a condition'),make('span','','Choose a field and tell us what should match.'));box.append(blank);}
        const tools=make('div','editor-query-tools');box.append(tools);
        const add=button(tools,'Add condition',()=>{group.conditions.push(defaultCondition());changed();render();},'queryAddCondition');
        const sub=button(tools,'Add group',()=>{group.conditions.push(empty());changed();render();},'queryAddGroup');
        add.prepend(icon('plus'));sub.prepend(icon('layer-group'));
        add.disabled=nodes(draft)>=64||!getColumns().length;sub.disabled=depth>=4||nodes(draft)>=64;
        syncLogic();
        parent.append(box);
    }
    function render(){
        const active=document.activeElement,path=active?.closest('[data-query-path]')?.dataset.queryPath,key=active?.dataset.queryField;
        tree.replaceChildren();sequence=0;renderGroup(draft,tree);feedback();
        if(path&&key)tree.querySelector(`[data-query-path="${CSS.escape(path)}"] [data-query-field="${CSS.escape(key)}"]`)?.focus({preventScroll:true});
    }
    function typed(value,type) {
        if(type==='number'){if(String(value).trim()===''||!Number.isFinite(Number(value)))throw new Error('Enter a valid number for each numeric condition.');return Number(value);}
        if(type==='boolean'){if(value===true||value===1||value==='true'||value==='1')return true;if(value===false||value===0||value==='false'||value==='0')return false;throw new Error('Choose Yes or No for each condition.');}
        return String(value??'');
    }
    function serialize() {
        let total=0,nodes=0,values=0;
        const walk=(group,depth=0)=>({match:group.match,conditions:group.conditions.map(condition=>{
            if(++nodes>64||depth>4)throw new Error('Use at most 64 conditions and groups, with five group levels.');
            if(condition.conditions)return walk(condition,depth+1);
            total++;
            if(!getColumns().includes(condition.column))throw new Error('Choose an available field for every condition.');
            const result={column:condition.column,operator:condition.operator};
            if(unary.has(condition.operator))return result;
            const operand=condition.value,resultValue={source:operand.source,type:operand.type};
            if(operand.source==='route'){
                if(!getParameters().includes(operand.parameter))throw new Error('Choose an available page address detail.');
                resultValue.parameter=operand.parameter;
                if(Object.hasOwn(operand,'default_value'))resultValue.default_value=typed(operand.default_value,operand.type);
                values++;
            }else{
                if(list.has(condition.operator)){
                    if(!Array.isArray(operand.value)||operand.value.length<1||operand.value.length>32||(condition.operator==='between'&&operand.value.length!==2))throw new Error('Enter two range values, or up to 32 list values, one per line.');
                    resultValue.value=operand.value.map(item=>typed(item,operand.type));values+=operand.value.length;
                }else {resultValue.value=typed(operand.value,operand.type);values++;}
            }
            if(values>256)throw new Error('Use at most 256 filter values.');
            result.value=resultValue;return result;
        })});
        const where=walk(draft);
        return total?{version:1,where}:null;
    }
    container.addEventListener('change',event=>event.stopPropagation(),{signal});
    return {refresh:()=>render(),update(query,{path,source}) {
        const key=JSON.stringify([path,source]);
        if(key===contextKey&&JSON.stringify(query??null)===JSON.stringify(committed))return;
        contextKey=key;committed=copy(query??null);draft=copy(committed?.where||empty());dirty=false;render();
    }};
}
