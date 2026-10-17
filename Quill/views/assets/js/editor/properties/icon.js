import { mountGroups } from './controls.js';

const catalogUrl = 'https://cdn.jsdelivr.net/gh/FortAwesome/Font-Awesome@6.7.2/metadata/icons.json';
let catalog;
function loadCatalog() {
    return catalog ||= fetch(catalogUrl).then(response => {
        if (!response.ok) throw new Error('The icon catalog could not be loaded. Try again.');
        return response.json();
    }).then(data => Object.entries(data).flatMap(([name, icon]) => (icon.free || []).filter(style => ['solid', 'regular', 'brands'].includes(style)).map(style => ({
        name, style, label: icon.label || name, search: [name, icon.label, ...(icon.search?.terms || [])].join(' ').toLowerCase(),
    })))).catch(error => { catalog = null; throw error; });
}
export default function({ container, editor, signal }) {
    const choose = document.createElement('button'); choose.type = 'button'; choose.className = 'editor-icon-choose';
    const preview = document.createElement('i'); preview.setAttribute('aria-hidden', 'true');
    const caption = document.createElement('span'); caption.textContent = 'Choose icon'; choose.append(preview, caption); container.append(choose);
    const label = document.createElement('label'); label.textContent = 'Accessible label';
    const accessible = document.createElement('input'); accessible.type = 'text'; accessible.placeholder = 'Empty for decorative icons'; label.append(accessible); container.append(label);
    accessible.addEventListener('input', () => {
        try {
            const attributes = { ...editor.getSelectedBlock().attributes };
            if (accessible.value.trim()) { attributes['aria-label'] = accessible.value.trim(); attributes.role = 'img'; delete attributes['aria-hidden']; }
            else { attributes['aria-hidden'] = 'true'; delete attributes['aria-label']; delete attributes.role; }
            editor.updateBlock({ attributes }); editor.reportError(null);
        } catch (error) { editor.reportError(error); }
    }, { signal });
    const dialog = document.createElement('dialog'); dialog.className = 'editor-icon-dialog';
    const heading = document.createElement('h2'); heading.textContent = 'Choose a Font Awesome icon';
    heading.id = `${container.id}-icon-heading`; dialog.setAttribute('aria-labelledby', heading.id);
    const close = document.createElement('button'); close.type = 'button'; close.className = 'editor-image-close'; close.setAttribute('aria-label', 'Close icon picker'); window.QuillEditorIcon.set(close, 'xmark');
    const search = document.createElement('input'); search.type = 'search'; search.placeholder = 'Search icons…'; search.setAttribute('aria-label', 'Search icons'); search.dataset.iconSearch = '';
    const family = document.createElement('select'); family.setAttribute('aria-label', 'Icon style');
    [['', 'All styles'], ['solid', 'Solid'], ['regular', 'Regular'], ['brands', 'Brands']].forEach(([value, text]) => family.add(new Option(text, value)));
    const filters = document.createElement('div'); filters.className = 'editor-icon-filters'; filters.append(search, family);
    const status = document.createElement('p'); status.setAttribute('role', 'status');
    const grid = document.createElement('div'); grid.className = 'editor-icon-grid';
    const more = document.createElement('button'); more.type = 'button'; more.textContent = 'Load more';
    const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Retry'; retry.hidden = true;
    dialog.append(heading, close, filters, status, retry, grid, more); document.body.append(dialog);
    let icons = [], limit = 120, openPath = null, request = 0;
    const render = () => {
        const query = search.value.trim().toLowerCase();
        const matches = icons.filter(icon => (!family.value || icon.style === family.value) && query.split(/\s+/).every(term => icon.search.includes(term)));
        status.textContent = matches.length ? `${Math.min(limit, matches.length)} of ${matches.length} icons` : 'No icons found.';
        const block = editor.getSelectedBlock(), classes = block?.attributes.class || '';
        const fragment = document.createDocumentFragment();
        for (const icon of matches.slice(0, limit)) {
            const choice = document.createElement('button'); choice.type = 'button'; choice.dataset.iconChoice = `${icon.style}:${icon.name}`;
            choice.title = `${icon.label} (${icon.style})`; choice.setAttribute('aria-label', choice.title);
            choice.setAttribute('aria-pressed', String(classes.split(/\s+/).includes(`fa-${icon.name}`) && classes.split(/\s+/).includes(`fa-${icon.style}`)));
            const glyph = document.createElement('i'); glyph.className = `fa-${icon.style} fa-${icon.name}`; glyph.setAttribute('aria-hidden', 'true');
            const name = document.createElement('span'); name.textContent = icon.label; choice.append(glyph, name);
            choice.addEventListener('click', () => {
                if (editor.selectedPath !== openPath || editor.isReadOnly()) { dialog.close(); return; }
                try {
                    const attributes = { ...editor.getSelectedBlock().attributes };
                    attributes.class = [...String(attributes.class || '').split(/\s+/).filter(value => value && !/^(?:fa(?:s|r|b)?|fa-[a-z0-9-]+)$/.test(value)), `fa-${icon.style}`, `fa-${icon.name}`].join(' ');
                    editor.updateBlock({ attributes }); editor.reportError(null); dialog.close();
                } catch (error) { editor.reportError(error); }
            });
            fragment.append(choice);
        }
        grid.replaceChildren(fragment); more.hidden = limit >= matches.length;
    };
    const load = async () => {
        const ticket = ++request; status.textContent = 'Loading icons…'; retry.hidden = more.hidden = true;
        try { const loaded = await loadCatalog(); if (ticket !== request || signal.aborted || !dialog.open) return; icons = loaded; render(); }
        catch (error) { if (ticket === request && !signal.aborted && dialog.open) { status.textContent = error.message; retry.hidden = false; } }
    };
    choose.addEventListener('click', () => { openPath = editor.selectedPath; limit = 120; dialog.showModal(); search.focus(); load(); }, { signal });
    close.addEventListener('click', () => dialog.close(), { signal }); retry.addEventListener('click', load, { signal });
    for (const control of [search, family]) control.addEventListener(control === search ? 'input' : 'change', () => { limit = 120; render(); }, { signal });
    more.addEventListener('click', () => { limit += 120; render(); }, { signal });
    dialog.addEventListener('close', () => { request++; }, { signal });
    signal.addEventListener('abort', () => { request++; dialog.close(); dialog.remove(); }, { once: true });
    const settings = document.createElement('div'); container.append(settings);
    const controls = mountGroups({ container: settings, editor, signal }, [['Icon appearance', [['font-size', 'Size'], ['color', 'Color']]]]);
    return { update(block, context) {
        if (dialog.open && context.selectionChanged) dialog.close();
        preview.className = String(block.attributes.class || 'fa-solid fa-star');
        caption.textContent = String(block.attributes.class || '').split(/\s+/).filter(value => value.startsWith('fa-')).join(' ');
        if (context.selectionChanged || accessible !== document.activeElement) accessible.value = block.attributes['aria-label'] || '';
        controls.update(block, context);
    }, destroy() { controls.destroy(); dialog.close(); dialog.remove(); } };
}
