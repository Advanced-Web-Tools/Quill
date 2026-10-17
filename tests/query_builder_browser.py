"""Visual query builder draft, grouping, types and history in real Chrome."""
import base64
from pathlib import Path
import editor_browser as browser


def exercise(endpoint, origin, screenshot):
    page=browser.Page(endpoint,origin+'/connect-data')
    page.wait('!!window.QuillEditor?.ui.properties')
    page.evaluate("QuillEditor.select('0.0.1');QuillEditor.ui.properties.showCategory('connect-data')")
    page.wait("!!document.querySelector('[data-data-field=source]')")
    page.evaluate("""window.setData=(key,value)=>{const field=document.querySelector(`[data-data-field="${key}"]`);field.value=value;field.dispatchEvent(new Event('change',{bubbles:true}));};setData('source','products');document.querySelector('[data-data-mode=collection]').click();window.queryProps=()=>QuillEditor.getSelectedBlock().descriptor.props;window.queryJSON=()=>JSON.stringify(QuillEditor.getDocument());window.queryRoot=()=>document.querySelector('.editor-query-tree > [data-query-group]');window.queryRule=(index,group=queryRoot())=>[...group.querySelectorAll(':scope > .editor-query-condition')][index];window.querySet=(rule,key,value)=>{const input=rule.querySelector(`[data-query-field="${key}"]`);if(input.getAttribute('aria-hidden')!=='true')input.focus();input.value=value;input.dispatchEvent(new Event(input.tagName==='SELECT'?'change':'input',{bubbles:true}));};window.addRule=(group=queryRoot())=>group.querySelector(':scope > .editor-query-tools [data-query-add-condition]').click();window.queryApply=()=>document.querySelector('[data-query-apply]').click();""")
    page.wait("queryProps().mode==='collection'")
    page.evaluate("document.querySelector('[data-query-summary]').closest('details').open=true;window.authoredBefore=QuillEditor.getSelectedBlock().content;window.beforeQuery=queryJSON();addRule()")
    page.check("!queryProps().query && document.querySelector('[data-query-feedback]').textContent.includes('unapplied')", 'New conditions are explicit drafts until Apply filters')
    page.evaluate("querySet(queryRule(0),'column','price');querySet(queryRule(0),'operator','gte')")
    page.check("document.activeElement.dataset.queryField==='operator' && document.activeElement.isConnected", 'Changing a comparison preserves keyboard focus after rerendering its value controls')
    page.evaluate("querySet(queryRule(0),'type','number');querySet(queryRule(0),'value','30');queryApply()")
    page.wait("queryProps().query?.where.conditions[0].value.value===30")
    page.check("queryProps().query.version===1 && queryProps().query.where.conditions[0].value.type==='number' && document.querySelector('[data-query-apply]').disabled", 'Apply stores a versioned typed filter AST in descriptor props')
    page.check("QuillEditor.getSelectedBlock().content===authoredBefore && document.querySelector('iframe').contentDocument.querySelector('p').textContent===authoredBefore", 'Query editing leaves the authored block and canvas content unchanged')
    page.evaluate("querySet(queryRule(0),'value','');queryApply()")
    page.check("queryProps().query.where.conditions[0].value.value===30 && document.querySelector('[data-query-feedback]').textContent.includes('valid number')", 'Invalid numeric drafts report a field error and preserve the committed query')
    page.evaluate("document.querySelector('[data-query-cancel]').click()")
    page.check("queryRule(0).querySelector('[data-query-field=value]').value==='30' && document.querySelector('[data-query-apply]').disabled", 'Cancel restores the applied filters without writing a document change')
    page.evaluate("queryRoot().querySelector(':scope > .editor-query-tools [data-query-add-group]').click()")
    page.evaluate("""window.innerGroup=()=>queryRoot().querySelector(':scope > .editor-query-nested > [data-query-group]');querySet(innerGroup(),'match','any');addRule(innerGroup());querySet(queryRule(0,innerGroup()),'column','title');querySet(queryRule(0,innerGroup()),'operator','contains');querySet(queryRule(0,innerGroup()),'value','Sale');addRule(innerGroup());querySet(queryRule(1,innerGroup()),'column','slug');querySet(queryRule(1,innerGroup()),'source','route');""")
    page.check("queryRule(1,innerGroup()).querySelector('[data-query-field=parameter]').options.length===1 && queryRule(1,innerGroup()).querySelector('[data-query-field=parameter]').value==='slug'", 'Route comparisons offer only parameters from this page’s assigned routes')
    page.evaluate("querySet(queryRule(1,innerGroup()),'default_value','preview-product');queryApply()")
    page.wait("queryProps().query.where.conditions.length===2")
    page.check("queryProps().query.where.match==='all' && queryProps().query.where.conditions[1].match==='any' && queryProps().query.where.conditions[1].conditions[1].value.source==='route' && queryProps().query.where.conditions[1].conditions[1].value.default_value==='preview-product'", 'Nested All/Any and route fallback values serialize without losing grouping')
    page.check("document.querySelector('[data-query-summary]').textContent.includes('3 applied conditions') && document.querySelector('[data-query-summary]').closest('details').querySelector('summary').textContent.includes('3')", 'Applied condition counts remain discoverable in the collapsed inspector category')
    page.check("document.querySelector('[data-query-preview]').textContent.includes('30') && !!document.querySelector('.editor-query-token-route') && document.querySelector('.editor-query-token-route').textContent==='{slug}'", 'Live visual expression shows entered values and route tokens without executing the query')
    page.evaluate("window.visualQueryBefore=JSON.stringify(queryProps().query);queryRoot().querySelector(':scope > .editor-query-group-header [data-query-match=any]').click()")
    page.check("JSON.stringify(queryProps().query)===visualQueryBefore && queryRoot().querySelector(':scope > .editor-query-group-header [data-query-match=any]').getAttribute('aria-pressed')==='true' && queryRoot().querySelector(':scope > .editor-query-join span').textContent==='OR'", 'Segmented logic switches update connector badges and leave the committed query intact until Apply')
    page.evaluate("queryApply();QuillEditor.ui.history.undo()")
    page.wait("JSON.stringify(queryProps().query)===visualQueryBefore")
    page.evaluate("window.nestedQuery=JSON.stringify(queryProps().query);QuillEditor.ui.history.undo()")
    page.wait("queryProps().query.where.conditions.length===1")
    page.check("!queryRoot().querySelector('.editor-query-nested')", 'Undo removes an applied group as one history operation and updates controls')
    page.evaluate("QuillEditor.ui.history.redo()")
    page.wait("JSON.stringify(queryProps().query)===nestedQuery")
    page.check("!!queryRoot().querySelector('.editor-query-nested')", 'Redo restores nested groups and route bindings')
    page.evaluate("querySet(queryRule(0),'operator','in');querySet(queryRule(0),'value','10\\n20\\n30');queryApply()")
    page.wait("Array.isArray(queryProps().query.where.conditions[0].value.value)")
    page.check("JSON.stringify(queryProps().query.where.conditions[0].value.value)==='[10,20,30]'", 'One-value-per-line list input stores a typed numeric array')
    page.evaluate("querySet(queryRule(0),'operator','is_empty');queryApply()")
    page.wait("queryProps().query.where.conditions[0].operator==='is_empty'")
    page.check("!Object.hasOwn(queryProps().query.where.conditions[0],'value') && !queryRule(0).querySelector('[data-query-field=value]')", 'Empty/unset comparisons require no artificial value')
    for theme in ('light','dark'):
        page.evaluate("{const field=document.querySelector('#editor-settings-theme');field.value="+repr(theme)+";field.dispatchEvent(new Event('change'));}")
        page.wait(f"document.documentElement.dataset.editorTheme==='{theme}'")
        page.check("queryRule(0).getBoundingClientRect().right<=document.querySelector('.editor-rightbar').getBoundingClientRect().right && getComputedStyle(queryRule(0)).backgroundColor!=='rgba(0, 0, 0, 0)'", theme+' theme uses shared surface tokens without overflowing the inspector')
        page.check("[...document.querySelectorAll('[data-query-field]')].every(input=>input.getAttribute('aria-label') && input.id && !!document.querySelector('label[for=\"'+input.id+'\"]'))", theme+' query fields retain explicit accessible labels')
    if screenshot:
        page.evaluate("{const sidebar=document.querySelector('.editor-rightbar'),target=document.querySelector('[data-query-preview]'),tabs=document.querySelector('.editor-property-tabs');sidebar.scrollTop+=target.getBoundingClientRect().top-sidebar.getBoundingClientRect().top-tabs.offsetHeight-16;window.scrollTo(0,0);}")
        Path(screenshot).write_bytes(base64.b64decode(page.command('Page.captureScreenshot',format='png')['data']))
    page.evaluate("document.querySelector('[data-query-clear]').click()")
    page.wait("!queryProps().query")
    page.check("queryRoot().querySelectorAll('.editor-query-condition').length===0 && document.querySelector('[data-query-summary]').textContent.includes('No filters')", 'Remove filters restores the legacy query and clears its controls')
    page.evaluate("QuillEditor.ui.history.undo()")
    page.wait("!!queryProps().query")
    page.check("queryProps().query.where.conditions.length===2", 'Removing filters is reversible through existing history')
    page.evaluate("setData('source','news')")
    page.wait("queryProps().source==='news'")
    page.check("!queryProps().query && queryRoot().querySelectorAll('.editor-query-condition').length===0", 'Changing source clears filters that reference its old columns')
    page.evaluate("addRule();querySet(queryRule(0),'column','headline');querySet(queryRule(0),'value','New');queryApply();QuillEditor.select('0.0.0')")
    page.wait("QuillEditor.selectedPath==='0.0.0' && !queryProps().source")
    page.check("queryRoot().querySelectorAll('.editor-query-condition').length===0", 'Changing selection never carries another block’s filter draft into the new block')
    page.evaluate("QuillEditor.select('0.0.1');QuillEditor.ui.properties.showCategory('connect-data')")
    page.wait("queryRule(0)?.querySelector('[data-query-field=column]').value==='headline'")
    page.check("queryRule(0).querySelector('[data-query-field=value]').value==='New'", 'Returning to the block reopens its saved filter configuration')
    page.evaluate("document.querySelector('[data-data-disconnect]').click()")
    page.wait("!queryProps().source")
    page.check("!queryProps().query && QuillEditor.getSelectedBlock().content===authoredBefore", 'Disconnect removes the query together with the connection and preserves authored content')
    if page.exceptions:
        raise AssertionError(page.exceptions)


if __name__=='__main__':
    browser.exercise_rich_text=lambda *args:None
    browser.exercise_rich_text_line_breaks=lambda *args:None
    browser.exercise_structured_blocks=lambda *args:None
    browser.exercise=exercise
    browser.main()
