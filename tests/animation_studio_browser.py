"""Isolated Animation Studio regression suite, using the real editor and Chrome."""
import base64
from pathlib import Path
import editor_browser as browser


def mock_library(page):
    # Browser interactions use a deterministic HTTP fixture. Real endpoint/database
    # behavior is exercised separately by tests/animation_library.php.
    page.evaluate("""(() => {
        const originalFetch=window.fetch.bind(window);window.animationRecords={};window.animationRequests=[];window.nextAnimationId=1;
        window.withoutAnimationReferences=document=>{const result=structuredClone(document);const visit=blocks=>blocks.forEach(block=>{if(block._quill)delete block._quill.animations;visit(block.children)});result.sections.forEach(section=>visit(section.blocks));return result};
        window.fetch=async(url,options={})=>{
            const path=new URL(url,location.href).pathname;
            if(!path.startsWith('/dashboard/quill/editor-animation'))return originalFetch(url,options);
            await new Promise(resolve=>setTimeout(resolve,10));
            const id=Number(path.split('/')[4]),method=options.method||'GET',body=options.body;
            animationRequests.push({path,method});
            const response=(data,status=200,message='OK')=>new Response(JSON.stringify({data,message}),{status,headers:{'Content-Type':'application/json'}});
            if(method==='GET')return id?animationRecords[id]?response({animation:animationRecords[id]}):response({},404,'Animation not found.'):response({animations:Object.values(animationRecords).map(({definition,...summary})=>summary)});
            if(window.failAnimationSave)return response({},500,'Animation save failed.');
            if(id&&(!animationRecords[id]||Number(body.get('lock_version'))!==animationRecords[id].version))return response({},409,'This animation changed. Reopen it before replacing it.');
            if(path.endsWith('/delete')){delete animationRecords[id];return response({});}
            const definition=JSON.parse(body.get('definition')),key=id||nextAnimationId++;
            const item={id:key,name:definition.name,version:(animationRecords[key]?.version||0)+1,admin_id:1,created_at:animationRecords[key]?.created_at||'2026-10-16 12:00:00',updated_at:'2026-10-16 12:01:00',definition};
            animationRecords[key]=item;return response({animation:item},id?200:201);
        };
    })()""")


