(() => {
    'use strict';
    // Shared URL segment editor for dashboard and canvas editor route dialogs.
    class QuillRouteBuilder {
        constructor({ scope, list, template, preview, orderStatus, isBusy = () => false }) {
            let nextId = 0;
            const rows = () => [...list.children];
            function updateParts() {
                const parts = rows();
                parts.forEach((row, index) => {
                    const input = row.querySelector('[data-route-value]');
                    const type = row.querySelector('[data-route-type]');
                    type.name = `segments[${index}][type]`;
                    input.name = `segments[${index}][value]`;
                    const label = type.value === 'param' ? 'Parameter name' : 'Part';
                    row.querySelector('label').textContent = `${label} ${index + 1}`;
                    row.querySelector('[data-route-move="up"]').disabled = index === 0;
                    row.querySelector('[data-route-move="down"]').disabled = index === parts.length - 1;
                    const name = input.value.trim() || label.toLowerCase();
                    row.querySelector('[data-route-move="up"]').ariaLabel = `Move ${name} up`;
                    row.querySelector('[data-route-move="down"]').ariaLabel = `Move ${name} down`;
                    row.querySelector('[data-route-remove]').ariaLabel = `Remove ${name}`;
                });
                scope.querySelectorAll('[data-route-add]').forEach(button => {
                    button.disabled = parts.length >= 64;
                });
                preview.textContent = '/' + parts.map(row => {
                    const value = row.querySelector('[data-route-value]').value.trim();
                    return row.querySelector('[data-route-type]').value === 'param'
                        ? `{${value || 'name'}}` : (value || 'part');
                }).join('/');
            }

            function addPart(type, focus = true, value = '') {
                if (rows().length >= 64) return;
                const row = template.content.firstElementChild.cloneNode(true);
                const input = row.querySelector('[data-route-value]');
                row.querySelector('[data-route-type]').value = type;
                input.id = `quill-route-value-${++nextId}`;
                input.value = value;
                input.pattern = type === 'param' ? '[A-Za-z_][A-Za-z0-9_]*' : '[A-Za-z0-9._~\\-]+';
                input.placeholder = type === 'param' ? 'id' : 'products';
                input.title = type === 'param'
                    ? 'Start with a letter or underscore. Use letters, numbers, and underscores.'
                    : 'Enter one URL part without slashes.';
                row.querySelector('label').htmlFor = input.id;
                list.append(row);
                updateParts();
                if (focus) input.focus();
            }

            if (list) {
                scope.querySelectorAll('[data-route-add]').forEach(button => {
                    button.addEventListener('click', () => {
                        if (!isBusy()) addPart(button.dataset.routeAdd);
                    });
                });
                list.addEventListener('input', updateParts);
                list.addEventListener('click', event => {
                    const button = event.target.closest('button');
                    if (!button || isBusy()) return;
                    const row = button.closest('li');
                    const input = row.querySelector('[data-route-value]');
                    if (button.hasAttribute('data-route-remove')) {
                        const neighbour = row.nextElementSibling || row.previousElementSibling;
                        row.remove();
                        updateParts();
                        (neighbour?.querySelector('[data-route-value]') || scope.querySelector('[data-route-add]')).focus();
                        orderStatus.textContent = 'Route part removed.';
                        return;
                    }
                    if (button.dataset.routeMove === 'up' && row.previousElementSibling) {
                        list.insertBefore(row, row.previousElementSibling);
                    } else if (button.dataset.routeMove === 'down' && row.nextElementSibling) {
                        list.insertBefore(row.nextElementSibling, row);
                    }
                    updateParts();
                    // Keep focus on the moved entry even when its arrow becomes disabled.
                    input.focus();
                    orderStatus.textContent = `Moved to position ${rows().indexOf(row) + 1}.`;
                });
            }

            this.add = addPart;
            this.update = updateParts;
            this.clear = () => { list.replaceChildren(); orderStatus.textContent = ''; updateParts(); };
            this.segments = () => rows().map(row => ({ type: row.querySelector('[data-route-type]').value, value: row.querySelector('[data-route-value]').value.trim() }));
        }
        static segments(path) {
            return path === '/' ? [] : path.replace(/^\//, '').split('/').map(value => {
                const parameter = /^\{([^{}]+)\}$/.exec(value);
                return { type: parameter ? 'param' : 'part', value: parameter ? parameter[1] : value };
            });
        }
    }
    window.QuillRouteBuilder = QuillRouteBuilder;
})();
