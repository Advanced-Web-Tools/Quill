import { dimensionControl } from './dimension-control.js';

const sides = ['top', 'right', 'bottom', 'left'];
// Split transform components without splitting the spaces inside calc()/var().
function components(value) {
    const result = []; let depth = 0, start = 0;
    for (let index = 0; index <= value.length; index++) {
        const character = value[index];
        if (character === '(') depth++;
        if (character === ')') depth--;
        if (index === value.length || (/\s/.test(character) && !depth)) {
            if (index > start) result.push(value.slice(start, index));
            start = index + 1;
        }
    }
    return result;
}
function translation(style) {
    if ((style.translate || '').includes('--quill-')) return {
        axes: ['x', 'y'].map(axis => ({
            centered: style.getPropertyValue(`--quill-center-${axis}`).trim() === '-50%',
            move: style.getPropertyValue(`--quill-move-${axis}`).trim() || '0px',
        })),
        z: components(style.translate)[2],
    };
    const values = components(style.translate === 'none' ? '' : style.translate || '');
    const axes = [0, 1].map(index => {
        const value = values[index] || '0px';
        const centered = value === '-50%' || /^calc\(-50%\s*\+/.test(value);
        return { centered, move: centered ? value === '-50%' ? '0px' : value.replace(/^calc\(-50%\s*\+\s*/, '').slice(0, -1) : value };
    });
    return { axes, z: values[2] };
}
function writeTranslation(style, value) {
    value.axes.forEach((axis, index) => {
        const name = index ? 'y' : 'x';
        style.setProperty(`--quill-center-${name}`, axis.centered ? '-50%' : '0%');
        style.setProperty(`--quill-move-${name}`, axis.move);
    });
    style.translate = ['x', 'y'].map(axis => `calc(var(--quill-center-${axis}) + var(--quill-move-${axis}))`).join(' ') + (value.z ? ` ${value.z}` : '');
}
function environment(editor) {
    const style = editor.getStyleDeclarations();
    const element = editor.canvas.getElement(editor.selectedPath);
    const computed = element ? element.ownerDocument.defaultView.getComputedStyle(element) : style;
    // Preserve the authored expressions across viewport/state scopes. Computed translate
    // resolves calc()/var(), which would lose the distinction between centering and Move.
    const authored = document.createElement('div').style, block = editor.getSelectedBlock();
    const width = element?.ownerDocument.defaultView.innerWidth || 1440;
    const applies = viewport => viewport === 'base' || (viewport === 'desktop' ? width > editor.breakpoints.tablet : width <= editor.breakpoints[viewport]);
    const states = ['hover', 'focus', 'focus-visible', 'active', 'disabled', 'checked'];
    const groups = ['base', 'desktop', 'tablet', 'mobile', ...states, ...['desktop', 'tablet', 'mobile'].flatMap(viewport => states.map(state => `${viewport}:${state}`))];
    for (const group of groups) {
        const [viewport, state] = group.includes(':') ? group.split(':') : states.includes(group) ? ['base', group] : [group, null];
        if (!applies(viewport) || (state && editor.styleState !== state && !element?.matches(`:${state}`))) continue;
        for (const [property, value] of Object.entries(block.styles?.[group] || {})) authored.cssText += `${property}:${value};`;
    }
    authored.cssText += ';' + (block.attributes.style || '');
    const effective = new Proxy(style, { get(target, key) {
        if (key === 'getPropertyValue') return name => target.getPropertyValue(name) || authored.getPropertyValue(name) || computed.getPropertyValue(name);
        if (typeof key !== 'string') return Reflect.get(target, key);
        return target[key] || (['translate', 'transform', 'transformOrigin'].includes(key) ? authored[key] : '') || computed[key];
    } });
    return { style, computed, effective, element };
}
function edit(editor, callback) {
    try {
        const context = environment(editor);
        callback(context);
        editor.setStyleDeclarations(context.style.cssText);
        editor.reportError(null);
    } catch (error) { editor.reportError(error); }
}
function button(label, icon, signal, action) {
    const element = document.createElement('button'); element.type = 'button';
    element.title = label; element.setAttribute('aria-label', label);
    element.innerHTML = `<i class="fa-solid fa-${icon}" aria-hidden="true"></i>`;
    element.addEventListener('click', action, { signal });
    return element;
}
function authoredInset(side, style, element, computed) {
    return style.getPropertyValue(side) || element?.computedStyleMap?.().get(side)?.toString() || computed.getPropertyValue(side) || 'auto';
}

export function constraintsControl(editor, signal, prefix) {
    const element = document.createElement('fieldset'); element.className = 'editor-constraints editor-spacing';
    const legend = document.createElement('legend'); legend.textContent = 'Pin to edges';
    const diagram = document.createElement('div'); diagram.className = 'editor-constraints-diagram';
    const preview = document.createElement('div'); preview.className = 'editor-constraints-preview'; preview.setAttribute('aria-hidden', 'true');
    diagram.append(preview);
    const fields = new Map(), pins = new Map();
    const uncenter = (style, effective, side) => {
        const value = translation(effective), axis = ['left', 'right'].includes(side) ? 0 : 1;
        if (!value.axes[axis].centered) return;
        value.axes[axis].centered = false; writeTranslation(style, value);
        const start = axis === 0 ? 'left' : 'top';
        if (side !== start) style.setProperty(start, 'auto');
    };
    for (const side of sides) {
        const pin = button(`Pin ${side}`, { top: 'arrow-up', right: 'arrow-right', bottom: 'arrow-down', left: 'arrow-left' }[side], signal, () => edit(editor, ({style, effective, element, computed}) => {
            const centered = translation(effective).axes[['left', 'right'].includes(side) ? 0 : 1].centered;
            const active = authoredInset(side, style, element, computed) !== 'auto' && !centered;
            uncenter(style, effective, side);
            style.setProperty(side, active ? 'auto' : '0px');
        }));
        pin.dataset.constraint = side; pin.className = `editor-constraint-pin editor-constraint-${side}`; pins.set(side, pin); diagram.append(pin);
        const field = document.createElement('div'); field.className = 'editor-property-row'; field.dataset.control = side;
        const caption = document.createElement('label'); caption.textContent = side[0].toUpperCase() + side.slice(1);
        const control = dimensionControl(side, caption.textContent, value => edit(editor, ({style, effective}) => {
            if (value && !CSS.supports(side, value)) throw new Error(`Invalid ${side}: ${value}`);
            uncenter(style, effective, side);
            if (value) style.setProperty(side, value); else style.removeProperty(side);
        }), signal, { auto: true });
        control.input.id = `${prefix}-constraint-${side}`; caption.htmlFor = control.input.id;
        field.append(caption, control.element); fields.set(side, { field, control });
    }
    const all = button('Pin all edges', 'arrows-up-down-left-right', signal, () => edit(editor, ({style, effective}) => {
        const value = translation(effective); value.axes.forEach(axis => { axis.centered = false; }); writeTranslation(style, value);
        sides.forEach(side => style.setProperty(side, '0px'));
        style.width = 'auto'; style.height = 'auto';
    }));
    all.className = 'editor-constraint-all'; all.dataset.constraint = 'all'; diagram.append(all);
    const centers = document.createElement('div'); centers.className = 'editor-constraint-centers';
    const centerButtons = [0, 1].map(axis => {
        const control = button(`Center ${axis === 0 ? 'horizontally' : 'vertically'}`, axis === 0 ? 'arrows-left-right-to-line' : 'arrows-up-down', signal, () => edit(editor, ({style, effective}) => {
            const value = translation(effective); value.axes[axis].centered = !value.axes[axis].centered;
            style.setProperty(axis === 0 ? 'left' : 'top', value.axes[axis].centered ? '50%' : 'auto');
            style.setProperty(axis === 0 ? 'right' : 'bottom', 'auto');
            writeTranslation(style, value);
        }));
        const label = document.createElement('span'); label.textContent = axis === 0 ? 'Horizontal' : 'Vertical'; control.append(label);
        control.dataset.centerAxis = axis === 0 ? 'x' : 'y'; centers.append(control); return control;
    });
    const hint = document.createElement('p'); hint.className = 'editor-viewport-note';
    const reset = button('Reset pinning', 'arrow-rotate-left', signal, () => edit(editor, ({style, effective}) => {
        sides.forEach(side => style.removeProperty(side));
        const value = translation(effective); value.axes.forEach(axis => { axis.centered = false; }); writeTranslation(style, value);
    })); reset.className = 'editor-property-reset';
    element.append(legend, diagram, centers, hint, ...[...fields.values()].map(value => value.field), reset);
    return { element, update(style, force, computed) {
        const node = editor.canvas.getElement(editor.selectedPath);
        const position = computed.position || style.position;
        const pinned = ['absolute', 'fixed'].includes(position);
        element.hidden = !position || position === 'static';
        diagram.hidden = centers.hidden = !pinned;
        hint.textContent = position === 'fixed' ? 'Pinned to the window. The cross fills all edges and sets size to Auto.'
            : position === 'absolute' ? 'Pinned to the nearest positioned parent. The cross fills all edges and sets size to Auto.'
            : position === 'sticky' ? 'Offsets set where the block sticks while scrolling.' : 'Offsets move the block from its normal position.';
        const { effective } = environment(editor), value = translation(effective);
        for (const [side, {field, control}] of fields) {
            field.hidden = element.hidden;
            const inset = authoredInset(side, style, node, computed);
            const centered = value.axes[['left', 'right'].includes(side) ? 0 : 1].centered;
            pins.get(side).setAttribute('aria-pressed', String(inset !== 'auto' && !centered));
            control.set(style.getPropertyValue(side), force);
        }
        centerButtons.forEach((control, axis) => control.setAttribute('aria-pressed', String(value.axes[axis].centered)));
        all.setAttribute('aria-pressed', String(sides.every(side => pins.get(side).getAttribute('aria-pressed') === 'true')));
    } };
}

export function transformControl(editor, signal, prefix) {
    const element = document.createElement('div'); element.className = 'editor-transform-controls';
    const hint = document.createElement('p'); hint.className = 'editor-viewport-note';
    hint.textContent = 'Changes the block’s appearance without moving surrounding elements.';
    element.append(hint);
    const fields = new Map();
    function field(key, label, unit, apply, parent = element) {
        const row = document.createElement('div'); row.className = 'editor-property-row';
        const caption = document.createElement('label'); caption.textContent = label;
        const input = document.createElement('input'); input.type = 'text'; input.inputMode = 'decimal'; input.placeholder = unit || 'Default';
        input.id = `${prefix}-${key}`; input.dataset.transform = key; caption.htmlFor = input.id;
        input.addEventListener('input', () => edit(editor, context => apply(input.value.trim(), context)), { signal });
        row.append(caption, input); parent.append(row); fields.set(key, input);
    }
    for (const [axis, key] of ['x', 'y'].entries()) field(`move-${key}`, key === 'x' ? 'Move across' : 'Move down', '0px', (raw, {style, effective}) => {
        const move = raw ? /^-?(?:\d*\.)?\d+$/.test(raw) ? raw + 'px' : raw : '0px';
        if (!CSS.supports('translate', `calc(0px + ${move}) 0px`)) throw new Error('Enter a distance, for example 12px or 10%.');
        const value = translation(effective); value.axes[axis].move = move; writeTranslation(style, value);
    });
    const rotation = document.createElement('div'); rotation.className = 'editor-rotation-preview'; rotation.setAttribute('aria-hidden', 'true');
    const needle = document.createElement('span'); rotation.append(needle); element.append(rotation);
    field('rotate', 'Rotate (°)', '0°', (raw, {style}) => {
        const value = raw ? /^-?(?:\d*\.)?\d+$/.test(raw) ? raw + 'deg' : raw : '';
        if (value && !CSS.supports('rotate', value)) throw new Error('Enter an angle, for example 45deg.');
        if (value) style.rotate = value; else style.removeProperty('rotate');
    });
    let linked = true;
    const link = button('Link scale X and Y', 'link', signal, () => { linked = !linked; link.setAttribute('aria-pressed', String(linked)); });
    link.className = 'editor-transform-link'; link.setAttribute('aria-pressed', 'true'); element.append(link);
    for (const [axis, key] of ['x', 'y'].entries()) field(`scale-${key}`, key === 'x' ? 'Visual width (%)' : 'Visual height (%)', '100', (raw, {style, effective}) => {
        const values = effective.scale === 'none' ? ['1', '1'] : components(effective.scale || '1');
        if (raw === '') { style.removeProperty('scale'); return; }
        if (!Number.isFinite(Number(raw))) throw new Error('Enter a scale percentage.');
        const value = String(Number(raw) / 100);
        values[1] ||= values[0];
        if (linked) { values[0] = value; values[1] = value; } else values[axis] = value;
        style.scale = values.join(' ');
    });
    const origin = document.createElement('fieldset'); origin.className = 'editor-transform-origin';
    const legend = document.createElement('legend'); legend.textContent = 'Rotate around';
    const grid = document.createElement('div'); grid.className = 'editor-origin-grid'; grid.setAttribute('role', 'group'); grid.setAttribute('aria-label', 'Rotation center');
    const origins = new Map();
    for (const [y, vertical] of ['top', 'center', 'bottom'].entries()) for (const [x, horizontal] of ['left', 'center', 'right'].entries()) {
        const value = `${x * 50}% ${y * 50}%`;
        const choice = button(`Rotate around: ${vertical} ${horizontal}`, 'circle', signal, () => edit(editor, ({style, effective}) => {
            const z = components(effective.transformOrigin || '')[2];
            style.transformOrigin = value + (z ? ` ${z}` : '');
        })); choice.dataset.transformOrigin = value; origins.set(value, choice); grid.append(choice);
    }
    origin.append(legend, grid); element.append(origin);
    const advanced = document.createElement('details'); advanced.className = 'editor-transform-advanced';
    const summary = document.createElement('summary'); summary.textContent = 'More options: slant'; advanced.append(summary); element.append(advanced);
    for (const key of ['X', 'Y']) field(`skew-${key.toLowerCase()}`, key === 'X' ? 'Slant across (°)' : 'Slant down (°)', '0°', (raw, {style, effective}) => {
        const angle = raw ? /^-?(?:\d*\.)?\d+$/.test(raw) ? raw + 'deg' : raw : '0deg';
        if (!CSS.supports('transform', `skew${key}(${angle})`)) throw new Error('Enter an angle, for example 10deg.');
        // Preserve authored transform functions while changing only this skew component.
        const transform = effective.transform === 'none' ? '' : effective.transform || '';
        const pattern = new RegExp(`skew${key}\\([^)]*\\)`, 'g');
        style.transform = `${transform.replace(pattern, '').trim()} skew${key}(${angle})`.trim();
    }, advanced);
    const reset = button('Reset adjustments', 'arrow-rotate-left', signal, () => edit(editor, ({style, effective}) => {
        const value = translation(effective); value.axes.forEach(axis => { axis.move = '0px'; }); writeTranslation(style, value);
        for (const property of ['rotate', 'scale', 'transform-origin', 'transform']) style.removeProperty(property);
    })); reset.className = 'editor-transform-reset'; reset.append(' Reset adjustments'); element.append(reset);
    const display = (key, value, force) => { const input = fields.get(key); if (force || input !== document.activeElement) input.value = value; };
    return { element, update(style, force) {
        if (force) { linked = true; link.setAttribute('aria-pressed', 'true'); }
        const { effective, element: node, computed } = environment(editor), value = translation(effective);
        hint.textContent = computed.display === 'inline' ? 'Choose Inline item in Layout to move, rotate or resize this item.'
            : 'Changes the block’s appearance without moving surrounding elements.';
        value.axes.forEach((axis, index) => display(`move-${index ? 'y' : 'x'}`, axis.move === '0px' ? '' : axis.move, force));
        const rotate = effective.rotate === 'none' ? '' : effective.rotate || '';
        display('rotate', rotate.replace(/deg$/, ''), force); needle.style.rotate = rotate || '0deg';
        const scales = components(effective.scale === 'none' ? '1' : effective.scale || '1');
        for (const [index, key] of ['x', 'y'].entries()) display(`scale-${key}`, String(parseFloat(scales[index] || scales[0]) * ((scales[index] || scales[0]).endsWith('%') ? 1 : 100)), force);
        let originValue = style.transformOrigin;
        if (!originValue && node?.computedStyleMap) originValue = node.computedStyleMap().get('transform-origin')?.toString();
        const tokens = components(originValue || '50% 50%');
        const normalized = tokens.slice(0, 2).map(token => ({ left: '0%', top: '0%', center: '50%', right: '100%', bottom: '100%' })[token] || token).join(' ');
        origins.forEach((control, choice) => control.setAttribute('aria-pressed', String(choice === normalized)));
        for (const key of ['X', 'Y']) {
            const match = (style.transform || '').match(new RegExp(`skew${key}\\(([^)]*)\\)`));
            display(`skew-${key.toLowerCase()}`, match ? match[1].replace(/deg$/, '') : '', force);
        }
    } };
}
