(() => {
    'use strict';
    const triggers = { click: 'On click', dblclick: 'On double click', mouseenter: 'On mouse enter', mouseleave: 'On mouse leave', focus: 'On focus', blur: 'On blur', change: 'On value change', submit: 'On form submit', keydown: 'On key press' };
    const bindings = { click: 'onClick', dblclick: 'onDoubleClick', mouseenter: 'onMouseEnter', mouseleave: 'onMouseLeave', focus: 'onFocus', blur: 'onBlur', change: 'onChange', submit: 'onSubmit', keydown: 'onKeyDown' };
    const attribute = trigger => trigger === 'click' ? 'data-quill-action' : 'data-quill-on-' + trigger;
    const keys = { '': 'Any key', Enter: 'Enter', ' ': 'Space', Escape: 'Escape', Tab: 'Tab', ArrowUp: 'Arrow up', ArrowDown: 'Arrow down', ArrowLeft: 'Arrow left', ArrowRight: 'Arrow right' };
    const hiddenStyles = new WeakMap();
    const operations = ['set-style', 'reset-style', 'add-class', 'remove-class', 'toggle-class', 'show', 'hide', 'toggle-visibility', 'remove-element'];
    const id = value => typeof value === 'string' && /^[a-zA-Z][a-zA-Z0-9_-]{0,99}$/.test(value);
    const className = value => typeof value === 'string' && !!value.trim() && !/[\s\x00-\x1f\x7f]/.test(value);
    const motionCurves = { smooth: 'cubic-bezier(.2,.8,.2,1)', gentle: 'ease-in-out', spring: 'cubic-bezier(.34,1.56,.64,1)', linear: 'linear' };
    function validateAnimation(animation, nested = false) {
        if (!animation || typeof animation !== 'object' || Array.isArray(animation)
            || Object.keys(animation).some(key => !(nested ? ['effect','duration','delay','easing','direction','distance'] : ['effect','duration','delay','easing','direction','distance','exit']).includes(key))
            || !['none','fade','slide','scale'].includes(animation.effect)) throw new Error('Choose a supported animation.');
        for (const [key, max] of [['duration',10000],['delay',10000],['distance',500]]) {
            if (animation[key] !== undefined && (typeof animation[key] !== 'number' || !Number.isFinite(animation[key]) || animation[key] < 0 || animation[key] > max)) throw new Error(`Choose an animation ${key} between 0 and ${max}.`);
        }
        if (animation.easing !== undefined && !Object.hasOwn(motionCurves,animation.easing)) throw new Error('Choose a supported motion style.');
        if (animation.direction !== undefined && !['up','down','left','right'].includes(animation.direction)) throw new Error('Choose a slide direction.');
        if (animation.exit !== undefined) validateAnimation(animation.exit, true);
    }
    function validate(actions, doc = document) {
        if (!Array.isArray(actions) || actions.length > 200) throw new Error('Use at most 200 actions.');
        const ids = new Set();
        for (const action of actions) {
            if (!id(action.id) || ids.has(action.id) || typeof action.name !== 'string' || !action.name.trim() || action.name.length > 255 || !Object.hasOwn(triggers, action.trigger)) throw new Error('Enter a valid, unique action and name.');
            if (action.key !== undefined && (action.trigger !== 'keydown' || !Object.hasOwn(keys, action.key))) throw new Error('Choose a supported key.');
            ids.add(action.id);
            if (!Array.isArray(action.steps) || !action.steps.length || action.steps.length > 50) throw new Error('Add between 1 and 50 steps.');
            const steps = new Set();
            for (const step of action.steps) {
                if (!id(step.id) || steps.has(step.id)) throw new Error('Invalid action step.');
                steps.add(step.id);
                const target = step.target || {};
                if (!['self', 'parent', 'element', 'selector'].includes(target.type)) throw new Error('Choose a target.');
                if (target.type === 'element' && !id(target.value)) throw new Error('Choose a target element.');
                if (target.type === 'selector') {
                    if (typeof target.value !== 'string' || !target.value.trim() || target.value.length > 1024) throw new Error('Enter a CSS selector.');
                    try { doc.querySelector(target.value); } catch { throw new Error('Enter a valid CSS selector.'); }
                }
                const condition = step.condition;
                if (condition) {
                    if (!['has-class', 'missing-class', 'visible', 'hidden', 'viewport'].includes(condition.type)) throw new Error('Choose a condition.');
                    if (['has-class', 'missing-class'].includes(condition.type) && !className(condition.value)) throw new Error('Enter one class name for the condition.');
                    if (condition.type === 'viewport' && !['desktop', 'tablet', 'mobile'].includes(condition.value)) throw new Error('Choose a condition viewport.');
                }
                const operation = step.operation || {};
                if (!operations.includes(operation.type)) throw new Error('Choose an operation.');
                if (operation.animation !== undefined) {
                    if (!['show','hide','toggle-visibility'].includes(operation.type)) throw new Error('Animations are available for showing and hiding elements.');
                    validateAnimation(operation.animation);
                }
                if (['set-style', 'reset-style'].includes(operation.type)) {
                    if (!/^(?:--[A-Za-z_][A-Za-z0-9_-]*|-?[A-Za-z][A-Za-z0-9-]*)$/.test(operation.property || '')) throw new Error('Enter a CSS property, for example background-color.');
                    if (operation.type === 'set-style' && (typeof operation.value !== 'string' || !operation.value.trim() || /[{};<>\x00-\x1f\x7f]|\/\*|\*\//.test(operation.value) || !CSS.supports(operation.property, operation.value))) throw new Error('Enter a valid CSS style value.');
                }
                if (['add-class', 'remove-class', 'toggle-class'].includes(operation.type) && !className(operation.value)) throw new Error('Enter one CSS class name.');
            }
        }
        return actions;
    }
    function mount(root, config) {
        const doc = root.nodeType === 9 ? root : root.ownerDocument;
        const actions = new Map(validate(config.actions || [], doc).map(action => [action.id, action]));
        const motions = new Map();
        const breakpoints = { tablet: 1024, mobile: 767, ...config.breakpoints };
        const visible = element => !element.hidden && doc.defaultView.getComputedStyle(element).display !== 'none' && doc.defaultView.getComputedStyle(element).visibility !== 'hidden' && !!element.getClientRects().length;
        const matches = (condition, target) => {
            if (!condition) return true;
            if (condition.type === 'has-class') return target.classList.contains(condition.value);
            if (condition.type === 'missing-class') return !target.classList.contains(condition.value);
            if (condition.type === 'visible') return visible(target);
            if (condition.type === 'hidden') return !visible(target);
            const width = doc.defaultView.innerWidth;
            return condition.value === (width <= breakpoints.mobile ? 'mobile' : width <= breakpoints.tablet ? 'tablet' : 'desktop');
        };
        const targets = (target, clicked) => {
            if (target.type === 'self') return [clicked];
            if (target.type === 'parent') return clicked.parentElement ? [clicked.parentElement] : [];
            return [...root.querySelectorAll(target.type === 'element' ? `[data-quill-target="${target.value}"]` : target.value)];
        };
        const hide = target => {
            if (!hiddenStyles.has(target)) hiddenStyles.set(target, [target.style.getPropertyValue('display'), target.style.getPropertyPriority('display')]);
            target.hidden = true; target.style.setProperty('display', 'none', 'important');
        };
        const show = target => {
            target.hidden = false;
            const previous = hiddenStyles.get(target); hiddenStyles.delete(target);
            if (previous?.[0]) target.style.setProperty('display', ...previous);
            else if (previous || target.style.display === 'none') target.style.removeProperty('display');
            if (doc.defaultView.getComputedStyle(target).display === 'none') target.style.setProperty('display', 'revert', 'important');
            if (['hidden', 'collapse'].includes(doc.defaultView.getComputedStyle(target).visibility)) target.style.setProperty('visibility', 'visible', 'important');
        };
        const cancelMotion = target => {
            const motion = motions.get(target);
            if (motion) { motions.delete(target); motion.animation.cancel(); }
        };
        const changeVisibility = (target, shown, settings) => {
            const previous = motions.get(target);
            if (previous?.shown === shown) return;
            const snapshot = previous ? { opacity: doc.defaultView.getComputedStyle(target).opacity, transform: doc.defaultView.getComputedStyle(target).transform } : null;
            cancelMotion(target);
            const needsChange = previous || visible(target) !== shown;
            if (shown) show(target);
            const motion = !shown && settings?.exit ? settings.exit : settings;
            if (!needsChange || !motion || motion.effect === 'none' || motion.duration === 0 || !target.animate || doc.defaultView.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
                if (!shown) hide(target);
                return;
            }
            const computed = doc.defaultView.getComputedStyle(target);
            const base = { opacity: computed.opacity, transform: computed.transform };
            const concealed = { opacity: '0', transform: base.transform };
            const transform = base.transform === 'none' ? '' : base.transform;
            if (motion.effect === 'slide') {
                const distance = (motion.distance ?? 24) * (shown ? 1 : -1), direction = motion.direction || 'up';
                const x = direction === 'left' ? distance : direction === 'right' ? -distance : 0;
                const y = direction === 'up' ? distance : direction === 'down' ? -distance : 0;
                concealed.transform = `translate(${x}px, ${y}px) ${transform}`.trim();
            } else if (motion.effect === 'scale') concealed.transform = `${transform} scale(.94)`.trim();
            const from = snapshot || (shown ? concealed : base), to = shown ? base : concealed;
            // No permanent opacity/transform declarations: cancelling restores the authored look.
            const animation = target.animate([from,to], { duration: motion.duration ?? 250, delay: previous ? 0 : motion.delay ?? 0, easing: motionCurves[motion.easing || 'smooth'], fill: 'both' });
            const record = { animation, shown }; motions.set(target,record);
            animation.finished.then(() => {
                if (motions.get(target) !== record) return;
                if (!shown) hide(target);
                motions.delete(target); animation.cancel();
            }, () => {});
        };
        const run = (action, clicked) => {
            for (const step of action.steps) for (const target of targets(step.target, clicked)) {
                if (!target.isConnected || !matches(step.condition, target)) continue;
                const operation = step.operation;
                if (operation.type === 'set-style') target.style.setProperty(operation.property, operation.value);
                else if (operation.type === 'reset-style') target.style.removeProperty(operation.property);
                else if (operation.type === 'add-class') target.classList.add(operation.value);
                else if (operation.type === 'remove-class') target.classList.remove(operation.value);
                else if (operation.type === 'toggle-class') target.classList.toggle(operation.value);
                else if (operation.type === 'hide') changeVisibility(target, false, operation.animation);
                else if (operation.type === 'show') changeVisibility(target, true, operation.animation);
                else if (operation.type === 'toggle-visibility') changeVisibility(target, !(motions.get(target)?.shown ?? visible(target)), operation.animation);
                else if (operation.type === 'remove-element') { cancelMotion(target); target.remove(); }
            }
        };
        const listeners = [];
        for (const trigger of Object.keys(triggers)) {
            const eventName = { mouseenter: 'mouseover', mouseleave: 'mouseout' }[trigger] || trigger;
            const capture = trigger === 'focus' || trigger === 'blur';
            const attr = attribute(trigger);
            const handler = event => {
                if (trigger === 'keydown' && event.repeat) return;
                let source = event.target.closest?.(`[${attr}]`);
                while (source && root.contains(source)) {
                    const hovered = trigger === 'mouseenter' || trigger === 'mouseleave';
                    const relatedInside = hovered && event.relatedTarget?.nodeType && source.contains(event.relatedTarget);
                    const focused = (trigger === 'focus' || trigger === 'blur') && event.target !== source;
                    const action = actions.get(source.getAttribute(attr));
                    if (!relatedInside && !focused && action?.trigger === trigger && (trigger !== 'keydown' || !action.key || action.key === event.key)) {
                        if (trigger === 'click' || trigger === 'submit' || (trigger === 'keydown' && action.key)) event.preventDefault();
                        try { run(action, source); }
                        catch (error) { doc.dispatchEvent(new doc.defaultView.CustomEvent('quill.action.error', { detail: { actionId: action.id, message: error.message } })); }
                    }
                    if (!hovered) break;
                    source = source.parentElement?.closest(`[${attr}]`);
                }
            };
            root.addEventListener(eventName, handler, capture); listeners.push([eventName, handler, capture]);
        }
        return () => {
            listeners.forEach(([name, handler, capture]) => root.removeEventListener(name, handler, capture));
            for (const [target,motion] of motions) { if (motion.shown) show(target); else hide(target); cancelMotion(target); }
        };
    }
    window.QuillActions = { validate, mount, triggers, bindings, keys };
    for (const script of document.querySelectorAll('script[data-quill-actions]')) {
        if (script.dataset.quillMounted) continue;
        script.dataset.quillMounted = 'true';
        mount(document, JSON.parse(script.textContent));
    }
})();