def exercise_dimension_isolation(endpoint, origin, screenshot):
    page = browser.Page(endpoint, origin + '/')
    page.command('Page.bringToFront')
    page.wait('!!window.QuillEditor?.ui.animations && !!QuillEditor.canvas.getElement("0.0")')
    mock_library(page)
    page.evaluate("""document.querySelector('[data-path="0.0"]').click()""")
    page.wait('!!document.querySelector("#editor-properties [data-style=width]")')
    for property, fraction in [('width', .6), ('height', .45)]:
        page.evaluate("""(() => {const input=document.querySelector('#editor-properties [data-style=%s]');for(let node=input;node;node=node.parentElement)if(node.tagName==='DETAILS')node.open=true;input.scrollIntoView({block:'center'});input.focus();})()""" % property)
        point = page.evaluate("""(() => {const r=document.querySelector('#editor-properties [data-style=%s]').closest('.editor-dimension').querySelector('[type=range]').getBoundingClientRect();return {x:r.x+r.width*%s,y:r.y+r.height/2}})()""" % (property, fraction))
        page.command('Input.dispatchMouseEvent', type='mouseMoved', **point)
        page.command('Input.dispatchMouseEvent', type='mousePressed', button='left', buttons=1, clickCount=1, **point)
        page.command('Input.dispatchMouseEvent', type='mouseReleased', button='left', buttons=0, clickCount=1, **point)
    page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
    page.evaluate("""window.beforeDimensions=JSON.stringify(QuillEditor.getDocument());window.beforeDimensionValues=['width','height'].map(property=>document.querySelector('#editor-properties [data-style='+property+']').value);QuillEditor.ui.animations.open()""")
    page.wait('!!QuillEditor.ui.animations.adapter && !!document.querySelector("#studio-properties [data-style=opacity]")')
    page.check('''document.querySelector('#studio-recording').checkVisibility() && document.querySelector('#studio-recording').textContent==='Recording property changes' && getComputedStyle(document.querySelector('#studio-recording')).color===getComputedStyle(document.querySelector('#studio-error')).color''', 'Studio clearly identifies property recording with the shared red status color')
    page.check('''['display','position','constraints','z-index','font-family','font-style','text-transform','content','animation-duration','animation-play-state','transition-duration','border-top-radius'].every(property=>!QuillAnimations.supports(property) && !document.querySelector('#studio-properties [data-control="'+property+'"]')) && [...document.querySelectorAll('#studio-properties [data-control]')].every(control=>QuillAnimations.supports(control.dataset.control))''', 'Studio removes unsupported controls and only exposes supported visual CSS animation properties')
    page.check('''(() => {const studio=QuillEditor.ui.animations;let rejected=0;for(const property of ['animation-duration','transition-duration','content','border-top-radius']){try{studio.session.add(property)}catch{rejected++}try{studio.adapter.setStyle(property,'initial')}catch{rejected++}}return rejected===8 && !Object.keys(studio.session.definition.tracks).length})()''', 'Manual tracks and extension style callbacks also reject unsupported animation properties')
    page.check('''['opacity','transform','transform-origin','border-radius','padding','background-image','background-position','box-shadow','filter','--quill-animation-translate-x'].every(property=>QuillAnimations.supports(property))''', 'Spatial controls, colors, gradients and other CSS-supported animation properties remain available')
    page.evaluate("""window.initialPreviewDimensions=['width','height'].map(property=>QuillEditor.ui.animations.adapter.getStyleDeclarations().getPropertyValue(property));""")
    for time in [0, 300, 800, 1200]:
        page.evaluate("""(() => {const slider=document.querySelector('#studio-playhead');slider.value=%s;slider.dispatchEvent(new Event('input',{bubbles:true}));})()""" % time)
        page.check("""JSON.stringify(QuillEditor.getDocument())===beforeDimensions && Object.keys(QuillEditor.ui.animations.session.definition.tracks).length===0 && JSON.stringify(['width','height'].map(property=>document.querySelector('#editor-properties [data-style='+property+']').value))===JSON.stringify(beforeDimensionValues) && JSON.stringify(['width','height'].map(property=>QuillEditor.ui.animations.adapter.getStyleDeclarations().getPropertyValue(property)))===JSON.stringify(initialPreviewDimensions)""", 'Width and Height edited before Studio remain fixed when scrubbing to %sms' % time)
    page.evaluate("""const opacity=document.querySelector('#studio-properties [data-style=opacity]');opacity.value='40';opacity.dispatchEvent(new Event('input',{bubbles:true}));QuillEditor.ui.animations.session.seek(600);""")
    page.check("""JSON.stringify(Object.keys(QuillEditor.ui.animations.session.definition.tracks))==='["opacity"]' && JSON.stringify(QuillEditor.getDocument())===beforeDimensions && JSON.stringify(['width','height'].map(property=>QuillEditor.ui.animations.adapter.getStyleDeclarations().getPropertyValue(property)))===JSON.stringify(initialPreviewDimensions)""", 'Animating opacity never captures previously edited Width or Height as animation tracks')
    page.check('document.querySelector("#studio-track-hint").hidden', 'Tracks with multiple keyframes do not show the single-keyframe guidance')
    page.evaluate("""window.guidanceStudio=QuillEditor.ui.animations;guidanceStudio.session.removeTrack('opacity');guidanceStudio.session.seek(0);guidanceStudio.adapter.setStyle('width','210px');""")
    page.check('''!document.querySelector('#studio-track-hint').hidden && document.querySelector('#studio-track-hint').textContent.includes('Width has only one keyframe') && document.querySelector('#studio-track-hint').textContent.includes('original appearance') && document.querySelector('[data-track=width]').dataset.singleKeyframe==='true' && document.querySelector('[data-track=width] .studio-track-count').textContent==='1 keyframe · Add another' ''', 'A single Width keyframe displays a track indicator and explains how to define a second value')
    page.evaluate("guidanceStudio.adapter.setStyle('height','170px')")
    page.check('document.querySelector("#studio-track-hint").textContent.startsWith("2 tracks have only one keyframe")', 'Guidance summarizes multiple tracks with only one keyframe')
    if screenshot:
        hint_screenshot = Path(screenshot).with_name(Path(screenshot).stem + '-single-keyframe.png')
        hint_screenshot.write_bytes(base64.b64decode(page.command('Page.captureScreenshot', format='png')['data']))
    page.evaluate("guidanceStudio.session.seek(1200);guidanceStudio.adapter.setStyle('width','250px')")
    page.check('''document.querySelector('[data-track=width]').dataset.singleKeyframe==='false' && document.querySelector('[data-track=width] .studio-track-count').textContent==='2 keyframes' && document.querySelector('#studio-track-hint').textContent.startsWith('Height has only one keyframe')''', 'Adding a second keyframe clears the Width indicator while retaining guidance for Height')
    page.evaluate("guidanceStudio.adapter.setStyle('height','220px')")
    page.check('document.querySelector("#studio-track-hint").hidden && !document.querySelector("#studio-track-hint").checkVisibility()', 'Guidance disappears when every track has a second keyframe')
    page.evaluate("guidanceStudio.session.select('width',1200);guidanceStudio.session.remove()")
    page.check('!document.querySelector("#studio-track-hint").hidden && document.querySelector("#studio-track-hint").textContent.startsWith("Width has only one keyframe")', 'Removing the second keyframe restores guidance immediately')
    page.evaluate('guidanceStudio.session.undo()')
    page.check('document.querySelector("#studio-track-hint").hidden && JSON.stringify(QuillEditor.getDocument())===beforeDimensions', 'Undo updates guidance without mutating the original document')
    page.evaluate('QuillEditor.ui.animations.discard()')
    if page.exceptions:
        raise AssertionError(page.exceptions)


