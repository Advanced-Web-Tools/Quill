"""Named local/global palettes, animated exploration and defaults in the real editor."""
import base64
from pathlib import Path
import editor_browser as browser
from animation_studio_browser import mock_library


def mock_palettes(page):
    page.evaluate('''(() => {
        const original=window.fetch.bind(window);window.globalPaletteRecords={1:{id:1,name:'Global brand',version:1,colors:[{id:'qp-global-red',name:'Global red',value:'#ff3344'},{id:'qp-global-gradient',name:'Global gradient',value:'linear-gradient(90deg, red, blue)'}]},2:{id:2,name:'Global semantic',version:1,colors:[{id:'qp-global-success',name:'Success',value:'#008866'}]}};window.nextPaletteId=3;
        window.fetch=async(url,options={})=>{
            const path=new URL(url,location.href).pathname;if(!path.startsWith('/dashboard/quill/editor-color-palette'))return original(url,options);
            await new Promise(resolve=>setTimeout(resolve,10));const id=Number(path.split('/')[4]),method=options.method||'GET',body=options.body;
            const response=(data,status=200,message='OK')=>new Response(JSON.stringify({data,message}),{status,headers:{'Content-Type':'application/json'}});
            if(method==='GET')return id?globalPaletteRecords[id]?response({palette:globalPaletteRecords[id]}):response({},404,'Palette not found.'):response({palettes:Object.values(globalPaletteRecords)});
            if(id&&(!globalPaletteRecords[id]||Number(body.get('lock_version'))!==globalPaletteRecords[id].version))return response({},409,'Palette changed. Reopen it.');
            if(path.endsWith('/delete')){delete globalPaletteRecords[id];return response({});}
            const definition=JSON.parse(body.get('definition')),key=id||nextPaletteId++,item={id:key,name:definition.name,colors:definition.colors,definition,version:(globalPaletteRecords[key]?.version||0)+1};globalPaletteRecords[key]=item;return response({palette:item},id?200:201);
        };
    })()''')


