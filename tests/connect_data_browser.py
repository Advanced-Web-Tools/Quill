"""Connect data property panel and toolbar, using the actual editor in Chrome."""
import base64
from pathlib import Path
import editor_browser as browser


def exercise(endpoint, origin, screenshot):
    page = browser.Page(endpoint, origin + '/connect-data')
    page.wait('!!window.QuillEditor?.ui.properties')
    page.evaluate("QuillEditor.select('0.0.1');window.dataDoc=document.querySelector('iframe').contentDocument;window.fallbackBefore=QuillEditor.getSelectedBlock().content;window.extraProps=structuredClone(QuillEditor.getSelectedBlock().descriptor.props)")
    page.wait("!!dataDoc.querySelector('[data-toolbar-action=connect-data]')")
    page.evaluate("dataDoc.querySelector('[data-toolbar-action=connect-data]').click()")
    page.wait("!!document.querySelector('#editor-tab-connect-data[aria-selected=true]')")
    page.check("document.activeElement.dataset.dataField==='source'", 'Floating toolbar opens Connect data and focuses source selection')
    page.evaluate("""window.dataField=key=>document.querySelector(`[data-data-field="${key}"]`);window.setData=(key,value)=>{const field=dataField(key);field.focus();field.value=value;field.dispatchEvent(new Event('change',{bubbles:true}));};window.chooseMode=mode=>document.querySelector(`[data-data-mode="${mode}"]`).click();""")
    page.check("dataField('source').options.length===3 && dataField('source').value===''", 'Source dropdown offers all registered tables and an explicit disconnected state')
    page.evaluate("setData('source','products')")
    page.wait("QuillEditor.getSelectedBlock().descriptor.renderer.includes('ConnectedDataRenderer')")
    page.check("dataField('select').options.length===5 && dataField('select').value==='id' && document.querySelector('[data-data-mode=single]').checked", 'Selecting a source offers only its columns and defaults to a single record')
    page.evaluate("setData('select','slug');setData('default_value','product-2');setData('content','title')")
    page.check("QuillEditor.getSelectedBlock().descriptor.props.default_value==='product-2' && QuillEditor.getSelectedBlock().descriptor.props.set.content.content==='title' && QuillEditor.getSelectedBlock().content===fallbackBefore && dataDoc.querySelector('p').textContent===fallbackBefore", 'Connection edits affect descriptor props and keep authored canvas content as fallback')
    page.check("JSON.stringify(QuillEditor.getSelectedBlock().descriptor.props.empty)===JSON.stringify(extraProps.empty) && Array.isArray(QuillEditor.getSelectedBlock().descriptor.props.list)", 'Connecting preserves unrelated descriptor props')
    page.evaluate("chooseMode('route')")
    page.wait("document.querySelector('[data-data-mode=route]').checked")
    page.check("[...document.querySelectorAll('[data-data-mode]')].filter(input=>input.checked).length===1 && dataField('bind').options.length===2 && dataField('bind').options[1].value==='slug' && document.querySelector('[data-data-route-hint]').textContent.includes('/products/{slug}')", 'Route is exclusive and offers only parameters from this page’s own routes')
    page.evaluate("setData('bind','slug');setData('route-fallback','preview-product')")
    page.check("QuillEditor.getSelectedBlock().descriptor.props.bind==='slug' && QuillEditor.getSelectedBlock().descriptor.props.default_value==='preview-product'", 'Route binding and optional preview fallback use the descriptor contract')
    page.evaluate("chooseMode('collection')")
    page.wait("QuillEditor.getSelectedBlock().descriptor.props.mode==='collection'")
    page.check("dataField('show').value==='5' && document.querySelector('[data-data-repeat]').checkVisibility() && QuillEditor.getSelectedBlock().descriptor.props.bind===''", 'Collection defaults to 5, clears route binding, and explains block duplication')
    page.evaluate("setData('show','1')")
    page.check("!document.querySelector('[data-data-repeat]').checkVisibility() && !dataField('show').disabled", 'Show 1 hides duplication notice')
    page.evaluate("document.querySelector('[data-data-all]').click();setData('sort_by','price');setData('sort','desc')")
    page.check("dataField('show').disabled && document.querySelector('[data-data-repeat]').checkVisibility() && QuillEditor.getSelectedBlock().descriptor.props.show==='all' && QuillEditor.getSelectedBlock().descriptor.props.sort==='desc' && QuillEditor.getSelectedBlock().descriptor.props.sort_by==='price'", 'All records and descending ordering persist while clearly describing repetition')
    page.evaluate("document.querySelector('[data-data-add-attribute]').click();const name=document.querySelector('[data-data-attribute-name]');name.focus();name.value='title';name.dispatchEvent(new Event('change',{bubbles:true}));")
    page.evaluate("const field=document.querySelector('[data-data-attribute-value]');field.focus();field.value='title';field.dispatchEvent(new Event('change',{bubbles:true}));")
    page.check("QuillEditor.getSelectedBlock().descriptor.props.set.attributes[0].name==='title' && QuillEditor.getSelectedBlock().descriptor.props.set.attributes[0].value==='title'", 'Attribute mappings use named columns without changing authored attributes')
    page.evaluate("QuillEditor.ui.history.undo()")
    page.wait("QuillEditor.getSelectedBlock().descriptor.props.set.attributes.length===0")
    page.check("!document.querySelector('[data-data-attribute-name]')", 'Undo refreshes attribute mappings even when focus was inside them')
    page.evaluate("QuillEditor.ui.history.redo()")
    page.wait("!!document.querySelector('[data-data-attribute-name]')")
    page.check("document.querySelector('[data-data-attribute-name]').value==='title'", 'Redo restores attribute mapping controls')
    if screenshot:
        Path(screenshot).write_bytes(base64.b64decode(page.command('Page.captureScreenshot',format='png')['data']))
    page.evaluate("document.querySelector('[data-data-disconnect]').click()")
    page.wait("QuillEditor.getSelectedBlock().descriptor.renderer===''")
    page.check("!Object.hasOwn(QuillEditor.getSelectedBlock().descriptor.props,'source') && QuillEditor.getSelectedBlock().content===fallbackBefore && JSON.stringify(QuillEditor.getSelectedBlock().descriptor.props)===JSON.stringify(extraProps)", 'Disconnect removes only connection settings and retains fallback and unrelated props')
    page.evaluate("setData('source','news')")
    page.wait("QuillEditor.getSelectedBlock().descriptor.props.source==='news'")
    page.check("[...dataField('select').options].map(option=>option.value).join(',')==='id,headline,published_at' && dataField('content').value===''", 'Changing source resets mappings and offers the new source’s columns')
    page.evaluate("QuillEditor.updateBlock({descriptor:{...QuillEditor.getSelectedBlock().descriptor,renderer:'Demo\\\\CustomRenderer'}})")
    page.wait("!document.querySelector('#editor-tab-connect-data')")
    page.check("!dataDoc.querySelector('[data-toolbar-action=connect-data]')", 'External renderer controls are protected from being replaced by Connect data')
    # A page without dynamic routes must explain the unavailable route selection.
    other=browser.Page(endpoint,origin+'/')
    other.wait('!!window.QuillEditor?.ui.properties')
    other.evaluate("QuillEditor.select('0.0.1');QuillEditor.ui.properties.showCategory('connect-data')")
    other.wait("!!document.querySelector('[data-data-route-hint]')")
    other.check("document.querySelector('[data-data-field=bind]').disabled && document.querySelector('[data-data-route-hint]').textContent.includes('no route parameters')", 'Pages without route parameters show a clear explanation instead of unrelated page parameters')
    if page.exceptions or other.exceptions:
        raise AssertionError(page.exceptions+other.exceptions)


if __name__ == '__main__':
    browser.exercise_rich_text=lambda *args:None
    browser.exercise_rich_text_line_breaks=lambda *args:None
    browser.exercise_structured_blocks=lambda *args:None
    browser.exercise=exercise
    browser.main()
