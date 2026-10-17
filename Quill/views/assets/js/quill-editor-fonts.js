(() => {
    'use strict';
    class QuillEditorFonts {
        constructor(catalog = []) {
            this.catalog = catalog;
            this.byName = new Map(catalog.map(font => [font.family, font]));
        }
        families(css = '') {
            return [...new Set((String(css).match(/"[^"]+"|'[^']+'|[^,]+/g) || []).map(value => value.trim().replace(/^(['"])(.*)\1$/, '$2')).filter(value => this.byName.has(value)))];
        }
        find(css) { return this.byName.get(this.families(css)[0]) || null; }
        fallback(font) { return ({Serif:'serif', Monospace:'monospace', Handwriting:'cursive'})[font.category] || 'sans-serif'; }
        weights(font, italic = false) {
            return font.variants.filter(value => value.endsWith('i') === italic).map(value => parseFloat(value));
        }
        variant(font, weight, italic) {
            let available = this.weights(font, !!italic);
            if (!available.length) { italic = 0; available = this.weights(font, false); }
            if (!available.length) { italic = 1; available = this.weights(font, true); }
            if (font.weightRange) weight = Math.max(font.weightRange[0], Math.min(font.weightRange[1], weight));
            else weight = [...available].sort((a,b) => Math.abs(a-weight)-Math.abs(b-weight) || a-b)[0];
            return `${italic},${weight}`;
        }
        url(family, variants) {
            const sorted = [...new Set(variants)].sort((a,b) => {const [ai,aw]=a.split(',').map(Number), [bi,bw]=b.split(',').map(Number);return ai-bi || aw-bw;});
            return `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family).replace(/%20/g,'+')}:ital,wght@${sorted.join(';')}&display=swap`;
        }
        groups(block) {
            const groups = structuredClone(block._quill?.styles || block.styles || {});
            groups.base ||= {};
            const inline = document.createElement('div').style; inline.cssText = block.attributes?.style || '';
            for (const property of ['font-family','font-weight','font-style']) if (inline.getPropertyValue(property)) {
                for (const group of Object.values(groups)) delete group[property];
                groups.base[property] = inline.getPropertyValue(property);
            }
            return groups;
        }
        contexts(groups, parent, tag = '') {
            const apply = (values, inherited) => {
                const result = structuredClone(inherited);
                for (const property of ['font-family','font-weight','font-style']) {
                    const value = String(values?.[property] || '').trim();
                    if (!value || ['inherit','unset'].includes(value)) continue;
                    if (property === 'font-family') result.families = this.families(value);
                    if (property === 'font-style') result.italics = [/^(italic|oblique)/.test(value) ? 1 : 0];
                    if (property === 'font-weight') result.weights = Number.isFinite(Number(value)) ? [Number(value)]
                        : value === 'bolder' ? result.weights.map(weight => weight < 350 ? 400 : weight < 550 ? 700 : 900)
                        : value === 'lighter' ? result.weights.map(weight => weight < 550 ? 100 : weight < 750 ? 400 : 700) : [value === 'bold' ? 700 : 400];
                }
                return result;
            };
            let base = structuredClone(parent);
            if (['h1','h2','h3','h4','h5','h6','strong','b','th'].includes(tag.toLowerCase())) base.weights = [700];
            if (['em','i'].includes(tag.toLowerCase())) base.italics = [1];
            base = apply(groups.base, base);
            const contexts = [];
            for (const viewport of ['base','desktop','tablet','mobile']) {
                const scopes = viewport === 'mobile' ? ['tablet','mobile'] : viewport === 'base' ? [] : [viewport];
                let view = base;
                for (const scope of scopes) view = apply(groups[scope], view);
                contexts.push(view);
                for (const state of ['hover','focus','focus-visible','active','disabled','checked']) {
                    let active = apply(groups[state], view);
                    for (const scope of scopes) active = apply(groups[`${scope}:${state}`], active);
                    contexts.push(active);
                }
            }
            return contexts;
        }
        inherited(contexts) {
            return Object.fromEntries(['families','weights','italics'].map(key => [key,[...new Set(contexts.flatMap(context => context[key]))]]));
        }
        stylesheets(doc) {
            const requests = new Map();
            const visit = (blocks, parent) => blocks.forEach(block => {
                const contexts = this.contexts(this.groups(block), parent, block.descriptor.name);
                if (block.content.trim()) for (const context of contexts) for (const family of context.families) {
                    if (!requests.has(family)) requests.set(family,new Set());
                    for (const weight of context.weights) for (const italic of context.italics) requests.get(family).add(this.variant(this.byName.get(family),weight,italic));
                }
                visit(block.children,this.inherited(contexts));
            });
            const root = this.inherited(this.contexts({base:doc.settings?.styles || {}},{families:[],weights:[400],italics:[0]}));
            doc.sections.forEach(section => visit(section.blocks,root));
            return [...requests].sort(([a],[b]) => a < b ? -1 : a > b ? 1 : 0).map(([family,variants]) => this.url(family,[...variants]));
        }
    }
    window.QuillEditorFonts = QuillEditorFonts;
})();
