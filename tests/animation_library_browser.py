"""Shared animation library workflow, exercising the real editor UI in Chrome."""
import base64
from pathlib import Path
import editor_browser as browser
from animation_studio_browser import mock_library


def exercise(endpoint, origin, screenshot):
    page = browser.Page(endpoint, origin + '/')
    page.wait('!!window.QuillEditor?.ui.animationLibrary')
    mock_library(page)
    page.evaluate('''window.seedDefinition={id:'qanim-seed',name:'Fade',duration:1200,delay:0,iterations:1,direction:'normal',fillMode:'both',easing:'linear',tracks:{opacity:[{time:0,value:'0'},{time:1200,value:'1'}]}};window.animationRecords={1:{id:1,name:'Fade',version:1,definition:structuredClone(seedDefinition)},2:{id:2,name:'Spin',version:1,definition:{...structuredClone(seedDefinition),id:'qanim-spin',name:'Spin',tracks:{rotate:[{time:0,value:'0deg'},{time:1200,value:'45deg'}]}}}};nextAnimationId=3;QuillEditor.select('0.0')''')
    page.wait('!!document.querySelector("[data-animation-add]")')
    page.evaluate('QuillEditor.ui.properties.showCategory("motion");QuillEditor.ui.animationLibrary.refresh()')
    page.check('document.querySelector("[data-animation-remove]").disabled && document.querySelector(".editor-animation-active").textContent.includes("No animations")', 'Empty active list explains adding animations and disables minus')
    page.evaluate('document.querySelector("[data-animation-add]").click()')
    page.wait('document.querySelector(".editor-animation-picker").matches(":popover-open") && !!document.querySelector("[data-animation-use=\\"1\\"]")')
    page.check('document.querySelector("[data-animation-new]").checkVisibility() && document.querySelectorAll("[data-animation-use]").length===2', 'Plus opens the adjacent shared selector with all animations and New animation')
    if screenshot:
        Path(screenshot).write_bytes(base64.b64decode(page.command('Page.captureScreenshot',format='png')['data']))
    page.evaluate('document.querySelector("[data-animation-use=\\"1\\"]").click();document.querySelector("[data-animation-add]").click();document.querySelector("[data-animation-use=\\"2\\"]").click()')
    page.check('QuillEditor.getSelectedBlock().animations.length===2 && !QuillEditor.getDocument().settings?.animations && document.querySelectorAll("[data-active-animation]").length===2', 'Elements reference multiple shared database identities without embedding definitions')
    page.evaluate('document.querySelector("[data-active-animation=\\"1\\"]").click();document.querySelector("[data-animation-remove]").click()')
    page.check('QuillEditor.getSelectedBlock().animations.length===1 && !!animationRecords[1]', 'Minus removes only the selected reference and keeps the shared resource')
    page.evaluate('QuillEditor.ui.history.undo()')
    page.check('QuillEditor.getSelectedBlock().animations.length===2', 'Reference removal participates in page undo')
    page.evaluate('document.querySelector("[data-animation-add]").click();document.querySelector("[data-animation-new]").click()')
    page.wait('!!QuillEditor.ui.animations.adapter')
    page.evaluate('''QuillEditor.ui.animations.adapter.setStyle('opacity','.2');QuillEditor.ui.animations.session.seek(1200);QuillEditor.ui.animations.adapter.setStyle('opacity','.8');document.querySelector('#studio-name').value='New fade';document.querySelector('[data-studio-save]').click()''')
    page.wait('!QuillEditor.ui.animations.active')
    page.check('animationRecords[3].name==="New fade" && QuillEditor.getSelectedBlock().animations.some(binding=>binding.animation_id===3)', 'New animation saves to the shared library and binds its database ID')
    page.evaluate('document.querySelector("[data-active-animation=\\"3\\"]").click();document.querySelector("[data-animation-edit]").click()')
    page.wait('!!QuillEditor.ui.animations.adapter')
    page.evaluate('''QuillEditor.ui.animations.session.seek(1200);QuillEditor.ui.animations.adapter.setStyle('opacity','.6');document.querySelector('[data-studio-save]').click()''')
    page.wait('!!document.querySelector(".editor-animation-confirm[open]")')
    page.check('document.querySelector(".editor-animation-confirm").textContent.includes("everywhere it is used") && !!document.querySelector("[data-animation-choice=new]") && !!document.querySelector("[data-animation-choice=replace]")', 'Editing a shared animation offers Save as new or Replace and explains shared impact')
    page.evaluate('document.querySelector("[data-animation-choice=cancel]").click()')
    page.wait('document.querySelector("#animation-studio").getAttribute("aria-busy")==="false"')
    page.check('QuillEditor.ui.animations.active && animationRecords[3].definition.tracks.opacity.at(-1).value==="0.8"', 'Cancelling the save decision retains temporary edits and leaves the resource unchanged')
    page.evaluate('document.querySelector("[data-studio-save]").click()')
    page.wait('!!document.querySelector("[data-animation-choice=new]")')
    page.evaluate('document.querySelector("[data-animation-choice=new]").click()')
    page.wait('!QuillEditor.ui.animations.active')
    page.check('animationRecords[3].definition.tracks.opacity.at(-1).value==="0.8" && animationRecords[4].definition.tracks.opacity.at(-1).value==="0.6" && QuillEditor.getSelectedBlock().animations.some(binding=>binding.animation_id===4) && !QuillEditor.getSelectedBlock().animations.some(binding=>binding.animation_id===3)', 'Save as new preserves the original and swaps only the edited element reference')
    page.evaluate('document.querySelector("[data-active-animation=\\"4\\"]").click();document.querySelector("[data-animation-edit]").click()')
    page.wait('!!QuillEditor.ui.animations.adapter')
    page.evaluate('QuillEditor.ui.animations.session.seek(1200);QuillEditor.ui.animations.adapter.setStyle("opacity",".4");document.querySelector("[data-studio-save]").click()')
    page.wait('!!document.querySelector("[data-animation-choice=replace]")')
    page.evaluate('document.querySelector("[data-animation-choice=replace]").click()')
    page.wait('!QuillEditor.ui.animations.active')
    page.check('animationRecords[4].version===2 && Object.keys(animationRecords).length===4 && animationRecords[4].definition.tracks.opacity.at(-1).value==="0.4"', 'Replace updates the shared identity using its version instead of creating a duplicate')
    page.evaluate('document.querySelector("[data-animation-add]").click();document.querySelector("[data-animation-delete=\\"1\\"]").click()')
    page.wait('!!document.querySelector(".editor-animation-confirm[open]")')
    page.check('document.querySelector(".editor-animation-confirm").textContent.includes("Are you sure") && !!animationRecords[1]', 'Deleting a library resource requires an explicit confirmation')
    page.evaluate('document.querySelector("[data-animation-choice=cancel]").click();document.querySelector("[data-animation-delete=\\"1\\"]").click();document.querySelector("[data-animation-choice=delete]").click()')
    page.wait('!animationRecords[1]')
    page.check('QuillEditor.getSelectedBlock().animations.some(binding=>binding.animation_id===1) && document.querySelector("[data-active-animation=\\"1\\"]").textContent.includes("Unavailable")', 'Deleted resources leave removable references and do not delete or alter elements')
    page.evaluate('document.querySelector(".editor-animation-picker").hidePopover();document.querySelector("[data-active-animation=\\"1\\"]").click();document.querySelector("[data-animation-remove]").click()')
    page.check('!QuillEditor.getSelectedBlock().animations.some(binding=>binding.animation_id===1)', 'Unavailable animation references can be removed normally')
    page.evaluate("""QuillEditor.state.updateSettings({animations:{'qanim-legacy':{...structuredClone(seedDefinition),id:'qanim-legacy',name:'Legacy fade'}},animation_bindings:[]});""")
    page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
    page.evaluate("""document.querySelector('[data-animation-add]').click();document.querySelector('[data-legacy-animation-use="qanim-legacy"]').click();""")
    page.check('QuillEditor.getDocument().settings.animation_bindings.length===1 && document.querySelector(".editor-animation-active").textContent.includes("Legacy fade")', 'Existing page animation definitions remain discoverable and reusable')
    page.evaluate('document.querySelector("[data-animation-edit]").click()')
    page.wait('!!QuillEditor.ui.animations.adapter')
    page.evaluate('QuillEditor.ui.animations.save()')
    page.check('!!animationRecords[5] && QuillEditor.getSelectedBlock().animations.some(binding=>binding.animation_id===5) && QuillEditor.getDocument().settings.animation_bindings.length===0 && !!QuillEditor.getDocument().settings.animations["qanim-legacy"]', 'Saving an edited legacy animation creates a shared resource and replaces only its selected binding')
    if page.exceptions:
        raise AssertionError(page.exceptions)


if __name__ == '__main__':
    browser.exercise_rich_text = lambda *args: None
    browser.exercise_structured_blocks = lambda *args: None
    browser.exercise = exercise
    browser.main()
