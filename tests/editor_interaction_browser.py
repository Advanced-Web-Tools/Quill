"""Continuous edit history, targeted canvas rendering, paint indicators and control contrast."""
import base64
from pathlib import Path
import editor_browser as browser


def exercise(endpoint, origin, screenshot):
    p = browser.Page(endpoint, origin + '/')
    p.command('Page.bringToFront')
    p.wait('!!window.QuillEditor?.ui.history && !!QuillEditor.canvas.getElement("0.0.1")')
    p.evaluate('''window.neighbor=QuillEditor.canvas.getElement('1.0');window.ancestorBefore=QuillEditor.canvas.getElement('0.0');window.savedHead=[...neighbor.ownerDocument.head.children];window.editedBefore=QuillEditor.canvas.getElement('0.0.1');window.neighborClicks=0;neighbor.addEventListener('custom-probe',()=>neighborClicks++);QuillEditor.select('0.0.1');QuillEditor.setStyle('color','#123456');''')
    p.wait('getComputedStyle(QuillEditor.canvas.getElement("0.0.1")).color==="rgb(18, 52, 86)"')
    p.check('QuillEditor.canvas.getElement("1.0")===neighbor && QuillEditor.canvas.getElement("0.0")===ancestorBefore && savedHead.every(node=>node.isConnected)', 'A local style update keeps other elements, ancestors and loaded styles mounted')
    p.evaluate('neighbor.dispatchEvent(new Event("custom-probe"))')
    p.check('neighborClicks===1 && !editedBefore.isConnected', 'Only the edited subtree is replaced and unrelated element listeners survive')
    p.evaluate('QuillEditor.setStyle("color","#abcdef");QuillEditor.setStyle("background-color","#ffeedd")')
    p.wait('getComputedStyle(QuillEditor.canvas.getElement("0.0.1")).backgroundColor==="rgb(255, 238, 221)"')
    p.check('getComputedStyle(QuillEditor.canvas.getElement("0.0.1")).color==="rgb(171, 205, 239)" && QuillEditor.canvas.getElement("1.0")===neighbor', 'Successive local patches keep the latest styles without rebuilding neighbors')
    p.evaluate('QuillEditor.setStyleViewport("mobile");QuillEditor.setStyleState("hover");QuillEditor.setStyle("color","#ff0000")')
    p.wait('!!QuillEditor.canvas.getElement("0.0.1")?.ownerDocument.head.textContent.includes("rgb") || QuillEditor.canvas.getElement("0.0.1").ownerDocument.head.textContent.includes("#ff0000")')
    p.check('QuillEditor.getSelectedBlock().styles["mobile:hover"].color==="rgb(255, 0, 0)"', 'Targeted rendering keeps responsive and state override data intact')
    p.evaluate('QuillEditor.setStyleViewport("all");QuillEditor.setStyleState("default");window.globalBefore=QuillEditor.canvas.getElement("1.0");QuillEditor.state.updateSettings({styles:{padding:"17px"}})')
    p.wait('getComputedStyle(QuillEditor.canvas.getElement("1.0").ownerDocument.body).paddingTop==="17px"')
    p.check('QuillEditor.canvas.getElement("1.0")!==globalBefore', 'Global page styling still uses a complete render')
    p.evaluate("""window.fixtureNode=(tag,content)=>({descriptor:{name:tag,props:{},renderer:''},attributes:{},content,children:[]});QuillEditor.updateBlock({children:[fixtureNode('b','New nested child')]},'0.0.1');QuillEditor.setContent('New footer','1.0');""")
    p.wait('QuillEditor.canvas.getElement("0.0.1.0")?.textContent==="New nested child" && QuillEditor.canvas.getElement("1.0").textContent==="New footer"')
    p.check('QuillEditor.canvas.getElement("0.0.1.0").tagName==="B"', 'Mixed subtree and sibling edits keep the latest canvas state')
    p.evaluate('QuillEditor.updateBlock({children:[]},"0.0.1")')
    p.wait('!QuillEditor.canvas.getElement("0.0.1.0")')
    p.evaluate("""window.rendererBuilds=0;window.rendererCleanups=0;window.unregisterProbe=QuillEditor.registerRenderer('test:probe',({element,block})=>{rendererBuilds++;element.dataset.probe=block.content;return()=>rendererCleanups++});QuillEditor.updateBlock({definition_key:'test:probe'},'1.0');""")
    p.wait('QuillEditor.canvas.getElement("1.0")?.dataset.probe==="New footer"')
    p.evaluate('window.buildCountBefore=rendererBuilds;window.cleanupCountBefore=rendererCleanups;QuillEditor.setContent("Other paragraph","0.0.1")')
    p.wait('QuillEditor.canvas.getElement("0.0.1").textContent==="Other paragraph"')
    p.check('rendererBuilds===buildCountBefore && rendererCleanups===cleanupCountBefore', 'Unrelated patches do not recreate extension previews or run their cleanup')
    p.evaluate('QuillEditor.setContent("Updated extension","1.0")')
    p.wait('QuillEditor.canvas.getElement("1.0")?.dataset.probe==="Updated extension"')
    p.check('rendererCleanups===cleanupCountBefore+1', 'Updating an extension preview runs its cleanup exactly once')
    # Canvas input is the actual native editor input path, not a synthetic history API.
    p.evaluate('QuillEditor.canvas.editText("0.0.1");window.textHost=QuillEditor.canvas.getElement("0.0.1").querySelector("[data-quill-editor-text]");window.beforeText=QuillEditor.getBlock("0.0.1").content;window.historyBefore=QuillEditor.ui.history.undoStack.length;window.editNeighbor=QuillEditor.canvas.getElement("1.0");textHost.focus();for(let i=1;i<=100;i++){textHost.textContent="a".repeat(i);textHost.dispatchEvent(new InputEvent("input",{bubbles:true,inputType:"insertText",data:"a"}))}')
    p.check('QuillEditor.ui.history.undoStack.length===historyBefore+1 && QuillEditor.getBlock("0.0.1").content==="a".repeat(100)', 'One hundred typed characters use one undo entry')
    p.check('textHost.isConnected && QuillEditor.canvas.getElement("1.0")===editNeighbor', 'Typing leaves the editing host and unrelated canvas elements mounted')
    p.evaluate('QuillEditor.ui.history.undo()')
    p.wait('QuillEditor.getBlock("0.0.1").content===beforeText')
    p.evaluate('QuillEditor.ui.history.redo()')
    p.wait('QuillEditor.getBlock("0.0.1").content==="a".repeat(100)')
    p.check('QuillEditor.getBlock("0.0.1").content==="a".repeat(100)', 'Redo restores the entire typing session')
    # A pause, another field and a successful save are explicit undo boundaries.
    p.evaluate('document.querySelector("#editor-page-menu").click();window.titleField=document.querySelector("#editor-page-title");window.titleHistory=QuillEditor.ui.history.undoStack.length;titleField.value="First";titleField.dispatchEvent(new InputEvent("input",{bubbles:true}))')
    p.evaluate('new Promise(resolve=>setTimeout(resolve,850))')
    p.evaluate('titleField.value="Second";titleField.dispatchEvent(new InputEvent("input",{bubbles:true}))')
    p.check('QuillEditor.ui.history.undoStack.length===titleHistory+2', 'A pause starts a new undo entry')
    p.evaluate('QuillEditor.state.markSaved(QuillEditor.state.revision);titleField.value="After save";titleField.dispatchEvent(new InputEvent("input",{bubbles:true}))')
    p.check('QuillEditor.ui.history.undoStack.length===titleHistory+3', 'Typing after saving cannot merge into the previous saved edit')
    p.evaluate('document.querySelector("#editor-page-settings-close").click();QuillEditor.select("0.0.1");QuillEditor.palette.save("Test green","#31a675");QuillEditor.palette.makeDefault(QuillEditor.palette.collections()[0]);document.querySelector("#editor-tab-text").click()')
    p.wait('!!document.querySelector("#editor-panel-text [data-style=color]")')
    p.evaluate('document.querySelector(`[data-palette-button="Saved colors: Text color"]`).click();document.querySelector("[data-saved-paint]").click()')
    p.wait('getComputedStyle(document.querySelector("#editor-panel-text [data-style=color]")).backgroundColor==="rgb(49, 166, 117)"')
    p.check('document.querySelector("#editor-panel-text [data-style=color]").value==="rgb(49, 166, 117)" && QuillEditor.getSelectedBlock().styles.base.color==="rgb(49, 166, 117)"', 'Choosing a palette color updates the authored value and visible swatch')
    p.evaluate('QuillEditor.setStyle("color","")')
    p.wait('QuillEditor.getSelectedBlock().styles.base.color===undefined')
    p.check('document.querySelector("#editor-panel-text [data-style=color]").style.backgroundColor!=="rgb(49, 166, 117)"', 'Resetting color also resets its indicator')
    p.evaluate("""QuillEditor.palette.save('Test gradient','linear-gradient(90deg, #ff0000 0%, #0000ff 100%)');document.querySelector(`[data-palette-button="Saved colors: Text color"]`).click();const gradient=QuillEditor.palette.list().find(p=>p.name==='Test gradient');document.querySelector(`[data-saved-paint="${gradient.id}"]`).click()""")
    p.wait('document.querySelector("#editor-panel-text [data-style=color]").style.backgroundImage.includes("linear-gradient")')
    p.check('getComputedStyle(QuillEditor.canvas.getElement("0.0.1")).webkitTextFillColor==="rgba(0, 0, 0, 0)"', 'A palette gradient updates its swatch and text preview together')
    p.evaluate("""document.querySelector(`[data-palette-button="Saved colors: Text color"]`).click();const solid=QuillEditor.palette.list().find(p=>p.name==='Test green');document.querySelector(`[data-saved-paint="${solid.id}"]`).click()""")
    p.wait('document.querySelector("#editor-panel-text [data-style=color]").style.backgroundImage==="none"')
    p.check('getComputedStyle(document.querySelector("#editor-panel-text [data-style=color]")).backgroundColor==="rgb(49, 166, 117)"', 'Returning to a solid palette color removes the old gradient indicator')
    for theme in ('dark', 'light'):
        p.evaluate('document.documentElement.dataset.editorTheme='+repr(theme))
        p.check('''(() => {for(const id of ['editor-viewport','editor-zoom']){const s=document.getElementById(id),style=getComputedStyle(s);if(style.backgroundColor==='rgba(0, 0, 0, 0)'||style.color===style.backgroundColor||parseFloat(style.minHeight)<32)return false;const option=getComputedStyle(s.options[1]);if(option.color===option.backgroundColor)return false;}return true})()''', theme+' viewport and zoom selectors have explicit readable surfaces and full-height targets')
    p.evaluate('document.querySelector(`[data-palette-button="Saved colors: Text color"]`).click()')
    p.check('!!document.querySelector(".editor-palette-dialog .editor-image-close .fa-xmark") && !!document.querySelector(".editor-palette-explore .fa-arrow-left") && ![...document.querySelectorAll("button")].some(b=>/^[×✎−←→]$/.test(b.textContent.trim()))', 'Picker indicators use accessible Font Awesome icons instead of text symbols')
    p.evaluate('document.querySelector(".editor-palette-dialog").close()')
    p.command('Emulation.setDeviceMetricsOverride',width=800,height=940,deviceScaleFactor=1,mobile=False)
    p.check('''(() => {const w=document.querySelector('.editor-workspace').getBoundingClientRect();return ['editor-viewport','editor-zoom'].every(id=>{const r=document.getElementById(id).getBoundingClientRect();return r.left>=w.left && r.right<=w.right && r.top>=w.top && r.bottom<=w.bottom})})()''', 'Viewport controls remain inside the canvas workspace at compact widths')
    p.command('Emulation.setDeviceMetricsOverride',width=1440,height=940,deviceScaleFactor=1,mobile=False)
    if screenshot:
        Path(screenshot).write_bytes(base64.b64decode(p.command('Page.captureScreenshot',format='png')['data']))
    if p.exceptions:
        raise AssertionError(p.exceptions)


if __name__ == '__main__':
    browser.exercise_rich_text = lambda *a: None
    browser.exercise_rich_text_line_breaks = lambda *a: None
    browser.exercise_structured_blocks = lambda *a: None
    browser.exercise = exercise
    browser.main()