def exercise(endpoint, origin, screenshot):
    exercise_dimension_isolation(endpoint, origin, screenshot)
    page = browser.Page(endpoint, origin + '/')
    page.command('Page.bringToFront')
    page.wait('!!window.QuillEditor?.ui.animations && !!QuillEditor.canvas.getElement("0.0")')
    mock_library(page)
    page.evaluate('''QuillEditor.select('0.0');QuillEditor.setStyle('transform-origin','50% 50%');''')
    page.wait('QuillEditor.canvas.getElement("0.0").style.transformOrigin==="50% 50%"')
    page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
    page.wait('!!document.querySelector("#editor-properties [data-style=width]")')
    page.evaluate("""window.lastPropertySlider=document.querySelector('#editor-properties [data-style=width]').closest('.editor-dimension').querySelector('[type=range]');lastPropertySlider.value='23';lastPropertySlider.dispatchEvent(new Event('input',{bubbles:true}));lastPropertySlider.focus();""")
    page.wait('QuillEditor.canvas.getElement("0.0").style.width==="23px"')
    page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
    page.evaluate('''window.studioOriginal=JSON.stringify(QuillEditor.getDocument());window.studioSections=JSON.stringify(QuillEditor.getDocument().sections);window.studioNode=QuillEditor.canvas.getElement('0.0');window.studioMarkup=studioNode.outerHTML;window.studioHistory=QuillEditor.ui.history.undoStack.length;window.studioRevision=QuillEditor.state.revision;window.canvasRenderCount=0;QuillEditor.on('canvasrendered',()=>canvasRenderCount++);QuillEditor.ui.animations.open()''')
    page.wait('!!QuillEditor.ui.animations.session && !!document.querySelector("#studio-properties [data-style=opacity]")')
    page.check('JSON.stringify(QuillEditor.getDocument())===studioOriginal && QuillEditor.canvas.getElement("0.0")===studioNode && studioNode.outerHTML===studioMarkup && canvasRenderCount===0', 'Opening Studio leaves document and original canvas node byte-for-byte unchanged')
    page.check('document.querySelector("#quill-editor").inert && document.querySelector("#animation-studio").open && !!document.querySelector("#studio-preview iframe").contentDocument.querySelector("section")', 'Studio locks the normal editor and renders a separate preview instance')
    page.check('''(() => {let blocked=0;for(const edit of [()=>QuillEditor.setStyle('opacity','.2'),()=>QuillEditor.select('0.0.0'),()=>QuillEditor.state.insertBlocks(0,[QuillEditor.state.getBlock('0.0')],null),()=>QuillEditor.ui.history.undo()]){try{edit()}catch{blocked++}}return blocked===4 && JSON.stringify(QuillEditor.getDocument())===studioOriginal && QuillEditor.ui.history.undoStack.length===studioHistory})()''', 'The central isolation lease blocks normal editing, selection, insertion and history')
    page.evaluate("window.escapedStudioEdits=0;for(const type of ['input','change'])document.addEventListener(type,event=>{if(event.target.closest('#animation-studio'))escapedStudioEdits++})")
    timeline = page.evaluate("""(() => {const input=document.querySelector('#studio-playhead'),r=input.getBoundingClientRect();return {x:r.x+r.width*.65,y:r.y+r.height/2}})()""")
    page.command('Input.dispatchMouseEvent', type='mousePressed', button='left', buttons=1, clickCount=1, **timeline)
    page.command('Input.dispatchMouseEvent', type='mouseReleased', button='left', buttons=0, clickCount=1, **timeline)
    page.check('QuillEditor.ui.animations.session.time>0 && lastPropertySlider.value==="23" && JSON.stringify(QuillEditor.getDocument())===studioOriginal', 'Dragging the animation slider cannot change the previously focused Properties slider')
    page.evaluate('''window.studio=QuillEditor.ui.animations;studio.session.seek(600);const opacity=document.querySelector('#studio-properties [data-style=opacity]');opacity.value='40';opacity.dispatchEvent(new Event('input'));''')
    page.wait('!!studio.session.definition.tracks.opacity')
    page.check('studio.session.definition.tracks.opacity[1].time===600 && Number(studio.session.definition.tracks.opacity[1].value)===.4 && Number(getComputedStyle(studio.adapter.canvas.getElement(studio.adapter.selectedPath)).opacity)<.41', 'Shared opacity control creates a keyframe at the playhead and changes only the preview')
    page.evaluate('''studio.session.seek(1200);const rotate=document.querySelector('#studio-properties [data-transform=rotate]');rotate.value='45';rotate.dispatchEvent(new Event('input'));document.querySelector('#studio-properties [data-transform-origin="100% 0%"]').click();''')
    page.wait('!!studio.session.definition.tracks.rotate && !!studio.session.definition.tracks["transform-origin"]')
    page.check('studio.session.definition.tracks.rotate.at(-1).value==="45deg" && studio.session.definition.tracks["transform-origin"].at(-1).value==="100% 0%"', 'Shared rotation and 3×3 origin controls write independent animation tracks')
    page.evaluate('''studio.adapter.setStyle('background-color','#ff0066');studio.adapter.setStyle('background-image','linear-gradient(90deg, red, blue)');studio.adapter.setStyle('box-shadow','0px 4px 10px black');document.querySelector('#studio-properties [data-radius-corner="top-left"]').click();const radius=document.querySelector('#studio-properties [data-style=border-top-left-radius]');radius.value='24';radius.dispatchEvent(new Event('input'));''')
    page.check('["background-color","background-image","box-shadow","border-top-left-radius","border-top-right-radius","border-bottom-right-radius","border-bottom-left-radius"].every(property=>!!studio.session.definition.tracks[property])', 'Colors, gradients, shadows and the linked four-corner diagram remain animation controls')
    page.check('escapedStudioEdits===0', 'Studio input and change events stay inside its editing context')
    page.evaluate('''const field=document.querySelector('#studio-properties [data-style=border-top-left-radius]').closest('.editor-dimension');for(let parent=field.parentElement;parent;parent=parent.parentElement)if(parent.tagName==='DETAILS')parent.open=true;field.scrollIntoView({block:'center'});field.querySelector('input[type=text]').focus();window.studioRadiusSlider=field.querySelector('[type=range]');''')
    page.check('''(() => {const r=studioRadiusSlider.getBoundingClientRect(),parent=studioRadiusSlider.parentElement.getBoundingClientRect();return r.top>=parent.top && r.bottom<=parent.bottom && r.width<=parent.width+.1})()''', 'A focused property slider stays within its own control instead of overlapping another slider')
    radius_point = page.evaluate('''(() => {const r=studioRadiusSlider.getBoundingClientRect();return {x:r.x+r.width*.3,y:r.y+r.height/2}})()''')
    page.command('Input.dispatchMouseEvent', type='mousePressed', button='left', buttons=1, clickCount=1, **radius_point)
    page.command('Input.dispatchMouseEvent', type='mouseReleased', button='left', buttons=0, clickCount=1, **radius_point)
    page.check('Number(studioRadiusSlider.value)>24 && lastPropertySlider.value==="23" && escapedStudioEdits===0', 'Dragging a shared Studio property slider leaves the last normal Properties slider unchanged')
    page.check('JSON.stringify(QuillEditor.getDocument())===studioOriginal && QuillEditor.state.revision===studioRevision && QuillEditor.ui.history.undoStack.length===studioHistory && studioNode.outerHTML===studioMarkup && canvasRenderCount===0', 'Animation property edits never enter document state, block history or the live canvas')
    page.evaluate('studio.session.select("opacity",600);studio.session.editKeyframe({time:500,value:"0.3",easing:"ease-out"});studio.session.seek(350)')
    page.check('studio.session.definition.tracks.opacity.some(frame=>frame.time===500 && frame.easing==="ease-out") && JSON.stringify(QuillEditor.getDocument())===studioOriginal', 'Moving a keyframe, per-keyframe easing and scrubbing affect temporary animation state only')
    page.evaluate('studio.session.select("opacity",500);studio.session.remove();studio.session.undo();studio.session.redo();studio.session.undo()')
    page.check('studio.session.definition.tracks.opacity.some(frame=>frame.time===500) && QuillEditor.ui.history.undoStack.length===studioHistory', 'Keyframe deletion and animation undo/redo use a separate temporary history')
    if screenshot:
        Path(screenshot).write_bytes(base64.b64decode(page.command('Page.captureScreenshot',format='png')['data']))
    page.evaluate('document.querySelector("[data-studio-close]").click()')
    page.check('document.querySelector("#studio-close-confirm").open', 'Closing a dirty animation offers Save, Discard and Continue Editing')
    page.evaluate('document.querySelector("[data-studio-continue]").click();studio.discard()')
    page.check('!studio.active && !document.querySelector("#animation-studio").open && !document.querySelector("#quill-editor").inert && JSON.stringify(QuillEditor.getDocument())===studioOriginal && studioNode.outerHTML===studioMarkup', 'Discard destroys the preview and restores editing without restoring or rewriting blocks')
    page.evaluate('QuillEditor.ui.animations.open()')
    page.wait('!!QuillEditor.ui.animations.session && !!document.querySelector("#studio-properties [data-style=opacity]")')
    page.evaluate('''(async()=>{window.studio=QuillEditor.ui.animations;studio.session.seek(1200);studio.adapter.setStyle('opacity','0.25');document.querySelector('#studio-name').value='Hero entrance';await studio.save();window.savedAnimationId=QuillEditor.getSelectedBlock().animations[0].animation_id;})()''')
    page.check('JSON.stringify(withoutAnimationReferences(QuillEditor.getDocument()).sections)===studioSections && QuillEditor.canvas.getElement("0.0")===studioNode && studioNode.outerHTML===studioMarkup && canvasRenderCount===0 && QuillEditor.ui.history.undoStack.length===studioHistory+1', 'Save commits database references in one history step without rewriting normal properties or rebuilding the live canvas')
    page.check('QuillEditor.getSelectedBlock().animations[0].animation_id===savedAnimationId && !!animationRecords[savedAnimationId] && !studio.active && !document.querySelector("#quill-editor").inert', 'Block references use the library database ID and Save exits Studio')
    page.evaluate('window.savedAnimations=JSON.stringify(animationRecords);QuillEditor.ui.animations.open(savedAnimationId)')
    page.wait('!!QuillEditor.ui.animations.session')
    page.check('JSON.stringify(QuillEditor.ui.animations.session.definition)===JSON.stringify(animationRecords[savedAnimationId].definition)', 'Committed animations reopen without keyframe data loss')
    page.evaluate('QuillEditor.ui.animations.session.seek(800);QuillEditor.ui.animations.adapter.setStyle("opacity",".6");QuillEditor.ui.animations.discard()')
    page.check('JSON.stringify(animationRecords)===savedAnimations && JSON.stringify(withoutAnimationReferences(QuillEditor.getDocument()).sections)===studioSections', 'Discarding edits to an existing animation preserves its registry and the full block tree')
    page.evaluate('QuillEditor.setStyle("opacity",".8")')
    page.wait('QuillEditor.canvas.getElement("0.0").style.opacity==="0.8"')
    page.check('QuillEditor.getSelectedBlock().attributes.style.includes("opacity: 0.8")', 'Normal property editing works after leaving Animation Studio')
    page.evaluate('QuillEditor.state.moveBlock("0.0","1","inside")')
    page.check('QuillEditor.state.getBlock("1.1")._quill.animations[0].animation_id===savedAnimationId', 'Database animation references move with their block')
    page.evaluate('QuillEditor.select("1.1");QuillEditor.removeBlock()')
    page.check('!!animationRecords[savedAnimationId] && !JSON.stringify(QuillEditor.getDocument().sections).includes("animation_id")', 'Removing a bound element drops only its binding and retains the reusable animation')
    if page.exceptions:
        raise AssertionError(page.exceptions)

    external = browser.Page(endpoint, origin+'/extensions')
    external.wait('!!window.QuillEditor?.ui.animations')
    mock_library(external)
    external.evaluate('QuillEditor.insertBlock("Demo:card",0,null)')
    external.wait('!!document.querySelector("[data-demo-caption]")')
    external.evaluate('QuillEditor.setAttribute("data-detail","yes")')
    external.wait('QuillEditor.canvas.getElement(QuillEditor.selectedPath)?.getAttribute("data-detail")==="yes"')
    external.evaluate('window.externalPath=QuillEditor.selectedPath;window.externalOriginal=JSON.stringify(QuillEditor.getDocument());QuillEditor.ui.animations.open()')
    external.wait('!!QuillEditor.ui.animations.session')
    external.wait('!!document.querySelector("#studio-properties [data-demo-detail]")')
    external.evaluate('''const detail=document.querySelector('#studio-properties [data-demo-detail]');detail.value='18px';detail.dispatchEvent(new Event('input'));window.externalBlocked=0;try{QuillEditor.ui.animations.adapter.setProps({title:'Blocked'})}catch{externalBlocked++}''')
    external.check('externalBlocked===1 && JSON.stringify(QuillEditor.getDocument())===externalOriginal && !!QuillEditor.ui.animations.session.definition.tracks["border-top-left-radius"]', 'The extension adapter records supported styles and rejects structural/property writes')
    external.evaluate('QuillEditor.ui.animations.discard()')
    if external.exceptions:
        raise AssertionError(external.exceptions)


if __name__ == '__main__':
    browser.exercise_rich_text = lambda *args: None
    browser.exercise_structured_blocks = lambda *args: None
    browser.exercise = exercise
    browser.main()
