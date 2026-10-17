(() => {
    const cardMenus = [...document.querySelectorAll('.quill-card-menu')];
    for (const menu of cardMenus) menu.addEventListener('toggle', () => {
        if (menu.open) for (const other of cardMenus) if (other !== menu) other.open = false;
    });
    document.addEventListener('click', event => {
        for (const menu of cardMenus) if (!menu.contains(event.target) || event.target.closest('.quill-card-menu-items :is(a, button)')) menu.open = false;
    });
    document.addEventListener('focusin', event => {
        for (const menu of cardMenus) if (!menu.contains(event.target)) menu.open = false;
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const menu = cardMenus.find(menu => menu.open);
        if (!menu) return;
        event.preventDefault(); menu.open = false; menu.querySelector('summary').focus();
    });
    const search=document.querySelector('[data-quill-search]'),filter=document.querySelector('[data-quill-filter]');
    const items=[...document.querySelectorAll('[data-quill-item]')];
    const applyFilter=()=>{
        const query=search?.value.trim().toLocaleLowerCase()||'',status=filter?.value||'';
        let count=0;for(const item of items){item.hidden=!(item.dataset.search.toLocaleLowerCase().includes(query)&&(!status||item.dataset.status===status));if(!item.hidden)count++;}
        const empty=document.querySelector('[data-quill-no-results]');if(empty)empty.hidden=!!count;
        document.querySelectorAll('[data-quill-resource-section]').forEach(section=>{
            section.hidden=![...section.querySelectorAll('[data-quill-item]')].some(item=>!item.hidden);
        });
    };
    search?.addEventListener('input',applyFilter);filter?.addEventListener('change',applyFilter);
    document.querySelectorAll('[data-quill-reset-filters]').forEach(button=>button.addEventListener('click',()=>{
        if(search)search.value='';if(filter)filter.value='';applyFilter();search?.focus();
    }));
    const confirmation=document.getElementById('quill-command-confirm');
    let pending=null,busy=false,opener=null;
    document.querySelectorAll('[data-quill-dismiss]').forEach(button=>button.addEventListener('click',()=>{if(!busy)button.closest('dialog').close();}));
    for(const dialog of document.querySelectorAll('#quill-command-confirm,#quill-template-dialog')){
        dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
        dialog.addEventListener('close',()=>{if(opener?.isConnected)opener.focus();});
    }
    const send=async form=>{
        if(busy||!form.reportValidity())return;
        const feedback=form.querySelector('[data-quill-feedback]');const payload=new FormData(form);
        const controls=[...form.querySelectorAll('input,select,textarea,button'),...confirmation.querySelectorAll('button')];const disabled=controls.map(control=>control.disabled);
        busy=true;form.setAttribute('aria-busy','true');controls.forEach(control=>control.disabled=true);if(feedback)feedback.textContent='Working…';
        try {
            const response=await fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});
            if(response.redirected)throw new Error('Please sign in again, then reload this page.');
            const result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(result.message||'Unable to complete this request. Please try again.');
            if(feedback)feedback.textContent=result.message||'Saved.';
            if(form.hasAttribute('data-quill-open-editor')&&/^\/quill\/editor\/page\/[1-9][0-9]*$/.test(result.data?.editorUrl||''))window.location.assign(result.data.editorUrl);
            else window.location.reload();
        }catch(error){
            if(feedback)feedback.textContent=error.message;
            if(confirmation.open){confirmation.close();feedback?.scrollIntoView({block:'nearest'});}
        }finally{busy=false;form.removeAttribute('aria-busy');controls.forEach((control,index)=>control.disabled=disabled[index]);}
    };
    document.querySelectorAll('form[data-quill-command]').forEach(form=>form.addEventListener('submit',event=>{
        event.preventDefault();if(busy||!form.reportValidity())return;
        opener=event.submitter||document.activeElement;
        if(form.dataset.confirmTitle){
            pending=form;document.getElementById('quill-command-title').textContent=form.dataset.confirmTitle;document.getElementById('quill-command-description').textContent=form.dataset.confirmMessage;
            const button=confirmation.querySelector('[data-quill-command-go]');button.classList.toggle('btn-danger',form.hasAttribute('data-confirm-danger'));button.classList.toggle('btn-primary',!form.hasAttribute('data-confirm-danger'));
            button.textContent=form.hasAttribute('data-confirm-danger')?'Delete version':'Restore as draft';confirmation.showModal();confirmation.querySelector('[data-quill-dismiss]').focus();
        }else send(form);
    }));
    confirmation?.querySelector('[data-quill-command-go]').addEventListener('click',()=>{if(pending)send(pending);});
    const templateDialog=document.getElementById('quill-template-dialog');
    document.querySelectorAll('[data-quill-template]').forEach(button=>button.addEventListener('click',()=>{
        if(busy)return;const page=JSON.parse(button.dataset.quillTemplate);const form=templateDialog.querySelector('form');opener=button.closest('.quill-card-menu')?.querySelector('summary') || button;
        form.action=`/dashboard/quill/page/${page.id}/template`;form.elements.namedItem('name').value=page.name;form.elements.namedItem('description').value=page.description||'';form.elements.namedItem('lock_version').value=page.lock_version;
        form.querySelector('[data-quill-feedback]').textContent='';templateDialog.showModal();form.elements.namedItem('name').focus();
    }));
})();