def exercise(endpoint, origin, screenshot):
    page=browser.Page(endpoint,origin+'/')
    page.wait('!!window.QuillEditor?.palette && !!QuillEditor.canvas.getElement("0.0")')
    mock_library(page);mock_palettes(page)
    page.evaluate('window.originalPaletteSections=JSON.stringify(QuillEditor.getDocument().sections);document.querySelector("#editor-page-menu").click();document.querySelector("[data-page-tab=styling]").click()')
    page.wait('!!document.querySelector("[data-palette-manager-new]") && QuillEditor.palette.globals().length===2')
    page.evaluate('document.querySelector("[data-palette-manager-new]").click();document.querySelector("[data-palette-collection-name]").value="Local brand";document.querySelector(".editor-palette-naming").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))')
    page.wait('QuillEditor.palette.locals().length===1 && document.querySelector(".editor-palette-dialog").getAttribute("aria-busy")==="false"')
    page.evaluate('''window.localBrand=QuillEditor.palette.locals()[0].id;const form=document.querySelector('.editor-palette-form');form.querySelector('[data-palette-name]').value='Primary';form.querySelector('[data-palette-value]').value='#11223344';form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));''')
    page.wait('QuillEditor.palette.locals()[0].colors.length===1 && document.querySelector(".editor-palette-dialog").getAttribute("aria-busy")==="false"')
    page.check('Object.keys(globalPaletteRecords).length===2 && !QuillEditor.palette.defaultPalette() && document.querySelector("[data-palette-make-global]").checkVisibility()', 'New named palettes are local until explicitly made global and offer the promotion action')
    page.evaluate('document.querySelector("[aria-label=\\"Close saved colors\\"]").click();QuillEditor.palette.createLocal("Local semantic",[{id:"qp-semantic",name:"Success",value:"#008866"}]);document.querySelector("#editor-page-settings-close").click();QuillEditor.select("0.0.1");QuillEditor.ui.properties.showCategory("text")')
    page.wait('!!document.querySelector("[data-palette-button=\\"Saved colors: Text color\\"]")')
    page.evaluate('document.querySelector("[data-palette-button=\\"Saved colors: Text color\\"]").click()')
    page.wait('document.querySelectorAll("[data-palette-open]").length===4')
    page.check('!document.querySelector(".editor-palette-index").hidden && document.querySelector(".editor-palette-index").textContent.includes("Local palettes") && document.querySelector(".editor-palette-index").textContent.includes("Global palettes")', 'Without a default, the shared picker displays named local and global palettes first')
    if screenshot:
        Path(screenshot).write_bytes(base64.b64decode(page.command('Page.captureScreenshot',format='png')['data']))
    page.evaluate('window.samePaletteDialog=document.querySelector(".editor-palette-dialog");document.querySelector(`[data-palette-open="local:${localBrand}"]`).click()')
    page.check('document.querySelector(".editor-palette-dialog")===samePaletteDialog && document.querySelector(".editor-palette-index").hidden && document.querySelector(".editor-palette-dialog h2").textContent==="Local brand" && document.querySelector("[data-palette-explore]").checkVisibility() && document.querySelector(".editor-palette-views").getAnimations().length===1', 'Choosing a palette animates its colors into the same popup and exposes Explore')
    page.evaluate('document.querySelector("[data-palette-make-default]").click();document.querySelector("[aria-label=\\"Close saved colors\\"]").click();document.querySelector("[data-palette-button=\\"Saved colors: Text color\\"]").click()')
    page.check('QuillEditor.palette.defaultPalette().id===localBrand && document.querySelector(".editor-palette-index").hidden && document.querySelector(".editor-palette-dialog h2").textContent==="Local brand"', 'Make default is saved on the page and opens that palette directly next time')
    page.evaluate('document.querySelector("[data-palette-explore]").click();document.querySelector("[data-palette-open=\\"global:1\\"]").click();document.querySelector("[data-saved-paint=qp-global-red]").click()')
    page.wait('QuillEditor.getStyleDeclarations().color==="rgb(255, 51, 68)"')
    page.check('!document.querySelector(".editor-palette-dialog").open && globalPaletteRecords[1].colors[0].value==="#ff3344"', 'Exploring another palette applies a copied color without modifying the shared source')
    page.evaluate('document.querySelector("[data-palette-button=\\"Saved colors: Text color\\"]").click();document.querySelector("[data-palette-make-global]").click()')
    page.wait('QuillEditor.palette.defaultPalette()?.scope==="global" && Object.keys(globalPaletteRecords).length===3')
    page.wait('document.querySelector(".editor-palette-dialog").getAttribute("aria-busy")==="false"')
    page.check('QuillEditor.palette.locals().length===1 && QuillEditor.palette.defaultPalette().id===3 && document.querySelector(".editor-palette-dialog h2").textContent==="Local brand"', 'Make global moves the local palette into the shared library and preserves its default status')
    page.evaluate('document.querySelector("[aria-label=\\"Close saved colors\\"]").click();document.querySelector("[data-palette-button=\\"Saved colors: Text color\\"]").click()')
    page.check('document.querySelector(".editor-palette-index").hidden && document.querySelector(".editor-palette-detail").textContent.includes("Primary")', 'A global page default also opens directly')
    page.evaluate('document.querySelector("[aria-label=\\"Edit Primary\\"]").click();document.querySelector("[data-palette-value]").value="#0000ff";document.querySelector(".editor-palette-form").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))')
    page.wait('!!document.querySelector("[data-library-choice=replace]")')
    page.check('document.querySelector(".editor-library-confirm").textContent.includes("Existing element colors stay unchanged") && globalPaletteRecords[3].colors[0].value==="#11223344"', 'Global palette edits require Save as new or Replace before changing shared colors')
    page.evaluate('document.querySelector("[data-library-choice=replace]").click()')
    page.wait('globalPaletteRecords[3].version===2 && document.querySelector(".editor-palette-dialog").getAttribute("aria-busy")==="false"')
    page.check('QuillEditor.getStyleDeclarations().color==="rgb(255, 51, 68)" && globalPaletteRecords[3].colors[0].value==="#0000ff"', 'Replacing a shared palette never recolors existing elements')
    page.evaluate('document.querySelector("[aria-label=\\\"Edit Primary\\\"]").click();document.querySelector("[data-palette-value]").value="#ff00ff";document.querySelector(".editor-palette-form").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))')
    page.wait('!!document.querySelector("[data-library-choice=new]")')
    page.evaluate('document.querySelector("[data-library-choice=cancel]").click()')
    page.wait('document.querySelector(".editor-palette-dialog").getAttribute("aria-busy")==="false"')
    page.check('globalPaletteRecords[3].colors[0].value==="#0000ff"', 'Cancelling a shared palette edit does not mutate its colors')
    page.evaluate('document.querySelector(".editor-palette-form").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))')
    page.wait('!!document.querySelector("[data-library-choice=new]")')
    page.evaluate('document.querySelector("[data-library-choice=new]").click()')
    page.wait('!!globalPaletteRecords[4] && document.querySelector(".editor-palette-dialog").getAttribute("aria-busy")==="false"')
    page.check('globalPaletteRecords[4].colors[0].value==="#ff00ff" && globalPaletteRecords[3].colors[0].value==="#0000ff" && QuillEditor.palette.defaultPalette().id===3', 'Save as new preserves the original global palette and the existing default choice')
    page.evaluate('document.querySelector("[data-palette-explore]").click();document.querySelector("[data-palette-open=\\\"global:3\\\"]").click()')
    page.evaluate('document.querySelector("[data-palette-delete]").click()')
    page.wait('!!document.querySelector("[data-library-choice=delete]")')
    page.check('document.querySelector(".editor-library-confirm").textContent.includes("Are you sure") && !!globalPaletteRecords[3]', 'Deleting a palette requires a confirmation')
    page.evaluate('document.querySelector("[data-library-choice=cancel]").click()')
    page.wait('document.querySelector(".editor-palette-dialog").getAttribute("aria-busy")==="false"')
    page.check('!!globalPaletteRecords[3]', 'Cancelling deletion retains the global palette')
    page.evaluate('document.querySelector("[data-palette-delete]").click()')
    page.wait('!!document.querySelector("[data-library-choice=delete]")')
    page.evaluate('document.querySelector("[data-library-choice=delete]").click()')
    page.wait('!globalPaletteRecords[3] && !QuillEditor.palette.defaultPalette() && document.querySelector(".editor-palette-dialog").getAttribute("aria-busy")==="false"')
    page.check('!document.querySelector(".editor-palette-index").hidden && QuillEditor.getStyleDeclarations().color==="rgb(255, 51, 68)"', 'Removing the default returns to Explore while preserving applied colors')
    page.evaluate("""QuillEditor.palette.makeDefault({scope:'global',id:2});delete globalPaletteRecords[2];QuillEditor.palette.close();QuillEditor.palette.open({current:'#000',apply:()=>{}})""")
    page.wait('QuillEditor.palette.globals().every(palette=>palette.id!==2)')
    page.check('!document.querySelector(".editor-palette-index").hidden && QuillEditor.palette.defaultPalette().id===2', 'A remotely deleted global default falls back to Explore without breaking the picker')
    page.command('Emulation.setEmulatedMedia', features=[{'name':'prefers-reduced-motion','value':'reduce'}])
    page.wait('document.querySelector(".editor-palette-views").getAnimations().length===0')
    page.evaluate("""document.querySelector('[data-palette-open="global:1"]').click()""")
    page.check('document.querySelector(".editor-palette-views").getAnimations().length===0', 'Palette navigation respects reduced-motion preferences')
    page.command('Emulation.setEmulatedMedia', features=[])
    page.evaluate('document.querySelector("[aria-label=\\"Close saved colors\\"]").click();QuillEditor.palette.save("Legacy color","#ffaa00");document.querySelector("[data-palette-button=\\"Saved colors: Text color\\"]").click()')
    page.check('!!document.querySelector("[data-palette-open=\\"local:qpal-legacy\\"]") && QuillEditor.palette.list()[0].value==="#ffaa00"', 'Legacy saved colors remain available as Page colors without rewriting their storage')
    page.evaluate('document.querySelector("[data-palette-open=\\"global:1\\"]").click();document.querySelector("[data-palette-make-default]").click();document.querySelector("[aria-label=\\"Close saved colors\\"]").click();window.paletteSettingsBefore=JSON.stringify(QuillEditor.getDocument().settings);QuillEditor.ui.animations.open()')
    page.wait('!!QuillEditor.ui.animations.adapter')
    page.wait('!!document.querySelector("#studio-properties [data-palette-button=\\"Saved colors: Background color\\"]")')
    page.evaluate('document.querySelector("#studio-properties [data-palette-button=\\"Saved colors: Background color\\"]").click()')
    page.wait('!!document.querySelector(".editor-palette-dialog[open] [data-saved-paint=qp-global-red]")')
    page.check('document.querySelector(".editor-palette-dialog[open] [data-palette-make-default]").disabled && document.querySelector(".editor-palette-dialog[open] [data-palette-make-global]").hidden && document.querySelector(".editor-palette-dialog[open] .editor-palette-form").hidden', 'Animation Studio can browse shared palettes but cannot edit global resources or page defaults')
    page.evaluate('document.querySelector(".editor-palette-dialog[open] [data-saved-paint=qp-global-red]").click()')
    page.check('JSON.stringify(QuillEditor.getDocument().settings)===paletteSettingsBefore && !!QuillEditor.ui.animations.session.definition.tracks["background-color"]', 'Choosing a shared color in Studio records only isolated animation keyframes')
    page.evaluate('QuillEditor.ui.animations.discard()')
    if page.exceptions: raise AssertionError(page.exceptions)


if __name__=='__main__':
    browser.exercise_rich_text=lambda *args:None
    browser.exercise_structured_blocks=lambda *args:None
    browser.exercise=exercise
    browser.main()
