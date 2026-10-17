/* Page animation data and CSS-property contract. No editor UI or document mutators. */
(() => {
    'use strict';
    const properties = /^(?:opacity|color|background(?:-(?:color|image|size|position|position-x|position-y|clip))?|border(?:-(?:top|right|bottom|left))?(?:-(?:width|color|style))?|border-radius|border-(?:top-left|top-right|bottom-left|bottom-right)-radius|border-image-(?:source|slice)|(?:min-|max-)?(?:width|height)|aspect-ratio|margin(?:-(?:top|right|bottom|left))?|padding(?:-(?:top|right|bottom|left))?|gap|row-gap|column-gap|top|right|bottom|left|inset|translate|rotate|scale|transform|transform-origin|filter|backdrop-filter|box-shadow|text-shadow|font-size|font-weight|line-height|letter-spacing|word-spacing|text-indent|text-decoration-(?:color|thickness)|text-underline-offset|flex-(?:grow|shrink|basis)|grid-template-(?:columns|rows)|-webkit-text-fill-color|-webkit-background-clip|--[A-Za-z_][A-Za-z0-9_-]*)$/;
    const easing = /^(?:linear|ease|ease-in|ease-out|ease-in-out|step-start|step-end|cubic-bezier\(\s*-?[\d.]+\s*,\s*-?[\d.]+\s*,\s*-?[\d.]+\s*,\s*-?[\d.]+\s*\)|steps\(\s*[1-9]\d*\s*(?:,\s*(?:start|end|jump-start|jump-end|jump-none|jump-both)\s*)?\))$/;
    const object = value => value && typeof value === 'object' && !Array.isArray(value);
    const id = value => typeof value === 'string' && /^[A-Za-z][A-Za-z0-9_-]{0,99}$/.test(value);
    const time = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 600000;
    function validate(animations = {}, bindings = []) {
        if (!object(animations) || Object.keys(animations).length > 100 || !Array.isArray(bindings) || bindings.length > 500
            || JSON.stringify([animations, bindings]).length > 2000000) throw new Error('Invalid or oversized page animation registry.');
        for (const [key, animation] of Object.entries(animations)) {
            if (!id(key) || !object(animation) || animation.id !== key || Object.keys(animation).some(key => !['id','name','duration','delay','iterations','direction','fillMode','easing','tracks'].includes(key))
                || typeof animation.name !== 'string' || !animation.name.trim() || animation.name.length > 255 || !time(animation.duration) || animation.duration < 1 || !time(animation.delay)
                || !(animation.iterations === 'infinite' || (Number.isInteger(animation.iterations) && animation.iterations >= 1 && animation.iterations <= 1000))
                || !['normal','reverse','alternate','alternate-reverse'].includes(animation.direction) || !['none','forwards','backwards','both'].includes(animation.fillMode)
                || !validEasing(animation.easing) || !object(animation.tracks) || !Object.keys(animation.tracks).length || Object.keys(animation.tracks).length > 100) throw new Error('Enter valid animation settings and at least one property track.');
            for (const [property, frames] of Object.entries(animation.tracks)) {
                if (!supports(property) || !Array.isArray(frames) || frames.length < 1 || frames.length > 200) throw new Error(`Unsupported animation track: ${property}`);
                let previous = -1;
                for (const frame of frames) {
                    if (!object(frame) || Object.keys(frame).some(key => !['time','value','easing'].includes(key)) || !time(frame.time) || frame.time > animation.duration || frame.time <= previous
                        || typeof frame.value !== 'string' || !frame.value.trim() || frame.value.length > 4096 || /[{};<>\x00-\x1f\x7f]|\/\*|\*\//.test(frame.value)
                        || !CSS.supports(property, frame.value) || (frame.easing !== undefined && !validEasing(frame.easing))) throw new Error(`Invalid keyframe in ${property}.`);
                    previous = frame.time;
                }
            }
        }
        const ids = new Set();
        for (const binding of bindings) {
            if (!object(binding) || Object.keys(binding).some(key => !['id','animationId','target','trigger'].includes(key)) || !id(binding.id) || ids.has(binding.id) || !Object.hasOwn(animations, binding.animationId)
                || !object(binding.target) || Object.keys(binding.target).some(key => !['section','path'].includes(key)) || typeof binding.target.section !== 'string' || !binding.target.section.trim()
                || !Array.isArray(binding.target.path) || binding.target.path.length < 1 || binding.target.path.length > 128 || binding.target.path.some(index => !Number.isInteger(index) || index < 0)
                || !['load','click','hover','focus'].includes(binding.trigger)) throw new Error('Invalid page animation binding.');
            ids.add(binding.id);
        }
        return animations;
    }
    // The allowlist contains visual CSS animation properties (including discrete ones).
    // Parsing support alone cannot distinguish animation controls from ordinary CSS.
    function validateBindings(bindings) {
        if (!Array.isArray(bindings) || bindings.length > 20) throw new Error('A block supports up to 20 animations.');
        const ids = new Set();
        for (const binding of bindings) {
            if (!object(binding) || Object.keys(binding).some(key => !['animation_id','trigger'].includes(key)) || !Number.isSafeInteger(binding.animation_id) || binding.animation_id < 1
                || ids.has(binding.animation_id) || !['load','click','hover','focus'].includes(binding.trigger)) throw new Error('Invalid block animation reference.');
            ids.add(binding.animation_id);
        }
        return bindings;
    }
    function supports(property) { return typeof property === 'string' && properties.test(property) && CSS.supports(property, 'initial'); }
    function validEasing(value) { return typeof value === 'string' && easing.test(value) && CSS.supports('animation-timing-function', value); }
    const axes = Object.freeze(Object.fromEntries(['translate','scale'].flatMap(group => ['x','y','z'].map(axis => [`--quill-animation-${group}-${axis}`, { group, axis, syntax: group === 'scale' ? '<number>' : axis === 'z' ? '<length>' : '<length-percentage>', initial: group === 'scale' ? '1' : '0px' }]))));
    function components(value) {
        const tokens = []; let depth = 0, start = 0;
        for (let i=0; i<=value.length; i++) {
            if (value[i] === '(') depth++; if (value[i] === ')') depth--;
            if (i === value.length || (!depth && /\s/.test(value[i]))) { if (i>start) tokens.push(value.slice(start,i)); start=i+1; }
        }
        return tokens;
    }
    function label(property) {
        if (axes[property]) return `${axes[property].group === 'translate' ? 'Translate' : 'Scale'} ${axes[property].axis.toUpperCase()}`;
        return property.replace(/-/g, ' ').replace(/^./, value => value.toUpperCase());
    }
    window.QuillAnimations = Object.freeze({ validate, validateBindings, supports, validEasing, axes, components, label });
})();
