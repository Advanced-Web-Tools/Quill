"""Save actions, autosave responses, editor preferences and the navigation rail."""
import base64
from pathlib import Path
import editor_browser as browser


def exercise(endpoint, origin, screenshot):
    p = browser.Page(endpoint, origin + '/')
    p.command('Page.bringToFront')
    p.wait('!!window.QuillEditor?.ui.settings')
    p.check('document.querySelector("#editor-settings-open").getBoundingClientRect().width>=32 && document.querySelector("#editor-undo").getBoundingClientRect().width>=32', 'Toolbar icons retain accessible hit areas')
    p.check('document.querySelectorAll(".editor-rail button").length===7 && document.querySelector(".editor-rail #editor-page-menu")!==null', 'Rail contains block, components and bottom page settings actions')
    p.check('document.querySelector(".editor-rail").getBoundingClientRect().right<=document.querySelector(".editor-leftbar").getBoundingClientRect().left', 'Rail sits to the left of the layers sidebar')
    p.evaluate('document.querySelector("#editor-add-component").click()')
    p.check('document.querySelector("#editor-block-dialog h2").textContent==="Components" && !document.querySelector("#editor-block-dialog [data-block-key]") && document.querySelector("#editor-add-component").getAttribute("aria-expanded")==="true"', 'Components opens its own dynamic library view')
    p.evaluate('document.querySelector("#editor-block-close").click()')
    p.check('document.activeElement.id==="editor-add-component" && !document.querySelector("#editor-layers").hidden', 'Closing the component picker restores layers and keyboard focus')
    p.evaluate('document.querySelector("#editor-add-block").click()')
    p.check('!!document.querySelector("#editor-block-dialog [data-block-key]") && document.querySelector("#editor-block-dialog h2").textContent==="Add block"', 'Block picker restores all registered block types')
    p.evaluate('document.querySelector("#editor-block-close").click();document.querySelector("#editor-page-menu").click()')
    p.wait('document.querySelector("#editor-page-settings").getBoundingClientRect().left>=document.querySelector(".editor-rail").getBoundingClientRect().right')
    p.check('!document.querySelector("#editor-page-settings").hidden && document.querySelector("#editor-page-settings").getBoundingClientRect().left>=document.querySelector(".editor-rail").getBoundingClientRect().right', 'Page settings opens alongside the rail')
    p.evaluate('document.querySelector("#editor-add-component").click()')
    p.check('document.querySelector("#editor-page-settings").hidden && !document.querySelector("#editor-block-dialog").hidden', 'Switching rail destinations does not leave an overlay hiding the picker')
    p.evaluate('document.querySelector("#editor-block-close").click();window.originalDocument=JSON.stringify(QuillEditor.getDocument());window.saveRequests=[];window.fetch=async(url,options)=>{saveRequests.push(Object.fromEntries(options.body.entries()));return new Response(JSON.stringify({message:"Saved.",data:{lock_version:saveRequests.length+1}}),{status:200})};document.querySelector("#editor-settings-open").click()')
    for theme in ('dark', 'light'):
        p.evaluate(f'document.querySelector("#editor-settings-theme").value="{theme}";document.querySelector("#editor-settings-theme").dispatchEvent(new Event("change"))')
        p.check('''(() => {const d=document.querySelector('#editor-settings-dialog');return [...d.querySelectorAll(':scope > header,:scope > footer')].every(e=>getComputedStyle(e).backgroundColor===getComputedStyle(d).backgroundColor)})()''', theme+' settings uses one surface color')
    p.evaluate('document.querySelector("#editor-settings-save-method").value="publish";document.querySelector("#editor-settings-save-method").dispatchEvent(new Event("change"))')
    p.check('saveRequests.length===0 && QuillEditor.ui.save.mode==="publish" && JSON.stringify(QuillEditor.getDocument())===originalDocument', 'Changing default method updates the button without saving or editing the document')
    p.evaluate('document.querySelector("#editor-settings-dialog").close();document.querySelector("#editor-save-mode").value="draft";document.querySelector("#editor-save-mode").dispatchEvent(new Event("change"))')
    p.wait('!QuillEditor.ui.save.saving')
    p.check('saveRequests.length===1 && saveRequests[0].mode==="draft" && document.querySelector("#editor-save-mode").value==="" && QuillEditor.ui.save.mode==="draft"', 'Choosing a save action saves immediately once and leaves an arrow-only selector')
    p.evaluate('document.querySelector("#editor-save-mode").value="publish";document.querySelector("#editor-save-mode").dispatchEvent(new Event("change"))')
    p.wait('!QuillEditor.ui.save.saving')
    p.check('saveRequests.length===2 && saveRequests[1].mode==="publish" && saveRequests[1].lock_version==="2"', 'Publish selector executes once using the latest document version')
    p.evaluate('document.querySelector("#editor-save").click()')
    p.wait('!QuillEditor.ui.save.saving')
    p.check('saveRequests.length===3 && saveRequests[2].mode==="publish"', 'Main button repeats the chosen action')
    p.check('''(() => {const b=document.querySelector('#editor-save').getBoundingClientRect(),s=document.querySelector('#editor-save-mode').getBoundingClientRect();return Math.abs(b.right-s.left)<1 && Math.abs(b.height-s.height)<1})()''', 'Save button and arrow touch at equal height')
    p.evaluate('''(async()=>{QuillEditor.select('0.0.0');QuillEditor.setContent('Redirected save');window.fetch=async()=>{const r=new Response(JSON.stringify({message:'Draft saved.',data:{lock_version:5}}),{status:200});Object.defineProperties(r,{redirected:{value:true},url:{value:location.origin+'/canonical/save'}});return r;};await QuillEditor.ui.save.save('draft',{automatic:true})})()''')
    p.check('!QuillEditor.state.dirty && document.querySelector("#editor-error").hidden && document.querySelector("#editor-success").hidden', 'Autosave accepts a valid redirected save confirmation without false login errors')
    p.check('(async()=>{const data=await QuillLibraryClient.request("/library-fixture");return data.lock_version===5})()', 'Background library requests also accept valid redirected JSON')
    p.evaluate('''(async()=>{QuillEditor.setContent('Retain on login');window.fetch=async()=>{const r=new Response('<html>Sign in</html>',{status:200});Object.defineProperties(r,{redirected:{value:true},url:{value:location.origin+'/dashboard/login'}});return r;};await QuillEditor.ui.save.save('draft',{automatic:true}).catch(()=>{})})()''')
    p.check('QuillEditor.state.dirty && document.querySelector("#editor-error").textContent.includes("login session expired")', 'Real login redirects retain edits and report authentication failure')
    p.evaluate('''(async()=>{window.fetch=async()=>new Response(JSON.stringify({message:'Unauthorized'}),{status:401});await QuillEditor.ui.save.save('draft',{automatic:true}).catch(()=>{})})()''')
    p.check('QuillEditor.state.dirty && document.querySelector("#editor-error").textContent.includes("login session expired")', 'HTTP 401 is still recognized as an expired login')
    p.evaluate('''(async()=>{window.fetch=async()=>new Response('<html>Server failure</html>',{status:502});await QuillEditor.ui.save.save('draft',{automatic:true}).catch(()=>{})})()''')
    p.check('QuillEditor.state.dirty && document.querySelector("#editor-error").textContent.includes("save confirmation") && !document.querySelector("#editor-error").textContent.includes("login")', 'Unexpected HTML is not misreported as logout')
    p.evaluate('''(async()=>{window.fetch=async()=>new Response(JSON.stringify({message:'Your session token expired.'}),{status:403});await QuillEditor.ui.save.save('draft',{automatic:true}).catch(()=>{})})()''')
    p.check('document.querySelector("#editor-error").textContent.includes("session token expired") && QuillEditor.state.dirty', 'CSRF failures retain the server explanation and local edits')
    p.evaluate('window.beforePreferencesReload=true;QuillEditor.state.markSaved(QuillEditor.state.revision)')
    p.command('Page.reload')
    p.wait('!window.beforePreferencesReload && !!window.QuillEditor?.ui.settings')
    p.check('QuillEditor.ui.save.mode==="publish" && document.querySelector("#editor-save-mode").value===""', 'Saved default method is restored when opening the editor again')
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
