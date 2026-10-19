(() => {
    'use strict';
    class QuillEditorBlockHistory {
        #editor; #bootstrap; #dialog; #path; #uid; #page = 1; #request; #opener; #pageHistory;
        constructor(editor, bootstrap, pageHistory = false) {
            this.#pageHistory = pageHistory;
            this.#editor = editor; this.#bootstrap = bootstrap;
            this.#dialog = document.getElementById(pageHistory ? 'editor-page-history' : 'editor-block-history');
            this.#dialog.querySelector('[data-history-close]').addEventListener('click', () => this.#dialog.close());
            this.#dialog.addEventListener('close', () => {
                this.#request?.abort();
                const opener = this.#opener?.isConnected ? this.#opener : editor.canvas.getElement(editor.selectedPath);
                opener?.focus();
            });
            this.#dialog.querySelector('[data-history-prev]').addEventListener('click', () => this.#load(this.#page - 1));
            this.#dialog.querySelector('[data-history-next]').addEventListener('click', () => this.#load(this.#page + 1));
        }
        #identity(block) {
            return this.#editor.state.sourceBound ? block.editor?.urpcUid : block.editor?.historyId;
        }
        open(path) {
            if (this.#pageHistory) {
                this.#editor.state.assertAvailable();
                this.#opener = document.activeElement;
                this.#dialog.querySelector('[data-history-context]').textContent = this.#editor.getDocument().settings?.title || this.#bootstrap.name;
                this.#dialog.showModal(); this.#load(1); return;
            }
            this.#editor.state.assertEditable(path);
            this.#path = path;
            const block = this.#editor.getBlock(path);
            this.#uid = this.#identity(block);
            this.#opener = document.getElementById('editor-canvas').contentDocument.activeElement;
            this.#dialog.querySelector('[data-history-context]').textContent = block.editor?.label || block.descriptor.name;
            this.#dialog.showModal();
            this.#load(1);
        }
        async #load(page) {
            this.#request?.abort();
            const request = new AbortController(); this.#request = request;
            const list = this.#dialog.querySelector('[data-history-list]');
            const count = this.#dialog.querySelector('[data-history-count]');
            const previous = this.#dialog.querySelector('[data-history-prev]');
            const next = this.#dialog.querySelector('[data-history-next]');
            previous.disabled = next.disabled = true;
            list.replaceChildren(); count.textContent = 'Loading…'; list.setAttribute('aria-busy', 'true');
            try {
                if (!this.#bootstrap.saveUrl || !this.#pageHistory && !this.#uid) {
                    this.#empty(list); count.textContent = 'Showing 0 of 0'; return;
                }
                const url = new URL(this.#bootstrap.saveUrl + (this.#pageHistory ? '/snapshot-history' : '/block-history'), location.origin);
                if (!this.#pageHistory) url.searchParams.set('uid', this.#uid); url.searchParams.set('page', page);
                const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal });
                const result = await window.QuillEditorResponse.read(response, 'Unable to load version history.');
                if (request.signal.aborted) return;
                const data = result.data;
                if (!Number.isSafeInteger(data?.total) || data.total < 0 || !Number.isSafeInteger(data.page) || data.page < 1 || !Array.isArray(data.revisions)) throw new Error('Invalid version history response.');
                this.#page = data.page;
                if (!data.revisions.length) this.#empty(list);
                for (const revision of data.revisions) {
                    const row = document.createElement('li');
                    const identity = document.createElement('div'); identity.className = 'editor-block-history-author';
                    const author = revision.author;
                    const name = [author?.firstname, author?.lastname].filter(Boolean).join(' ').trim() || `Admin #${revision.admin_id}`;
                    if (typeof author?.profile_picture === 'string' && author.profile_picture.trim()) {
                        const url = new URL(author.profile_picture, new URL('/', location.href));
                        if (['http:', 'https:'].includes(url.protocol)) {
                            const picture = document.createElement('img'); picture.className = 'editor-block-history-picture';
                            picture.src = url.href; picture.alt = ''; picture.width = picture.height = 32; picture.referrerPolicy = 'no-referrer';
                            picture.addEventListener('error', () => picture.remove(), { once: true }); identity.append(picture);
                        }
                    }
                    const details = document.createElement('div');
                    const message = document.createElement('p');
                    const title = document.createElement('strong'); title.textContent = name;
                    const time = document.createElement('time'); time.dateTime = String(revision.created_at).replace(' ', 'T'); time.textContent = revision.created_at;
                    message.append(title, document.createTextNode(' has changed this on '), time);
                    const version = document.createElement('small'); version.textContent = `Version ${revision.revision}`;
                    details.append(message, version); identity.append(details);
                    const restore = document.createElement('button'); restore.type = 'button'; restore.className = 'editor-action-icon';
                    restore.title = restore.ariaLabel = `Restore version ${revision.revision}`;
                    const icon = document.createElement('i'); icon.className = 'fa-solid fa-rotate-left'; icon.setAttribute('aria-hidden', 'true'); restore.append(icon);
                    restore.addEventListener('click', async () => {
                        try {
                            if (this.#pageHistory) {
                                const revisionBefore = this.#editor.state.revision;
                                restore.disabled = true;
                                const url = new URL(this.#bootstrap.saveUrl + '/snapshot-history', location.origin);
                                url.searchParams.set('snapshot', revision.id);
                                const response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: request.signal});
                                const result = await window.QuillEditorResponse.read(response, 'Unable to load this snapshot.');
                                if (request.signal.aborted) return;
                                if (this.#editor.state.revision !== revisionBefore) throw new Error('The document changed while loading. Try restoring again.');
                                this.#editor.state.restoreDocument(result.data.document);
                                this.#dialog.close(); this.#editor.notify?.(`Version ${revision.revision} restored. Save or publish to keep it.`);
                                return;
                            }
                            const current = this.#editor.getBlock(this.#path);
                            if (this.#identity(current) !== this.#uid) throw new Error('The selected block changed. Reopen its history.');
                            // Older revisions omit children and restore only the block’s own properties.
                            this.#editor.state.updateBlock(this.#path, revision.block, 'block-history');
                            this.#dialog.close(); this.#editor.notify?.(`Version ${revision.revision} restored. Save to keep it.`);
                        } catch (error) { if (!request.signal.aborted) this.#editor.reportError(error); }
                        finally { restore.disabled = false; }
                    });
                    row.append(identity, restore); list.append(row);
                }
                const start = data.total ? (data.page - 1) * 10 + 1 : 0;
                const end = Math.min(data.page * 10, data.total);
                count.textContent = `Showing ${start === end ? end : `${start}–${end}`} of ${data.total}`;
                previous.disabled = data.page <= 1; next.disabled = end >= data.total;
            } catch (error) {
                if (request.signal.aborted) return;
                const message = document.createElement('li'); message.textContent = error.message; list.append(message);
                const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Try again';
                retry.addEventListener('click', () => this.#load(page)); message.append(retry); count.textContent = 'History unavailable';
            } finally { if (this.#request === request) list.removeAttribute('aria-busy'); }
        }
        #empty(list) {
            const row = document.createElement('li'); row.textContent = this.#pageHistory ? 'No snapshots yet. Publish this page to create its first snapshot.' : 'No saved changes yet. Save the document to begin this block’s history.'; list.append(row);
        }
    }
    window.QuillEditorBlockHistory = QuillEditorBlockHistory;
})();
