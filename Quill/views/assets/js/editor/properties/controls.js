import { motionControl } from './motion-control.js';
import { optionLabel, propertyHints } from './property-language.js';
import { opacityControl, trackControl } from './visual-controls.js';
import { fontControl } from './font-control.js';
import { colorControl } from './paint-control.js';
import { shadowControl } from './shadow-control.js';
import { constraintsControl, transformControl } from './position-controls.js';
import { dimensionControl } from './dimension-control.js';
import { spacingControl } from './spacing-control.js';
import { segmentedControl } from './segmented-control.js';
import { backgroundImageControl, backgroundPositionControl } from './background-control.js';

const dimensions = /^(?:(?:min-|max-)?(?:width|height)|font-size|line-height|letter-spacing|word-spacing|text-indent|text-underline-offset|text-decoration-thickness|gap|row-gap|column-gap|top|right|bottom|left|flex-basis|border-width)$/;
export function mountGroups({ container, editor, signal }, groups) {
    const controls = [];
    const visibility = [];
    const linkedControls = [];
    let fieldIndex = 0;
    groups = groups.map(([title, fields, ...rest]) => [title, fields.filter(([property]) => !editor.supportsProperty || editor.supportsProperty(property)), ...rest]).filter(([, fields]) => fields.length);
    groups.forEach(([title, fields, condition, description], groupIndex) => {
        const group = document.createElement('details');
        group.className = 'editor-style-group';
        group.open = groupIndex === 0;
        const summary = document.createElement('summary');
        const heading = document.createElement('span'); heading.textContent = title;
        const chevron = document.createElement('i'); chevron.className = 'fa-solid fa-chevron-down'; chevron.setAttribute('aria-hidden', 'true');
        summary.append(heading, chevron); group.append(summary);
        if (description) { const note = document.createElement('p'); note.className = 'editor-group-description'; note.textContent = description; group.append(note); }
        visibility.push({ group, condition });
        fields.forEach(([property, label, options, fieldCondition]) => {
            if (property === 'animate-changes') { const motion=motionControl(editor,signal); group.append(motion.element); linkedControls.push(motion); return; }
            if (property === 'constraints' || property === 'move-transform') {
                const linked = property === 'constraints' ? constraintsControl(editor, signal, container.id) : transformControl(editor, signal, container.id);
                group.append(linked.element); linkedControls.push(linked); return;
            }
            if (['margin', 'padding', 'border-radius'].includes(property)) {
                const linked = spacingControl(property, editor, signal, property === 'border-radius', container.id);
                group.append(linked.element); linkedControls.push(linked); return;
            }
            const row = document.createElement('div'); row.className = 'editor-property-row';
            row.dataset.control = property;
            const caption = document.createElement('label'); caption.textContent = label; if (propertyHints[property]) caption.title = propertyHints[property];
            const apply = value => {
                try { editor.setStyle(property, value.trim()); editor.reportError(null); }
                catch (error) { editor.reportError(new Error(`Check the ${label.toLowerCase()} value.`)); }
            };
            let control, setter;
            const composite = property === 'font-family' && editor.fonts?.catalog?.length ? fontControl(editor, signal)
                : property.includes('color') ? colorControl(property, label, editor, signal)
                : ['box-shadow', 'text-shadow'].includes(property) ? shadowControl(property, editor, signal)
                : property === 'background-image' ? backgroundImageControl(editor, signal)
                : property === 'background-position' ? backgroundPositionControl(editor, signal)
                : property === 'opacity' ? opacityControl(apply, signal)
                : ['grid-template-columns', 'grid-template-rows'].includes(property) ? trackControl(property, label, apply, signal)
                : options ? segmentedControl(property, options, apply, signal, label)
                : dimensions.test(property) ? dimensionControl(property, label, apply, signal, { auto: /width|height|top|right|bottom|left|basis/.test(property) }) : null;
            if (composite) {
                row.append(caption, composite.element); control = composite.input; setter = composite.set;
                if (property.includes('color')) row.classList.add('editor-property-color');
                if (['box-shadow', 'text-shadow'].includes(property)) row.classList.add('editor-property-stacked');
                if (options || ['background-position', 'font-family'].includes(property)) row.classList.add('editor-property-stacked');
            } else {
                control = document.createElement(options ? 'select' : 'input');
                control.dataset.style = property;
                if (options) {
                    control.add(new Option('Default', ''));
                    options.forEach(value => control.add(new Option(optionLabel(property, value), value)));
                } else { control.type = 'text'; control.placeholder = 'Default'; control.autocomplete = 'off'; }
                control.addEventListener('input', () => apply(control.value), { signal });
                if (property.includes('color')) {
                    control.placeholder = ''; control.setAttribute('data-coloris', ''); control.className = 'editor-color-swatch';
                    control.setAttribute('aria-label', label); control.setAttribute('spellcheck', 'false');
                    row.classList.add('editor-property-color');
                }
                row.append(caption, control);
                setter = (value, force, computed) => {
                    if (control.hasAttribute('data-coloris')) {
                        control.style.backgroundColor = value || computed?.getPropertyValue(property) || 'transparent';
                        control.title = `${label}: ${value || computed?.getPropertyValue(property) || 'Default'}`;
                    }
                    if (!force && control === document.activeElement) return;
                    if (options && value && ![...control.options].some(option => option.value === value)) control.add(new Option(optionLabel(property, value), value));
                    control.value = value || (control.hasAttribute('data-coloris') ? computed?.getPropertyValue(property) || '' : '');
                };
            }
            control.id = `${container.id}-${property}-${fieldIndex++}`; caption.htmlFor = control.id;
            const reset = document.createElement('button'); reset.type = 'button'; reset.className = 'editor-property-reset';
            reset.innerHTML = '<i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i>';
            reset.dataset.resetStyle = property;
            reset.setAttribute('aria-label', `Reset ${label.toLowerCase()}`);
            reset.addEventListener('click', () => composite?.reset ? composite.reset() : apply(''), { signal }); row.append(reset);
            controls.push({ row, property, setter, condition: fieldCondition }); group.append(row);
        });
        container.append(group);
    });
    window.QuillEditorColorPicker.setup();
    let currentBlock, currentContext;
    function update(block, context) {
        currentBlock = block; currentContext = context;
        // Read normalized Quill base declarations too; inline declarations retain precedence.
        const style = editor.getStyleDeclarations ? editor.getStyleDeclarations() : document.createElement('div').style;
        if (!editor.getStyleDeclarations) {
            for (const [property, value] of Object.entries(block.styles?.base || {})) style.setProperty(property, value);
            style.cssText += ';' + (block.attributes.style || '');
        }
        const element = editor.canvas.getElement(editor.selectedPath);
        const computed = element ? element.ownerDocument.defaultView.getComputedStyle(element) : style;
        const display = style.display || computed.display;
        const parentDisplay = element?.parentElement ? element.ownerDocument.defaultView.getComputedStyle(element.parentElement).display : '';
        const state = { block, style, display, parentDisplay, computed };
        visibility.forEach(({group, condition}) => { group.hidden = condition ? !condition(state) : false; });
        controls.forEach(({row, property, setter, condition}) => {
            row.hidden = condition ? !condition(state) : false;
            const inheritedTypography = ['font-size', 'font-weight', 'font-family', 'line-height', 'font-style', 'text-decoration-line', 'text-align'].includes(property);
            const assigned = !!style.getPropertyValue(property);
            row.dataset.valueSource = assigned ? (editor.styleScope === 'base' ? 'assigned' : 'override') : 'inherited';
            const reset = row.querySelector('[data-reset-style]');
            reset.title = assigned ? `Reset ${property} in ${editor.styleScope === 'base' ? 'shared styles' : editor.styleScope}` : 'Using inherited or default appearance';
            let value = style.getPropertyValue(property) || (inheritedTypography ? computed.getPropertyValue(property) : '');
            if (property === 'text-align' && !style.getPropertyValue(property) && ['start', 'end'].includes(value)) {
                value = (value === 'start') === (computed.direction !== 'rtl') ? 'left' : 'right';
            }
            setter(value, context.selectionChanged, computed);
        });
        linkedControls.forEach(linked => linked.update(style, context.selectionChanged, computed));
    }
    const off = editor.on('canvasrendered', () => { if (currentBlock) update(currentBlock, { ...currentContext, selectionChanged: false }); });
    signal.addEventListener('abort', off, { once: true });
    return { update, destroy: off };
}
