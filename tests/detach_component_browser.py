"""Turn a shared instance into editable local blocks without writing its library source."""
import editor_browser as browser


def exercise(endpoint,origin,screenshot):
 p=browser.Page(endpoint,origin+'/')
 p.wait('!!window.QuillEditor?.ui.components')
 p.evaluate("""window.makeLocal=(name,content='',children=[],editor={})=>({descriptor:{name,props:{},renderer:''},attributes:{},content,children,_quill:{editor}});
 window.sourceDocument={schema_version:1,sections:[{name:'main',blocks:[makeLocal('section','',[makeLocal('button','Source button',[],{onClick:'qa-source'}),makeLocal('p','Source target',[],{actionTargetId:'qt-source'})])]}],settings:{actions:[]},assets:{styles:['shared.css'],scripts:['shared.js']}};
 window.prepared=structuredClone(sourceDocument);prepared.sections[0].blocks[0].children[0]._quill.editor.onClick='qa-detached';prepared.sections[0].blocks[0].children[1]._quill.editor.actionTargetId='qt-detached';
 prepared.sections[0].blocks[0]._quill.editor.styleBreakpoints={tablet:960,mobile:520};prepared.sections[0].blocks[0]._quill.styles={base:{color:'#123456'},mobile:{padding:'9px'}};
 prepared.settings.actions=[{id:'qa-detached',name:'Local action',trigger:'click',steps:[{id:'qs-detached',target:{type:'element',value:'qt-detached'},condition:null,operation:{type:'show'}}]}];
 window.componentSourceBefore=JSON.stringify(sourceDocument);window.detachRequests=[];window.delayDetach=false;
 window.fetch=async(url,options={})=>{detachRequests.push({url:String(url),method:options.method||'GET'});if(String(url).includes('/editor-animation'))return new Response(JSON.stringify({data:{animations:[]}}),{status:200});if(String(url).includes('/block?')){if(delayDetach)await new Promise(resolve=>window.finishDetach=resolve);return new Response(JSON.stringify({data:{document:prepared,targetMap:{'qt-source':'qt-detached'},component:{id:14,version:2}}}),{status:200});}return new Response(JSON.stringify({data:{document:sourceDocument,component:{id:14,name:'Reusable card',version:2}}}),{status:200});};
 QuillEditor.state.insertComponent({id:14,name:'Reusable card',version:2},sourceDocument,0,null,1);
 QuillEditor.state.insertComponent({id:14,name:'Reusable card',version:2},sourceDocument,0,null,2);QuillEditor.select('0.1');
 QuillEditor.state.setAnimationBindings('0.1',[{animation_id:99,trigger:'load'}]);
 window.beforeDetach=JSON.stringify(QuillEditor.getDocument());window.detachRevision=QuillEditor.state.revision;
 document.querySelector('#editor-detach-component').click();""")
 p.wait("!QuillEditor.isReadOnly() && QuillEditor.getBlock('0.1').descriptor.name==='section'")
 p.check("QuillEditor.state.revision===detachRevision+1 && !document.querySelector('#editor-properties').hidden && document.querySelector('#editor-detach-component').hidden",'Turn into block is one undoable edit and restores normal property controls')
 p.check("!QuillEditor.getBlock('0.1').component && QuillEditor.getBlock('0.2').component.id===14",'Only the selected shared instance is converted')
 p.check("QuillEditor.getBlock('0.1').animations[0].animation_id===99",'Instance animation references remain on the resulting local block')
 p.check("QuillEditor.getDocument().settings.actions[0].id==='qa-detached' && QuillEditor.getBlock('0.1.0').editor.onClick==='qa-detached' && QuillEditor.getBlock('0.1.1').editor.actionTargetId==='qt-detached'",'Copied actions and their targets stay connected to editable local elements')
 p.check("QuillEditor.getDocument().assets.styles.includes('shared.css') && QuillEditor.getDocument().assets.scripts.includes('shared.js')",'Shared style and script dependencies become local page dependencies')
 p.check("JSON.stringify(sourceDocument)===componentSourceBefore && detachRequests.every(request=>request.method==='GET'&&!request.url.includes('/delete'))",'Conversion only reads the library and never edits or deletes its source')
 p.evaluate("QuillEditor.select('0.1.1');QuillEditor.setContent('Only local');")
 p.check("QuillEditor.getBlock('0.2').children[0].children[1].content==='Source target' && JSON.stringify(sourceDocument)===componentSourceBefore",'Local edits do not change another instance or the source')
 p.evaluate("QuillEditor.ui.history.undo();QuillEditor.ui.history.undo()")
 p.wait("!!QuillEditor.getBlock('0.1').component")
 p.check("JSON.stringify(QuillEditor.getDocument())===beforeDetach && !document.querySelector('#editor-detach-component').hidden",'Undo restores the original component link, animations and document settings')
 p.evaluate("QuillEditor.ui.history.redo()")
 p.wait("!QuillEditor.getBlock('0.1').component")
 p.check("QuillEditor.getBlock('0.1').descriptor.name==='section' && QuillEditor.getBlock('0.1').editor.styleBreakpoints.mobile===520",'Redo restores the editable copy and its original responsive thresholds')
 # Pending preparation must never overwrite a concurrent edit.
 p.evaluate("QuillEditor.select('0.2');window.delayDetach=true;document.querySelector('#editor-detach-component').click()")
 p.wait('!!window.finishDetach')
 p.evaluate("QuillEditor.setContent('Concurrent footer','1.0');window.finishDetach()")
 p.wait("!document.querySelector('#editor-detach-component').disabled")
 p.check("!!QuillEditor.getBlock('0.2').component && QuillEditor.getBlock('1.0').content==='Concurrent footer' && document.querySelector('#editor-property-error').textContent.includes('selection changed')",'Stale asynchronous preparation cannot overwrite concurrent page edits')
 # Multiple roots splice into the same parent without a new layout wrapper.
 p.evaluate("""window.delayDetach=false;window.prepared={schema_version:1,sections:[{name:'main',blocks:[makeLocal('h2','First local root'),makeLocal('p','Second local root')]}],settings:{actions:[]},assets:{styles:[],scripts:[]}};QuillEditor.select('0.2');document.querySelector('#editor-detach-component').click();""")
 p.wait("QuillEditor.getBlock('0.2').descriptor.name==='h2'")
 p.check("QuillEditor.getBlock('0.3').content==='Second local root' && QuillEditor.getDocument().sections[0].blocks.length===4",'Multi-root components become adjacent editable blocks without changing layout structure')
 if p.exceptions:raise AssertionError(p.exceptions)


if __name__=='__main__':
 browser.exercise_rich_text=lambda *a:None
 browser.exercise_rich_text_line_breaks=lambda *a:None
 browser.exercise_structured_blocks=lambda *a:None
 browser.exercise=exercise
 browser.main()
