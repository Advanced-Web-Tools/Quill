(() => {
    'use strict';
    class QuillEditorPageSettings {
        constructor(editor, bootstrap, reportError) {
            const get = id => document.getElementById(id);
            const panel = get('editor-page-settings');
            const menu = get('editor-page-menu');
            const title = get('editor-page-title');
            const description = get('editor-page-description');
            const destinations = [
                { button: menu, panel, content: get('editor-page-general') },
                { button: get('editor-page-tab-styling'), panel: get('editor-page-styling-panel'), content: get('editor-page-styling') },
                { button: get('editor-page-tab-actions'), panel: get('editor-page-actions-panel'), content: get('editor-page-actions') },
            ];
            let active = null;
            const activate = destination => {
                if (destination && !get('editor-block-dialog').hidden) editor.ui.picker.close();
                active = destination;
                for (const item of destinations) {
                    const open = item === destination;
                    item.panel.hidden = item.content.hidden = !open;
                    item.button.setAttribute('aria-expanded', String(open));
                }
                if (destination === destinations[1]) this.loadStyling();
                if (destination === destinations[0]) title.focus();
            };
            this.close = () => { const button = active?.button; activate(null); button?.focus(); };
            destinations.forEach(destination => {
                destination.button.addEventListener('click', () => { if (active === destination) this.close(); else activate(destination); });
                destination.panel.querySelector('header button').addEventListener('click', () => this.close());
                destination.panel.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); this.close(); } });
            });
            editor.ui.pageRoutes = new window.QuillEditorPageRoutes(editor, bootstrap, reportError);
            const snapshots = get('editor-page-snapshots');
            if (snapshots && bootstrap.editorType === 'page' && bootstrap.externalSourceKind !== 'component' && Number.isSafeInteger(bootstrap.editorId)) {
                snapshots.hidden = false;
                editor.ui.pageHistory = new window.QuillEditorBlockHistory(editor, bootstrap, true);
                get('editor-page-snapshots-open').addEventListener('click', () => editor.ui.pageHistory.open());
            }
            title.addEventListener('input', () => editor.state.updateSettings({ title: title.value }));
            description.addEventListener('input', () => editor.state.updateSettings({ description: description.value }));
            const block = () => ({ attributes: {}, styles: { base: editor.state.getDocument().settings?.styles || {} } });
            const update = () => {
                const settings = editor.state.getDocument().settings || {};
                if (document.activeElement !== title) title.value = settings.title || '';
                if (document.activeElement !== description) description.value = settings.description || '';
                get('quill-editor').querySelector('.editor-document-name h1').textContent = settings.title || 'Untitled document';
                document.title = `${settings.title || 'Untitled document'} | Quill`;
                this.controls?.update(block(), { selectionChanged: false });
            };
            editor.state.addEventListener('change', update); update();
            const saveStyle = (property, value) => {
                if (value && (!CSS.supports(property, value) || /[{};<>\x00-\x1f\x7f]|\/\*|\*\//.test(value))) throw new Error(`Invalid ${property}.`);
                const styles = { ...(editor.state.getDocument().settings?.styles || {}) };
                if (value) styles[property] = value; else delete styles[property];
                editor.state.updateSettings({ styles });
            };
            const declarations = () => {
                const style = document.createElement('div').style;
                for (const [property, value] of Object.entries(block().styles.base)) style.cssText += `${property}:${value};`;
                return style;
            };
            const saveDeclarations = cssText => {
                const style = document.createElement('div').style; style.cssText = cssText || '';
                const styles = Object.fromEntries([...style].map(property => [property, style.getPropertyValue(property) + (style.getPropertyPriority(property) ? ' !important' : '')]));
                editor.state.updateSettings({ styles });
            };
            const adapter = {
                canvas: { getElement: () => get('editor-canvas').contentDocument?.body }, selectedPath: null,
                mediaLibrary: editor.mediaLibrary, fonts: editor.fonts, palette: editor.palette, styleScope: 'base', isReadOnly: () => false,
                getStyleDeclarations: declarations, setStyleDeclarations: saveDeclarations,
                setStyle: saveStyle, reportError, on: (...args) => editor.on(...args),
                getSelectedBlock: () => {
                    const style = document.createElement('div').style;
                    for (const [property, value] of Object.entries(block().styles.base)) style.setProperty(property, value);
                    return { attributes: { style: style.cssText } };
                },
                setAttribute: (name, value) => {
                    const style = document.createElement('div').style; style.cssText = value || '';
                    editor.state.updateSettings({ styles: Object.fromEntries([...style].map(property => [property, style.getPropertyValue(property)])) });
                },
            };
            this.loadStyling = () => this.loading ||= import(bootstrap.propertyAssetBase + 'controls.js').then(({ mountGroups }) => {
                this.controls = mountGroups({ container: get('editor-page-styling'), editor: adapter, signal: new AbortController().signal }, [
                    ['Default font', [['font-family', 'Typeface'], ['font-weight', 'Weight', ['normal', 'bold', '100', '200', '300', '400', '500', '600', '700', '800', '900']], ['font-style', 'Style', ['normal', 'italic', 'oblique']]]],
                    ['Spacing', [['margin', 'Space around'], ['padding', 'Space inside']]],
                    ['Background', [
                        ['background-color', 'Background color'], ['background-image', 'Background'],
                        ['background-size', 'Image fit', ['auto', 'cover', 'contain'], ({style, computed}) => (style.backgroundImage || computed.backgroundImage) !== 'none'],
                        ['background-position', 'Image position', null, ({style, computed}) => (style.backgroundImage || computed.backgroundImage) !== 'none'],
                        ['background-repeat', 'Tiling', ['no-repeat', 'repeat', 'repeat-x', 'repeat-y', 'space', 'round'], ({style, computed}) => (style.backgroundImage || computed.backgroundImage) !== 'none'],
                    ]],
                ]);
                editor.palette.mountManager(get('editor-page-styling'));
                this.controls.update(block(), { selectionChanged: true });
            }).catch(reportError);
        }
    }
    window.QuillEditorPageSettings = QuillEditorPageSettings;
})();
