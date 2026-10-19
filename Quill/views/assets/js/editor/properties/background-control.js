import { commitPaint, setBackgroundPaint } from './paint-control.js';
import { dimensionControl } from './dimension-control.js';

export function backgroundImageControl(editor, signal) {
    const element = document.createElement('div'); element.className = 'editor-background-image';
    const button = document.createElement('button'); button.type = 'button'; button.className = 'button-primary editor-select-image';
    button.innerHTML = '<span>Select image</span><i class="fa-solid fa-image" aria-hidden="true"></i>';
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'editor-icon-button';
    remove.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i>'; remove.setAttribute('aria-label', 'Remove background image');
    const dialog = document.createElement('dialog'); dialog.className = 'editor-image-dialog';
    const title = document.createElement('h2'); title.textContent = 'Background image';
    const label = document.createElement('label'); label.textContent = 'Image URL';
    const input = document.createElement('input'); input.type = 'url'; input.placeholder = 'https://example.com/image.jpg';
    input.dataset.style = 'background-image'; label.append(input);
    const hint = document.createElement('p'); hint.textContent = 'Use an image URL or a path from your site. Changes appear immediately.';
    const close = document.createElement('button'); close.type = 'button'; close.className = 'editor-image-close'; close.setAttribute('aria-label', 'Close image picker');
    close.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
    const urlPanel = document.createElement('div'); urlPanel.className = 'editor-media-url'; urlPanel.append(label, hint);
    dialog.append(title, close); document.body.append(dialog);
    const library = editor.mediaLibrary || new window.QuillEditorMediaLibrary(editor);
    const picker = library.mount({ container: dialog, type: 'image', mode: 'tabs', urlPanel, signal, lazy: true,
        onSelect: item => { input.value = item.url; apply(`url(${JSON.stringify(item.url)})`); } });
    button.addEventListener('click', () => { picker.resume(); dialog.showModal(); (dialog.querySelector('[role=tab][aria-selected=true]') || input).focus(); }, { signal });
    dialog.addEventListener('close', () => picker.suspend(), { signal });
    close.addEventListener('click', () => dialog.close(), { signal });
    const apply = value => {
        commitPaint(editor, (style, computed) => setBackgroundPaint(style, value, computed));
    };
    remove.addEventListener('click', () => { input.value = ''; apply(''); }, { signal });
    input.addEventListener('input', () => {
        const value = input.value.trim();
        if (!value) { apply(''); return; }
        try {
            const url = new URL(value, document.baseURI);
            if (!['http:', 'https:'].includes(url.protocol)) throw new Error('Choose an HTTP image URL or a path from your site.');
            apply(`url(${JSON.stringify(value)})`);
        } catch (error) { editor.reportError(error); }
    }, { signal });
    signal.addEventListener('abort', () => { dialog.close(); dialog.remove(); }, { once: true });
    element.append(button, remove);
    return { element, input, set(value, force) {
        if (force && dialog.open) dialog.close();
        const match = String(value).match(/^url\(["']?(.*?)["']?\)$/);
        if (force || document.activeElement !== input) input.value = match ? match[1] : '';
        picker.update(match ? match[1] : '', force);
        remove.hidden = !value || value === 'none';
        button.querySelector('span').textContent = remove.hidden ? 'Select image' : 'Change image';
    } };
}

export function backgroundPositionControl(editor, signal) {
    const element = document.createElement('div'); element.className = 'editor-background-position';
    const choices = document.createElement('div'); choices.className = 'editor-segmented'; choices.setAttribute('role', 'group'); choices.setAttribute('aria-label', 'Background position');
    const manual = document.createElement('div'); manual.className = 'editor-background-offsets';
    const input = document.createElement('input'); input.type = 'text'; input.hidden = true; input.dataset.style = 'background-position';
    let manualMode = false;
    const controls = new Map();
    const buttons = new Map();
    function apply(value) {
        try { editor.setStyle('background-position', value); editor.reportError(null); }
        catch (error) { editor.reportError(error); }
    }
    for (const [value, title, name] of [['top','Top','arrow-up'],['left','Left','arrow-left'],['center','Center','bullseye'],['right','Right','arrow-right'],['bottom','Bottom','arrow-down'],['manual','Manual','pen']]) {
        const button = document.createElement('button'); button.type = 'button'; button.dataset.backgroundPosition = value;
        const caption = document.createElement('span'); caption.textContent = title;
        const icon = document.createElement('i'); icon.className = `fa-solid fa-${name}`; icon.setAttribute('aria-hidden', 'true');
        button.append(caption, icon);
        button.addEventListener('click', () => {
            manualMode = value === 'manual'; manual.hidden = !manualMode;
            if (!manualMode) apply(value);
            else { for (const [choice, control] of buttons) control.setAttribute('aria-pressed', String(choice === 'manual')); }
        }, { signal });
        buttons.set(value, button); choices.append(button);
    }
    for (const [axis, title] of [['x','Horizontal'], ['y','Vertical']]) {
        const field = document.createElement('label'); field.textContent = title;
        const control = dimensionControl(`background-position-${axis}`, `Background ${title.toLowerCase()} position`, value => {
            try { editor.setStyle(`background-position-${axis}`, value); editor.reportError(null); }
            catch (error) { editor.reportError(error); }
        }, signal, { slider: false });
        controls.set(axis, control); field.append(control.element); manual.append(field);
    }
    input.addEventListener('input', () => apply(input.value), { signal });
    manual.hidden = true; element.append(choices, manual, input);
    return { element, input, set(value, force) {
        input.value = value;
        const normalized = { 'center top': 'top', 'center bottom': 'bottom', 'left center': 'left', 'right center': 'right', 'center center': 'center', '50% 0%': 'top', '0% 50%': 'left', '50% 50%': 'center', '100% 50%': 'right', '50% 100%': 'bottom' }[value] || value;
        const preset = buttons.has(normalized) ? normalized : null;
        if (force) manualMode = !!value && !preset;
        manual.hidden = !manualMode;
        for (const [choice, button] of buttons) button.setAttribute('aria-pressed', String(choice === (manualMode ? 'manual' : preset)));
        const style = document.createElement('div').style; style.backgroundPosition = value;
        controls.get('x').set(({left: '0%', center: '50%', right: '100%'})[style.backgroundPositionX] || style.backgroundPositionX || '', force);
        controls.get('y').set(({top: '0%', center: '50%', bottom: '100%'})[style.backgroundPositionY] || style.backgroundPositionY || '', force);
    } };
}
