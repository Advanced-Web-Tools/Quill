(() => {
    'use strict';

    const copy = value => JSON.parse(JSON.stringify(value));
    const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
    const voidTags = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);

    function validateBlock(block, path, depth = 0) {
        if (depth >= 128) throw new Error(`${path}: Maximum block depth exceeded.`);
        if (!isObject(block) || !isObject(block.descriptor)) throw new Error(`${path}: Missing block descriptor.`);
        if (block._quill?.animations !== undefined) window.QuillAnimations.validateBindings(block._quill.animations);
        const descriptor = block.descriptor;
        if (typeof descriptor.name !== 'string' || !/^[A-Za-z][A-Za-z0-9-]*$/.test(descriptor.name)) {
            throw new Error(`${path}: Enter a valid HTML tag.`);
        }
        if (!isObject(descriptor.props) || typeof descriptor.renderer !== 'string') throw new Error(`${path}: Invalid descriptor props or renderer.`);
        if (!isObject(block.attributes) || typeof block.content !== 'string' || !Array.isArray(block.children)) {
            throw new Error(`${path}: Invalid attributes, content or children.`);
        }
        for (const [name, value] of Object.entries(block.attributes)) {
            if (!/^[A-Za-z_:][A-Za-z0-9_.:-]*$/.test(name)
                || (value !== null && !['string', 'number', 'boolean'].includes(typeof value))) {
                throw new Error(`${path}/attributes/${name}: Attributes must have valid names and scalar values.`);
            }
        }
        const tag = descriptor.name.toLowerCase();
        if (voidTags.has(tag) && (block.content !== '' || block.children.length)) throw new Error(`${path}: ${tag} cannot contain text or children.`);
        if (['script', 'style'].includes(tag) && (block.content !== '' || block.children.length)) {
            throw new Error(`${path}: Text or children inside ${tag} are unsupported.`);
        }
        block.children.forEach((child, index) => validateBlock(child, `${path}/children/${index}`, depth + 1));
    }

    function validateDocument(document) {
        if (!isObject(document) || document.schema_version !== 1 || !Array.isArray(document.sections)) {
            throw new Error('Expected a schema_version 1 document with sections.');
        }
        if (document.settings !== undefined && (!isObject(document.settings)
            || (document.settings.styles !== undefined && !isObject(document.settings.styles)))) throw new Error('Invalid page settings.');
        if (document.settings?.animations !== undefined || document.settings?.animation_bindings !== undefined) window.QuillAnimations.validate(document.settings.animations || {}, document.settings.animation_bindings || []);
        if (document.settings?.color_palette !== undefined) window.QuillEditorPalette.validate(document.settings.color_palette);
        window.QuillEditorPalette.validateCollections(document.settings || {});
        const names = new Set();
        document.sections.forEach((section, index) => {
            if (!isObject(section) || typeof section.name !== 'string' || !section.name.trim()
                || names.has(section.name) || !Array.isArray(section.blocks)) {
                throw new Error(`sections/${index}: Invalid or duplicate section.`);
            }
            names.add(section.name);
            section.blocks.forEach((block, position) => validateBlock(block, `sections/${index}/blocks/${position}`));
        });
    }

    function findBlock(document, path) {
        if (typeof path !== 'string' || !/^\d+(?:\.\d+)+$/.test(path)) throw new Error('Invalid block path.');
        const [sectionIndex, ...indices] = path.split('.').map(Number);
        let blocks = document.sections[sectionIndex]?.blocks;
        let block;
        for (const index of indices) {
            block = blocks?.[index];
            if (!block) throw new Error(`Block ${path} no longer exists.`);
            blocks = block.children;
        }
        return block;
    }

    function componentRoot(document, path) {
        if (path === null) return null;
        const parts = path.split('.');
        for (let length = 2; length <= parts.length; length++) {
            const candidate = parts.slice(0, length).join('.');
            if (findBlock(document, candidate)._quill?.component) return candidate;
        }
        return null;
    }
    function sharedBlock(info, document, instanceId = 'qc-' + crypto.randomUUID(), externalTargets = {}) {
        return { descriptor: { name: 'quill-component', props: {}, renderer: '' }, attributes: {}, content: '',
            children: copy(document.sections.flatMap(section => section.blocks)),
            _quill: { component: { id: info.id, name: info.name, version: info.version || 1, instanceId, externalTargets: copy(externalTargets),
                assets: copy(document.assets || { styles: [], scripts: [] }),
                breakpoints: copy(document.settings?.breakpoints || { tablet: 1024, mobile: 767 }) } } };
    }

    function findList(document, parentPath) {
        if (/^\d+$/.test(parentPath)) return document.sections[Number(parentPath)]?.blocks;
        return findBlock(document, parentPath).children;
    }

    function pathOf(document, target) {
        function search(blocks, parent) {
            for (let index = 0; index < blocks.length; index++) {
                const path = `${parent}.${index}`;
                if (blocks[index] === target) return path;
                const childPath = search(blocks[index].children, path);
                if (childPath) return childPath;
            }
            return null;
        }
        for (let index = 0; index < document.sections.length; index++) {
            const result = search(document.sections[index].blocks, String(index));
            if (result) return result;
        }
        return null;
    }

    function animationTargets(document) {
        return (document.settings?.animation_bindings || []).map(binding => {
            const section = document.sections.findIndex(section => section.name === binding.target.section);
            const parts = [section, ...binding.target.path];
            for (let length=2; length<parts.length; length++) if (findBlock(document, parts.slice(0,length).join('.'))._quill?.component) throw new Error('Animation bindings target shared instances as a whole.');
            const node = findBlock(document, parts.join('.'));
            return { binding, node, snapshot: JSON.stringify(node) };
        });
    }
    function remapAnimations(document, targets) {
        if (!document.settings?.animation_bindings) return;
        const nodes = [];
        const visit = blocks => blocks.forEach(node => { nodes.push(node); if (!node._quill?.component) visit(node.children); });
        document.sections.forEach(section => visit(section.blocks));
        document.settings.animation_bindings = targets.flatMap(({binding, node, snapshot}) => {
            let path = pathOf(document, node);
            if (!path) { const matches = nodes.filter(node => JSON.stringify(node) === snapshot); if (matches.length === 1) path = pathOf(document, matches[0]); }
            if (!path) return [];
            const [section, ...indices] = path.split('.').map(Number);
            return [{ ...binding, target: { section: document.sections[section].name, path: indices } }];
        });
    }

    class QuillEditorState extends EventTarget {
        #document;
        #selectedPath = null;
        #dirty = false;
        #revision = 0;
        #componentPreviews = new Map();
        #editorType;
        #isolatedSession = null;

        constructor(document, { type = 'page' } = {}) {
            super();
            this.#editorType = type;
            validateDocument(document);
            this.#document = copy(document);
        }

        setAnimationBindings(path, bindings) {
            this.assertAvailable(); this.#writeAnimationBindings(path, bindings);
        }
        #writeAnimationBindings(path, bindings, legacyBindingId = null) {
            window.QuillAnimations.validateBindings(bindings);
            const candidate = copy(this.#document), root = componentRoot(candidate, path);
            if (root && root !== path) throw new Error('Animate the component instance or edit its source.');
            const block = findBlock(candidate, path);
            block._quill = { ...(block._quill || {}), animations: copy(bindings) };
            if (!bindings.length) delete block._quill.animations;
            if (legacyBindingId && candidate.settings?.animation_bindings) candidate.settings.animation_bindings = candidate.settings.animation_bindings.filter(binding => binding.id !== legacyBindingId);
            validateDocument(candidate);
            if (JSON.stringify(candidate) === JSON.stringify(this.#document)) return;
            this.#document = candidate; this.#dirty = true; this.#revision++;
            this.dispatchEvent(new CustomEvent('change', { detail: { source: 'animationbindings' } }));
        }

        assertAvailable() { if (this.#isolatedSession) throw new Error('Finish Animation Studio before editing the document.'); }
        beginIsolatedSession() {
            this.assertAvailable();
            const token = {}; this.#isolatedSession = token;
            return Object.freeze({
                commitAnimations: (animations, bindings) => {
                    if (this.#isolatedSession !== token) throw new Error('Animation session has expired.');
                    window.QuillAnimations.validate(animations, bindings);
                    const candidate = copy(this.#document);
                    candidate.settings = { ...(candidate.settings || {}), animations: copy(animations), animation_bindings: copy(bindings) };
                    animationTargets(candidate); validateDocument(candidate);
                    if (JSON.stringify(candidate) === JSON.stringify(this.#document)) return;
                    this.#document = candidate; this.#dirty = true; this.#revision++;
                    this.dispatchEvent(new CustomEvent('change', { detail: { source: 'animations' } }));
                },
                commitAnimationBindings: (path, bindings, legacyBindingId = null) => {
                    if (this.#isolatedSession !== token) throw new Error('Animation session has expired.');
                    this.#writeAnimationBindings(path, bindings, legacyBindingId);
                },
                release: () => { if (this.#isolatedSession === token) this.#isolatedSession = null; },
            });
        }

        get editorType() { return this.#editorType; }
        componentRoot(path = this.#selectedPath) { return componentRoot(this.#document, path); }
        assertEditable(path = this.#selectedPath) {
            this.assertAvailable();
            if (path !== null && this.componentRoot(path) !== null) throw new Error('Shared components are managed in the component editor. Drag to reposition this instance.');
        }
        replaceWithComponent(path, info, document) {
            this.assertEditable(path);
            const candidate = copy(this.#document), parts = path.split('.'), index = Number(parts.pop());
            const animationReferences = animationTargets(candidate);
            const originalAnimationNode = findBlock(candidate, path);
            const targets = new Set(), outsideActions = new Set();
            const scanTargets = block => {
                if (block._quill?.component) return;
                if (block._quill?.editor?.actionTargetId) targets.add(block._quill.editor.actionTargetId);
                block.children.forEach(scanTargets);
            };
            scanTargets(findBlock(candidate, path));
            const scanOutside = (blocks, parent) => blocks.forEach((block, at) => {
                const current = `${parent}.${at}`;
                if (current === path || block._quill?.component) return;
                for (const field of Object.values(window.QuillActions.bindings)) if (block._quill?.editor?.[field]) outsideActions.add(block._quill.editor[field]);
                scanOutside(block.children, current);
            });
            candidate.sections.forEach((section, at) => scanOutside(section.blocks, String(at)));
            const aliases = {};
            for (const action of candidate.settings?.actions || []) if (outsideActions.has(action.id)) {
                for (const step of action.steps) if (step.target.type === 'element' && targets.has(step.target.value)) aliases[step.target.value] = step.target.value;
            }
            findList(candidate, parts.join('.'))[index] = sharedBlock(info, document, 'qc-' + crypto.randomUUID(), aliases);
            animationReferences.forEach(reference => { if (reference.node === originalAnimationNode) reference.node = findBlock(candidate, path); });
            remapAnimations(candidate, animationReferences);
            validateDocument(candidate); this.#componentPreviews.set(info.id, { info: copy(info), document: copy(document) });
            this.#document = candidate; this.#selectedPath = path; this.#dirty = true; this.#revision++;
            this.dispatchEvent(new CustomEvent('change', { detail: { source: 'component-conversion' } }));
            this.dispatchEvent(new Event('selectionchange'));
        }
        detachComponent(path, document, targetMap = {}) {
            this.assertAvailable();
            if (this.componentRoot(path) !== path) throw new Error('Select the whole component instance.');
            const candidate = copy(this.#document), original = findBlock(candidate, path), local = copy(document);
            if (!original._quill?.component) throw new Error('Select a shared component.');
            validateDocument(local);
            const roots = local.sections.flatMap(section => section.blocks);
            if (!roots.length) throw new Error('This component has no blocks to convert.');
            const ensureLocal = nodes => nodes.forEach(node => { if (node._quill?.component) throw new Error('The local copy still contains a shared component.'); ensureLocal(node.children); });
            ensureLocal(roots);
            const references = animationTargets(candidate), parent = path.slice(0, path.lastIndexOf('.')), index = Number(path.split('.').at(-1));
            const importedBindings = animationTargets(local);
            for (const root of roots) {
                const bindings = root._quill?.animations || [];
                for (const binding of original._quill.animations || []) {
                    const previous = bindings.findIndex(item => item.animation_id === binding.animation_id);
                    if (previous >= 0) bindings.splice(previous, 1);
                    bindings.push(copy(binding));
                }
                if (bindings.length) root._quill = { ...(root._quill || {}), animations: bindings };
            }
            for (const reference of [...references]) if (reference.node === original) {
                reference.node = roots[0];
                for (const node of roots.slice(1)) references.push({ ...reference, node, binding: { ...copy(reference.binding), id: 'qbinding-' + crypto.randomUUID() } });
            }
            findList(candidate, parent).splice(index, 1, ...roots);
            candidate.settings ??= {};
            const actions = copy(candidate.settings.actions || []);
            for (const action of local.settings?.actions || []) {
                const existing = actions.find(item => item.id === action.id);
                if (existing && JSON.stringify(existing) !== JSON.stringify(action)) throw new Error('A local action identity conflicts with an existing page action.');
                if (!existing) actions.push(action);
            }
            const aliases = original._quill.component.externalTargets || {};
            for (const action of actions) for (const step of action.steps) {
                const target = step.target;
                if (target.type === 'element' && Object.hasOwn(aliases, target.value) && targetMap[aliases[target.value]]) target.value = targetMap[aliases[target.value]];
            }
            window.QuillActions.validate(actions);
            if (actions.length) candidate.settings.actions = actions;
            const importedAnimations = local.settings?.animations || {};
            if (Object.keys(importedAnimations).length) candidate.settings.animations = { ...(candidate.settings.animations || {}), ...importedAnimations };
            if (importedBindings.length) candidate.settings.animation_bindings = [...(candidate.settings.animation_bindings || []), ...importedBindings.map(item => item.binding)];
            remapAnimations(candidate, [...references, ...importedBindings]);
            const assets = candidate.assets || { styles: [], scripts: [] };
            for (const kind of ['styles','scripts']) assets[kind] = [...new Set([...(assets[kind] || []), ...(local.assets?.[kind] || [])])];
            candidate.assets = assets;
            validateDocument(candidate);
            this.#document = candidate; this.#selectedPath = path; this.#dirty = true; this.#revision++;
            this.dispatchEvent(new CustomEvent('change', { detail: { source: 'component-detach' } }));
            this.dispatchEvent(new Event('selectionchange'));
            return path;
        }
        insertComponent(info, document, sectionIndex, parentPath, index = null) {
            const block = sharedBlock(info, document);
            const path = this.insertBlocks(sectionIndex, [block], parentPath, index);
            this.#componentPreviews.set(info.id, { info: copy(info), document: copy(document) });
            return path;
        }
        refreshComponents(entries) {
            if (this.#isolatedSession) return;
            for (const { info, document } of entries) this.#componentPreviews.set(info.id, { info: copy(info), document: copy(document) });
            if (this.#hydrateComponents(this.#document)) this.dispatchEvent(new Event('componentrefresh'));
        }
        #hydrateComponents(document) {
            let changed = false;
            const visit = blocks => blocks.forEach((block, index) => {
                const component = block._quill?.component, cached = component && this.#componentPreviews.get(component.id);
                if (cached) {
                    const updated = sharedBlock(cached.info, cached.document, component.instanceId, component.externalTargets);
                    if (block._quill?.animations) updated._quill.animations = copy(block._quill.animations);
                    if (JSON.stringify(block) !== JSON.stringify(updated)) { blocks[index] = updated; changed = true; }
                } else if (!component) visit(block.children);
            });
            document.sections.forEach(section => visit(section.blocks)); return changed;
        }

        get selectedPath() { return this.#selectedPath; }
        get dirty() { return this.#dirty; }
        get revision() { return this.#revision; }
        getDocument() { return copy(this.#document); }
        getBlock(path) { return copy(findBlock(this.#document, path)); }

        select(path) {
            this.assertAvailable();
            if (path !== null) findBlock(this.#document, path);
            this.#selectedPath = componentRoot(this.#document, path) || path;
            this.dispatchEvent(new Event('selectionchange'));
        }

        updateBlock(path, changes, source = 'properties', settings = null) {
            this.assertEditable(path);
            const candidate = copy(this.#document);
            const animationReferences = animationTargets(candidate);
            if (settings) candidate.settings = { ...(candidate.settings || {}), ...copy(settings) };
            const block = findBlock(candidate, path);
            if (changes.descriptor !== undefined) block.descriptor = copy(changes.descriptor);
            if (changes.attributes !== undefined) block.attributes = copy(changes.attributes);
            if (changes.content !== undefined) block.content = changes.content;
            if (changes.children !== undefined) block.children = copy(changes.children);
            if (changes._quill !== undefined) block._quill = copy(changes._quill);
            // Validate the complete candidate before committing so an invalid edit leaves state intact.
            remapAnimations(candidate, animationReferences);
            validateDocument(candidate);
            if (JSON.stringify(candidate) === JSON.stringify(this.#document)) return;
            this.#document = candidate;
            this.#dirty = true;
            this.#revision++;
            this.dispatchEvent(new CustomEvent('change', { detail: { source, path } }));
        }

        updateSettings(changes) {
            this.assertAvailable();
            if (!isObject(changes)) throw new Error('Invalid page settings.');
            const candidate = copy(this.#document);
            candidate.settings = { ...(candidate.settings || {}), ...copy(changes) };
            validateDocument(candidate);
            if (JSON.stringify(candidate) === JSON.stringify(this.#document)) return;
            this.#document = candidate; this.#dirty = true; this.#revision++;
            this.dispatchEvent(new CustomEvent('change', { detail: { source: 'page-settings' } }));
        }

        updateActions(actions, patches = [], removeId = null) {
            this.assertAvailable();
            window.QuillActions.validate(actions);
            const candidate = copy(this.#document);
            candidate.settings = { ...(candidate.settings || {}), actions: copy(actions) };
            for (const { path, editor } of patches) {
                this.assertEditable(path);
                const block = findBlock(candidate, path);
                block._quill = { ...(block._quill || {}), editor: { ...(block._quill?.editor || {}), ...copy(editor) } };
            }
            const visit = blocks => blocks.forEach(block => {
                if (block._quill?.component) return;
                const metadata = block._quill?.editor;
                if (metadata) {
                    const assigned = [];
                    for (const field of Object.values(window.QuillActions.bindings)) {
                        if (metadata[field] && metadata[field] !== removeId) assigned.push(metadata[field]);
                        delete metadata[field];
                    }
                    for (const id of assigned) {
                        const action = actions.find(action => action.id === id);
                        if (!action) throw new Error('Assigned action no longer exists.');
                        const field = window.QuillActions.bindings[action.trigger];
                        if (metadata[field] && metadata[field] !== id) throw new Error(`This element already has an action for ${window.QuillActions.triggers[action.trigger]}. Remove that assignment first.`);
                        metadata[field] = id;
                    }
                }
                visit(block.children);
            });
            candidate.sections.forEach(section => visit(section.blocks));
            validateDocument(candidate);
            if (JSON.stringify(candidate) === JSON.stringify(this.#document)) return;
            this.#document = candidate; this.#dirty = true; this.#revision++;
            this.dispatchEvent(new CustomEvent('change', { detail: { source: 'actions' } }));
        }

        addBlock(sectionIndex, tag = 'p', parentPath = this.#selectedPath) {
            const block = {
                descriptor: { name: tag, props: {}, renderer: '' },
                attributes: {},
                content: tag === 'h1' ? 'Heading' : (tag === 'p' ? 'Write your text here.' : (tag === 'button' ? 'Button' : '')),
                children: [],
            };
            if (tag === 'a') block.content = 'Link';
            if (tag === 'img') block.attributes = { alt: 'Image description' };
            return this.insertBlocks(sectionIndex, [block], parentPath);
        }

        insertBlocks(sectionIndex, templates, parentPath = this.#selectedPath, index = null) {
            this.assertAvailable();
            if (parentPath !== null) this.assertEditable(parentPath);
            const candidate = copy(this.#document);
            const animationReferences = animationTargets(candidate);
            const section = candidate.sections[sectionIndex];
            if (!section) throw new Error('Choose an existing section.');
            if (!Array.isArray(templates) || !templates.length) throw new Error('Choose a definition with root blocks.');
            const parent = parentPath === null ? null : findBlock(candidate, parentPath);
            if (parent && (voidTags.has(parent.descriptor.name.toLowerCase()) || ['script', 'style'].includes(parent.descriptor.name.toLowerCase()))) {
                throw new Error(`Cannot insert a block into ${parent.descriptor.name}. Select a container first.`);
            }
            const blocks = parent ? parent.children : section.blocks;
            const position = index === null ? blocks.length : index;
            if (!Number.isInteger(position) || position < 0 || position > blocks.length) throw new Error('Invalid insertion position.');
            const path = `${parent ? parentPath : sectionIndex}.${position}`;
            const instances = copy(templates);
            const renewTargets = nodes => nodes.forEach(block => {
                if (block._quill?.component) { block._quill.component.instanceId = 'qc-' + crypto.randomUUID(); block._quill.component.externalTargets = {}; return; }
                if (block._quill?.editor?.actionTargetId) block._quill.editor.actionTargetId = 'qt-' + crypto.randomUUID();
                renewTargets(block.children);
            });
            renewTargets(instances);
            blocks.splice(position, 0, ...instances);
            remapAnimations(candidate, animationReferences);
            validateDocument(candidate);
            this.#document = candidate;
            this.#dirty = true;
            this.#revision++;
            this.#selectedPath = path;
            this.dispatchEvent(new Event('change'));
            this.dispatchEvent(new Event('selectionchange'));
            return path;
        }

        duplicateBlock(path = this.#selectedPath) {
            this.assertEditable(path);
            if (path === null) throw new Error('Select a block first.');
            const parts = path.split('.'); const index = Number(parts.pop()); const parent = parts.join('.');
            const template = this.getBlock(path);
            const renewIds = blocks => blocks.forEach(block => {
                if (block._quill?.component) return;
                if (block.attributes.id) block.attributes.id += '-copy-' + crypto.randomUUID().slice(0, 8);
                renewIds(block.children);
            });
            renewIds([template]);
            return this.insertBlocks(Number(parts[0]), [template], parts.length > 1 ? parent : null, index + 1);
        }

        removeBlock(path = this.#selectedPath) {
            const root = this.componentRoot(path);
            if (root !== null && root === path) this.assertAvailable();
            else this.assertEditable(path);
            const candidate = copy(this.#document);
            const animationReferences = animationTargets(candidate);
            const target = findBlock(candidate, path);
            const selected = this.#selectedPath === null ? null : findBlock(candidate, this.#selectedPath);
            const parent = path.slice(0, path.lastIndexOf('.'));
            const list = findList(candidate, parent); const index = list.indexOf(target);
            list.splice(index, 1);
            const selectionRemoved = this.#selectedPath === path || this.#selectedPath?.startsWith(path + '.');
            const next = selectionRemoved ? (parent.includes('.') ? findBlock(candidate, parent) : list[index] || list[index - 1] || null) : selected;
            remapAnimations(candidate, animationReferences);
            validateDocument(candidate);
            this.#document = candidate; this.#selectedPath = next ? pathOf(candidate, next) : null;
            this.#dirty = true; this.#revision++;
            this.dispatchEvent(new CustomEvent('change', { detail: { source: 'structure' } }));
            this.dispatchEvent(new Event('selectionchange'));
        }

        moveBlock(sourcePath, targetPath, position = 'inside') {
            this.assertAvailable();
            const sourceComponent = this.componentRoot(sourcePath);
            if (sourceComponent && sourceComponent !== sourcePath) throw new Error('Move the shared component as a whole.');
            const targetComponent = targetPath.includes('.') ? this.componentRoot(targetPath) : null;
            if (targetComponent && (position === 'inside' || targetComponent !== targetPath)) throw new Error('Blocks cannot be inserted inside a shared component.');
            if (!['before', 'after', 'inside'].includes(position)) throw new Error('Unknown move position.');
            if (sourcePath === targetPath || targetPath.startsWith(`${sourcePath}.`)) throw new Error('A block cannot be moved into itself or its descendants.');
            const candidate = copy(this.#document);
            const animationReferences = animationTargets(candidate);
            const source = findBlock(candidate, sourcePath);
            const selected = this.#selectedPath ? findBlock(candidate, this.#selectedPath) : source;
            const sourceParent = sourcePath.slice(0, sourcePath.lastIndexOf('.'));
            const sourceList = findList(candidate, sourceParent);
            let targetList;
            let target;
            if (position === 'inside') {
                targetList = findList(candidate, targetPath);
                if (!targetList) throw new Error('Choose an existing destination.');
            } else {
                target = findBlock(candidate, targetPath);
                targetList = findList(candidate, targetPath.slice(0, targetPath.lastIndexOf('.')));
            }
            sourceList.splice(sourceList.indexOf(source), 1);
            const index = position === 'inside' ? targetList.length : targetList.indexOf(target) + (position === 'after' ? 1 : 0);
            targetList.splice(index, 0, source);
            remapAnimations(candidate, animationReferences);
            validateDocument(candidate);
            this.#document = candidate;
            this.#selectedPath = pathOf(candidate, selected);
            this.#dirty = true;
            this.#revision++;
            this.dispatchEvent(new CustomEvent('change', { detail: { source: 'structure' } }));
            this.dispatchEvent(new Event('selectionchange'));
        }

        moveSelected(direction) {
            this.assertEditable();
            const path = this.#selectedPath;
            if (!path) throw new Error('Select a block to move.');
            const parts = path.split('.');
            const index = Number(parts.pop());
            const parent = parts.join('.');
            const siblings = findList(this.#document, parent);
            if (direction === 'up' && index > 0) return this.moveBlock(path, `${parent}.${index - 1}`, 'before');
            if (direction === 'down' && index < siblings.length - 1) return this.moveBlock(path, `${parent}.${index + 1}`, 'after');
            if (direction === 'indent' && index > 0) return this.moveBlock(path, `${parent}.${index - 1}`, 'inside');
            if (direction === 'outdent' && parts.length > 1) return this.moveBlock(path, parent, 'after');
            throw new Error('The selected block cannot move in that direction.');
        }

        restoreDocument(document, selectedPath = null) {
            this.assertAvailable();
            validateDocument(document);
            if (selectedPath !== null) findBlock(document, selectedPath);
            this.#document = copy(document);
            this.#hydrateComponents(this.#document);
            this.#selectedPath = componentRoot(this.#document, selectedPath) || selectedPath;
            this.#dirty = true;
            this.#revision++;
            this.dispatchEvent(new CustomEvent('change', { detail: { source: 'history' } }));
            this.dispatchEvent(new Event('selectionchange'));
        }

        markSaved(revision) {
            this.assertAvailable();
            if (revision === this.#revision) this.#dirty = false;
            this.dispatchEvent(new Event('saved'));
        }
    }

    window.QuillEditorState = QuillEditorState;
})();
