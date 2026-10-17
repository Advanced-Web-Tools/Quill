const units = ['px', '%', 'em', 'rem', 'vw', 'vh', 'svh', 'dvh', 'ch', ''];
export function dimensionControl(property, label, onChange, signal, { auto = false, slider = true } = {}) {
    const element = document.createElement('div');
    element.className = 'editor-dimension';
    const joined = document.createElement('div');
    joined.className = 'editor-unit-input';
    const input = document.createElement('input');
    input.type = 'text';
    input.inputMode = 'decimal';
    input.placeholder = 'Default';
    input.dataset.style = property;
    input.setAttribute('aria-label', label);
    const unit = document.createElement('select');
    unit.setAttribute('aria-label', `${label} unit`);
    [...units, ...(auto ? ['auto'] : [])].forEach(value => unit.add(new Option(value === 'auto' ? 'Automatic' : value || '—', value)));
    const range = document.createElement('input');
    range.type = 'range'; range.min = property.startsWith('margin') || ['top', 'left', 'right', 'bottom', 'letter-spacing', 'text-indent'].includes(property) ? '-200' : '0';
    range.max = '300'; range.step = '0.1'; range.value = '0';
    range.setAttribute('aria-label', `${label} slider`);
    range.hidden = !slider;
    const value = () => {
        if (unit.value === 'auto') return 'auto';
        const raw = input.value.trim();
        return raw && /^-?(?:\d+\.?\d*|\.\d+)$/.test(raw) ? raw + unit.value : raw;
    };
    const sync = () => { input.hidden = unit.value === 'auto'; range.hidden = !slider || input.hidden; };
    input.addEventListener('input', () => { range.value = parseFloat(input.value) || 0; onChange(value()); }, { signal });
    unit.addEventListener('change', () => { sync(); onChange(value()); }, { signal });
    range.addEventListener('input', () => { input.value = range.value; onChange(value()); }, { signal });
    joined.append(input, unit); element.append(joined, range);
    return { element, input, set(value, force = false) {
        if (!force && element.contains(document.activeElement)) return;
        const match = String(value).match(/^(-?(?:\d+\.?\d*|\.\d+))([a-z%]*)$/i);
        input.value = match ? match[1] : value === 'auto' ? '' : value;
        unit.value = value === 'auto' && auto ? 'auto' : match && units.includes(match[2]) ? match[2] : 'px';
        range.value = match ? match[1] : '0'; sync();
    } };
}
