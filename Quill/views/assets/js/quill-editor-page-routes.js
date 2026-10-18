(() => {
    'use strict';
    class QuillEditorPageRoutes {
        constructor(editor, bootstrap) {
            const get = id => document.getElementById(id);
            if (bootstrap.externalSourceKind === 'component' || bootstrap.editorType !== 'page' || !Number.isSafeInteger(bootstrap.editorId)) return;
            get('editor-page-routes').hidden = false;
            const pageId = bootstrap.editorId, routes = structuredClone(bootstrap.pageRoutes || []);
            const dialog = get('editor-route-dialog'), form = get('editor-route-form');
            const select = get('editor-page-route-select'), create = get('editor-page-route-new');
            const status = get('editor-page-route-status'), feedback = get('editor-route-status');
            let busy = false;
            const builder = new window.QuillRouteBuilder({ scope: dialog, list: get('editor-route-parts'), template: get('editor-route-part-template'),
                preview: get('editor-route-preview'), orderStatus: get('editor-route-order-status'), isBusy: () => busy });
            const render = () => {
                const list = get('editor-page-route-list'); list.replaceChildren(); select.replaceChildren(new Option('Choose a route…', ''));
                for (const route of [...routes].sort((a,b)=>a.path.localeCompare(b.path))) {
                    if (route.page_id === pageId) {
                        const item = document.createElement('code'); item.className = 'editor-page-route'; item.textContent = route.path; list.append(item);
                    } else select.add(new Option(route.path + (route.page_id ? ` · ${route.page_name || 'Another page'}` : ' · Not connected'), String(route.id)));
                }
                if (!list.childNodes.length) { const hint = document.createElement('p'); hint.className = 'editor-group-description'; hint.textContent = 'No address connected yet.'; list.append(hint); }
                select.disabled = busy || select.options.length === 1; create.disabled = busy;
            };
            const synchronize = route => {
                const index = routes.findIndex(item=>item.id===route.id);
                if (index < 0) routes.push(route); else routes[index] = route;
                editor.routes = Object.freeze([...editor.routes.filter(item=>item.path!==route.path), Object.freeze({path:route.path,name:route.path,parameters:window.QuillRouteBuilder.segments(route.path).filter(item=>item.type==='param').map(item=>item.value)})]);
                editor.dataRoutes = Object.freeze(routes.filter(item=>item.page_id===pageId).map(item=>Object.freeze({path:item.path,parameters:window.QuillRouteBuilder.segments(item.path).filter(part=>part.type==='param').map(part=>part.value)})));
                editor.dispatchEvent(new Event('routeschange')); render();
            };
            const request = async (url, segments, id = null) => {
                const body = new FormData(get('editor-csrf')); body.set('page_id', String(pageId));
                if (id !== null) body.set('id', String(id));
                segments.forEach((segment,index)=>{body.set(`segments[${index}][type]`,segment.type);body.set(`segments[${index}][value]`,segment.value);});
                const response = await fetch(url,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body});
                const result = await window.QuillEditorResponse.read(response, 'Unable to save the route. Try again.');
                if (!Number.isSafeInteger(result.data?.id) || result.data.id < 1) throw new Error('The server did not confirm the route. Reopen the editor before trying again.');
                if (id !== null && result.data.id !== id) throw new Error('The route confirmation does not match the selected address.');
                return result.data.id;
            };
            const setBusy = value => {
                busy = value; form.setAttribute('aria-busy',String(value));
                dialog.querySelectorAll('button,input').forEach(control=>control.disabled=value);
                if (!value) builder.update(); render();
            };
            create.addEventListener('click',()=>{
                if (busy) return; editor.state.assertAvailable(); builder.clear(); builder.add('part',false);
                feedback.textContent = ''; get('editor-route-page-name').textContent = editor.getDocument().settings?.title || bootstrap.name;
                dialog.showModal(); get('editor-route-parts').querySelector('[data-route-value]').focus();
            });
            dialog.querySelectorAll('[data-close-route]').forEach(button=>button.addEventListener('click',()=>{if(!busy)dialog.close();}));
            dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
            dialog.addEventListener('close',()=>{if(!dialog.open)create.focus();});
            form.addEventListener('submit',async event=>{
                event.preventDefault(); if (busy || !form.reportValidity()) return;
                editor.state.assertAvailable(); const segments = builder.segments(); setBusy(true); feedback.textContent='Creating route…';
                try {
                    const id = await request(form.action,segments);
                    const path = '/' + segments.map(item=>item.type==='param'?`{${item.value}}`:item.value).join('/');
                    synchronize({id,path,page_id:pageId,page_name:editor.getDocument().settings?.title || bootstrap.name});
                    dialog.close(); status.textContent = `Connected ${path} to this page.`;
                } catch(error) { feedback.textContent=error.message; }
                finally { setBusy(false); }
            });
            select.addEventListener('change',async()=>{
                if(busy || !select.value)return;
                const route = routes.find(item=>String(item.id)===select.value); select.value=''; if(!route)return;
                editor.state.assertAvailable(); setBusy(true); status.textContent='';
                try {
                    if (route.page_id && await window.QuillLibraryDialog.ask('Move this route?', `${route.path} currently opens ${route.page_name || 'another page'}. Connect it to this page instead?`, [['cancel','Cancel'],['connect','Connect route','button-primary']])!=='connect') return;
                    await request('/dashboard/quill/update/route',window.QuillRouteBuilder.segments(route.path),route.id);
                    synchronize({...route,page_id:pageId,page_name:editor.getDocument().settings?.title || bootstrap.name}); status.textContent=`Connected ${route.path} to this page.`;
                } catch(error) { status.textContent=error.message; }
                finally { setBusy(false); }
            });
            render();
        }
    }
    window.QuillEditorPageRoutes = QuillEditorPageRoutes;
})();
