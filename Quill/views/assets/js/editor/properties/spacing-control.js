import { dimensionControl } from './dimension-control.js';
export function spacingControl(name, editor, signal, corners = false, idPrefix = 'editor') {
    const element = document.createElement('fieldset');
    element.className = corners ? 'editor-spacing editor-corners' : 'editor-spacing';
    const title = corners ? 'Corners' : name === 'margin' ? 'Space around' : 'Space inside';
    const legend = document.createElement('legend'); legend.textContent = title;
    const grid = document.createElement('div'); grid.className = 'editor-spacing-grid';
    let locked = true;
    const lock = document.createElement('button'); lock.type = 'button'; lock.className = 'editor-spacing-lock';
    const updateLock = () => {
        lock.innerHTML = `<i class="fa-solid fa-${locked ? 'lock' : 'lock-open'}" aria-hidden="true"></i>`;
        lock.setAttribute('aria-pressed', String(locked)); lock.setAttribute('aria-label', `Link ${title.toLowerCase()} sides`);
    };
    updateLock();
    lock.addEventListener('click', () => { locked = !locked; updateLock(); }, { signal });
    const controls = new Map();
    const fields = new Map();
    const cornerButtons = new Map();
    let activeCorner = 'top-left';
    const preview = corners ? document.createElement('div') : null;
    if (preview) {
        preview.className = 'editor-radius-preview';
        preview.setAttribute('aria-hidden', 'true');
        grid.append(preview);
    }
    function selectCorner(side) {
        activeCorner = side;
        for (const [corner, field] of fields) field.hidden = corner !== side;
        for (const [corner, button] of cornerButtons) button.setAttribute('aria-pressed', String(corner === side));
    }
    const sides = corners ? ['top-left', 'top-right', 'bottom-right', 'bottom-left'] : ['top', 'left', 'bottom', 'right'];
    const propertyFor = side => corners ? `border-${side}-radius` : `${name}-${side}`;
    for (const side of sides) {
        const control = dimensionControl(propertyFor(side), corners ? `${side.replace('-', ' ')} radius` : `${name} ${side}`, value => {
            try {
                const block = editor.getSelectedBlock();
                const style = editor.getStyleDeclarations ? editor.getStyleDeclarations() : document.createElement('div').style;
                if (!editor.getStyleDeclarations) style.cssText = block.attributes.style || '';
                if (value && !CSS.supports(propertyFor(side), value)) throw new Error(`Invalid ${name}: ${value}`);
                for (const destination of locked ? sides : [side]) {
                    if (value) style.setProperty(propertyFor(destination), value); else style.removeProperty(propertyFor(destination));
                }
                if (editor.setStyleDeclarations) editor.setStyleDeclarations(style.cssText); else editor.setAttribute('style', style.cssText || null);
                editor.reportError(null);
            } catch (error) { editor.reportError(error); }
        }, signal, { auto: name === 'margin', slider: corners });
        controls.set(side, control);
        const field = document.createElement('div'); field.className = `editor-spacing-field editor-spacing-${side}`;
        const label = document.createElement('label'); label.textContent = side.split('-').map(part => part[0].toUpperCase() + part.slice(1)).join(' ');
        control.input.id = `${idPrefix}-${name}-${side}`; label.htmlFor = control.input.id;
        field.append(label, control.element); grid.append(field);
        if (corners) {
            fields.set(side, field);
            const button = document.createElement('button');
            button.type = 'button'; button.className = `editor-radius-corner editor-radius-${side}`;
            button.dataset.radiusCorner = side;
            button.setAttribute('aria-label', `Edit ${side.replace('-', ' ')} radius`);
            button.setAttribute('aria-controls', control.input.id);
            const curve = document.createElement('span'); curve.className = 'editor-radius-curve';
            curve.setAttribute('aria-hidden', 'true'); button.append(curve);
            button.addEventListener('click', () => selectCorner(side), { signal });
            cornerButtons.set(side, button); grid.append(button);
        }
    }
    const reset = document.createElement('button'); reset.type = 'button'; reset.className = 'editor-property-reset';
    reset.setAttribute('aria-label', `Reset ${title.toLowerCase()}`);
    reset.innerHTML = '<i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i>';
    reset.addEventListener('click', () => {
        const style = editor.getStyleDeclarations ? editor.getStyleDeclarations() : document.createElement('div').style;
        if (!editor.getStyleDeclarations) style.cssText = editor.getSelectedBlock().attributes.style || '';
        style.removeProperty(corners ? 'border-radius' : name);
        for (const side of sides) style.removeProperty(propertyFor(side));
        if (editor.setStyleDeclarations) editor.setStyleDeclarations(style.cssText); else editor.setAttribute('style', style.cssText || null);
    }, { signal });
    if (corners) selectCorner(activeCorner);
    grid.append(lock); element.append(legend, grid, reset);
    return { element, update(style, selectionChanged, computed) {
        if (selectionChanged) { locked = true; updateLock(); }
        if (corners && selectionChanged) selectCorner('top-left');
        for (const [side, control] of controls) {
            const property = propertyFor(side);
            control.set(style.getPropertyValue(property), selectionChanged);
            if (corners) {
                const value = style.getPropertyValue(property) || computed?.getPropertyValue(property) || '0px';
                preview.style.setProperty(property, value);
                cornerButtons.get(side).firstElementChild.style.setProperty(property, value);
                cornerButtons.get(side).title = `${side.replace('-', ' ')}: ${value}`;
            }
        }
    } };
}
