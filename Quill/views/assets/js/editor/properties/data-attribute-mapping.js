import { htmlAttributes, attributeAllowed } from './html-attributes.js';
import { mountAttributeNamePicker } from './attribute-name-picker.js';

export function mountAttributeMapping({ container, mapping = {}, columns, routes, onChange, onRemove, signal }) {
    const make = (tag, className = '', text = '') => { const node = document.createElement(tag); node.className = className; node.textContent = text; return node; };
    const field = (parent, label, key, options = null) => {
        const row = make('div', 'editor-link-field'), title = make('label', '', label), input = make(options ? 'select' : 'input');
        input.id = `attribute-${crypto.randomUUID()}`; title.htmlFor = input.id; input.setAttribute('aria-label', label); input.dataset[key] = '';
        if (options) fill(input, options); else input.autocomplete = 'off';
        row.append(title, input); parent.append(row); return input;
    };
    const fill = (input, choices, value = '') => {
        input.replaceChildren(...choices.map(([value, name]) => new Option(name, value)));
        if (value && !choices.some(([key]) => key === value)) input.add(new Option(`${value} (unavailable)`, value));
        input.value = value;
    };
    const iconButton = (name, icon, action) => {
        const button = window.QuillEditorIcon.set(make('button', 'editor-action-icon'), icon); button.type = 'button'; button.title = name; button.setAttribute('aria-label', name); button.addEventListener('click', action, { signal }); return button;
    };
    const names = [...new Set([...htmlAttributes, ...Object.keys(mapping.existingAttributes || {}).filter(attributeAllowed)])];
    const header = make('div', 'editor-data-attribute-heading'); container.append(header);
    const choice = field(header, 'Attribute', 'dataAttributeName', [['', 'Choose an attribute…'], ...names.map(name => [name, name]), ['__custom__', 'Custom attribute…']]);
    const custom = field(container, 'Custom attribute', 'dataAttributeCustom'); custom.placeholder = 'data-product-id';
    choice.value = !mapping.name ? '' : names.includes(mapping.name) ? mapping.name : '__custom__'; custom.value = choice.value === '__custom__' ? mapping.name : '';
    mountAttributeNamePicker(choice,{names,tag:mapping.tag,signal});
    header.append(iconButton('Remove attribute mapping', 'xmark', onRemove));
    const hrefArea = make('div', 'editor-data-href'); container.append(hrefArea);
    const routeChoice = field(hrefArea, 'Href destination', 'dataAttributeRoute', []);
    const parameterFields = make('div', 'editor-data-route-parameters'); hrefArea.append(parameterFields);
    const routeNote = make('p', 'editor-group-description', 'Map each route parameter to a field from this data source.'); hrefArea.append(routeNote);
    const composer = make('div', 'editor-data-attribute-composer'); container.append(composer);
    const first = field(composer, 'Value from', 'dataAttributeValue', [['', 'Choose a field…'], ...columns()]); fill(first, [['', 'Choose a field…'], ...columns()], mapping.value || '');
    const extra = make('div', 'editor-data-content-fields'); composer.append(extra);
    function addColumn(value = '') {
        const row = make('div', 'editor-data-content-row');
        const input = field(row, 'Additional field', 'dataAttributeAdditional', [['', 'Choose a field…'], ...columns()]); fill(input, [['', 'Choose a field…'], ...columns()], value);
        row.prepend(make('span', 'editor-data-content-index', String(extra.children.length + 2)));
        row.append(iconButton('Remove attribute field', 'xmark', () => { row.remove(); [...extra.children].forEach((entry, index) => { entry.querySelector('.editor-data-content-index').textContent = String(index + 2); }); onChange(); })); extra.append(row);
    }
    first.parentElement.append(iconButton('Add attribute field', 'plus', () => { addColumn(); extra.lastElementChild.querySelector('select').focus(); }));
    for (const column of mapping.additional || []) addColumn(column);
    const affixes = make('div', 'editor-data-affixes'); composer.append(affixes);
    const before = field(affixes, 'Before', 'dataAttributeBefore'), after = field(affixes, 'After', 'dataAttributeAfter'); before.value = mapping.before || ''; after.value = mapping.after || '';
    const spaceLabel = make('label', 'editor-link-toggle'), spaces = make('input'); spaces.type = 'checkbox'; spaces.dataset.dataAttributeSpaces = ''; spaces.checked = mapping.with_spaces !== false; spaceLabel.append(spaces, make('span', '', 'With spaces')); composer.append(spaceLabel);
    const name = () => choice.value === '__custom__' ? custom.value.trim() : choice.value;
    const parameters = path => [...new Set(path.split('/').filter(part => /^\{[^}]+\}$/.test(part)).map(part => part.slice(1, -1)))];
    function buildParameters(values = {}) {
        parameterFields.replaceChildren();
        for (const key of parameters(routeChoice.value)) {
            const input = field(parameterFields, `{${key}} from`, 'dataAttributeParameter', [['', 'Choose a field…'], ...columns()]); input.dataset.dataAttributeParameter = key;
            fill(input, [['', 'Choose a field…'], ...columns()], values[key] || '');
        }
        routeNote.hidden = !parameterFields.children.length;
    }
    function refreshRoutes() {
        const selected = routeChoice.options.length ? routeChoice.value : mapping.route?.path || '';
        fill(routeChoice, [['', 'From fields / custom URL'], ...routes().map(route => [route.path, route.name && route.name !== route.path ? `${route.name} — ${route.path}` : route.path])], selected);
    }
    function visibility() {
        custom.parentElement.hidden = choice.value !== '__custom__';
        hrefArea.hidden = name().toLowerCase() !== 'href';
        const routed = !hrefArea.hidden && !!routeChoice.value;
        composer.hidden = !name() || routed; parameterFields.hidden = !routed; routeNote.hidden = !routed || !parameterFields.children.length;
    }
    choice.addEventListener('change', visibility, { signal }); custom.addEventListener('input', visibility, { signal });
    routeChoice.addEventListener('change', () => { buildParameters(); visibility(); }, { signal });
    refreshRoutes(); buildParameters(mapping.route?.parameters || {}); visibility();
    return { refreshRoutes, read() {
        const attribute = name();
        if (!attribute) return null;
        if (!attributeAllowed(attribute)) throw new Error('Use a display attribute such as src, alt, href or title.');
        if (attribute.toLowerCase() === 'href' && routeChoice.value) {
            const values = Object.fromEntries([...parameterFields.querySelectorAll('[data-data-attribute-parameter]')].map(input => [input.dataset.dataAttributeParameter, input.value]));
            if (Object.values(values).some(value => !value)) return null;
            return { name: attribute, value: '', route: { path: routeChoice.value, parameters: values } };
        }
        if (!first.value) return null;
        const result = { name: attribute, value: first.value };
        const additional = [...extra.querySelectorAll('[data-data-attribute-additional]')].map(input => input.value).filter(Boolean);
        if (additional.length) result.additional = additional;
        if (before.value) result.before = before.value;
        if (after.value) result.after = after.value;
        if (!spaces.checked) result.with_spaces = false;
        return result;
    } };
}
