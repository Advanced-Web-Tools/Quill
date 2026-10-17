(() => {
    'use strict';

    class QuillEditorViewport {
        #area;
        #stage;
        #frame;
        #device;
        #zoom;
        #editor;
        #observer;
        #animation = null;
        #abort = new AbortController();

        constructor(editor) {
            this.#editor = editor;
            this.#area = document.querySelector('.editor-canvas-area');
            this.#stage = document.getElementById('editor-canvas-stage');
            this.#frame = document.getElementById('editor-canvas');
            this.#device = document.getElementById('editor-viewport');
            this.#zoom = document.getElementById('editor-zoom');
            const options = { signal: this.#abort.signal };
            const update = () => this.#schedule();
            this.#device.addEventListener('change', () => {
                this.#area.scrollLeft = 0;
                this.#area.scrollTop = 0;
                update();
            }, options);
            this.#zoom.addEventListener('change', update, options);
            this.#observer = new ResizeObserver(update);
            this.#observer.observe(this.#area);
            const notify = () => {
                editor.canvas.select(editor.selectedPath);
                // Re-evaluate controls that depend on the iframe's computed responsive styles.
                editor.dispatchEvent(new Event('canvasrendered'));
                editor.dispatchEvent(new CustomEvent('viewportchange', { detail: this.getViewport() }));
            };
            this.#frame.addEventListener('load', () => {
                this.#frame.contentWindow.addEventListener('resize', notify, options);
                update();
            }, options);
            this.#deviceIcons();
            this.#update();
        }

        #schedule() {
            if (this.#animation !== null) cancelAnimationFrame(this.#animation);
            this.#animation = requestAnimationFrame(() => { this.#animation = null; this.#update(); });
        }

        #deviceIcons() {
            const label = document.querySelector('label[for="editor-viewport"]');
            const device = this.#device.selectedOptions[0];
            const icon = label.querySelector('i');
            icon.className = `fa-solid ${device.dataset.icon}`;
            label.title = `${device.textContent} preview`;
        }

        #update() {
            const width = Number(this.#device.value);
            const availableWidth = this.#area.clientWidth;
            const availableHeight = this.#area.clientHeight;
            if (!width || !availableWidth || !availableHeight) return;
            const scale = this.#zoom.value === 'fit' ? Math.min(1, availableWidth / width) : Number(this.#zoom.value);
            if (!Number.isFinite(scale) || scale <= 0) return;
            // The stage reserves only painted space; the unscaled iframe cannot create phantom scrollbars.
            this.#stage.style.width = `${width * scale}px`;
            this.#stage.style.height = `${availableHeight}px`;
            this.#frame.style.width = `${width}px`;
            this.#frame.style.height = `${availableHeight / scale}px`;
            this.#frame.style.transform = `scale(${scale})`;
            this.#area.dataset.device = this.#device.selectedOptions[0].textContent.toLowerCase();
            this.#stage.dataset.scale = String(scale);
            this.#deviceIcons();
            document.getElementById('editor-viewport-size').textContent = `${width}px`;
            const fit = this.#zoom.querySelector('[value="fit"]');
            fit.textContent = `Fit (${Math.round(Math.min(1, availableWidth / width) * 100)}%)`;
        }

        getViewport() {
            return { width: Number(this.#device.value), scale: Number(this.#stage.dataset.scale), device: this.#area.dataset.device };
        }

        destroy() {
            this.#abort.abort();
            this.#observer.disconnect();
            if (this.#animation !== null) cancelAnimationFrame(this.#animation);
        }
    }
    window.QuillEditorViewport = QuillEditorViewport;
})();
