import { optionLabel } from './property-language.js';
const presentations = {
    'text-align': { left: ['Left', 'align-left'], center: ['Center', 'align-center'], right: ['Right', 'align-right'] },
    'font-style': { normal: ['Upright', 'font'], italic: ['Italic', 'italic'], oblique: ['Oblique', 'italic'] },
    'text-decoration-line': { none: ['None', 'font'], underline: ['Underline', 'underline'], overline: ['Line above', 'arrow-up'], 'line-through': ['Strike', 'strikethrough'] },
    'background-size': { auto: ['Original', 'expand'], cover: ['Fill', 'arrows-up-down-left-right'], contain: ['Fit', 'compress'] },
};

export function segmentedControl(property, options, onChange, signal, title) {
    const presentation = presentations[property];
    if (!presentation) return null;
    const element = document.createElement('div');
    element.className = 'editor-segmented';
    element.setAttribute('role', 'group');
    const control = document.createElement('select');
    control.hidden = true;
    control.dataset.style = property;
    control.add(new Option('Default', ''));
    options.forEach(value => control.add(new Option(optionLabel(property, value), value)));
    const buttons = new Map();
    for (const value of options) {
        if (!presentation[value]) continue;
        const [label, name] = presentation[value];
        const button = document.createElement('button');
        button.type = 'button'; button.dataset.styleChoice = property; button.dataset.value = value;
        button.title = label; button.setAttribute('aria-label', `${title}: ${label}`);
        const icon = document.createElement('i'); icon.className = `fa-solid fa-${name}`; icon.setAttribute('aria-hidden', 'true');
        const caption = document.createElement('span'); caption.textContent = label;
        button.append(caption, icon);
        button.addEventListener('click', () => {
            control.value = value; control.dispatchEvent(new Event('input', { bubbles: true }));
        }, { signal });
        buttons.set(value, button); element.append(button);
    }
    // Less common CSS values remain available without crowding the primary choices.
    const extra = options.filter(value => !presentation[value]);
    let extraControl = null;
    if (extra.length) {
        const more = document.createElement('select');
        more.className = 'editor-segment-more'; more.setAttribute('aria-label', `More ${title.toLowerCase()} options`);
        more.add(new Option('More', ''));
        extra.forEach(value => more.add(new Option(optionLabel(property, value), value)));
        more.addEventListener('change', () => {
            if (!more.value) return;
            control.value = more.value; control.dispatchEvent(new Event('input', { bubbles: true })); more.value = '';
        }, { signal });
        extraControl = more; element.append(more);
    }
    control.addEventListener('input', () => { onChange(control.value); update(control.value); }, { signal });
    function update(value) {
        control.value = value;
        if (extraControl) extraControl.value = extra.includes(value) ? value : '';
        for (const [choice, button] of buttons) button.setAttribute('aria-pressed', String(choice === value));
    }
    element.append(control);
    return { element, input: control, set: update };
}
