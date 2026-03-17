export function createOperationsModule(deps) {
    const {
        state,
        api,
        API,
        escHtml,
        toast,
        promptInput,
        confirm_,
        showModal,
        closeModal,
        setLoading,
        hideContextMenu,
        navigate,
        renderFileList,
        updateStatusBar,
        updateSelectionUI,
        selectOnly,
        selectAll,
    } = deps;

    function openItem(item) {
        if (item.is_dir) {
            navigate(item.path);
        } else if (item.is_image || item.is_video || item.is_audio) {
            previewFile(item);
        } else if (item.ext === 'pdf') {
            previewFile(item);
        } else if (item.editable) {
            editFile(item);
        } else {
            downloadFile(item.path);
        }
    }

    function downloadFile(path) {
        const url = `${API}?action=download&path=${encodeURIComponent(path)}`;
        const a = document.createElement('a');
        a.href = url;
        a.download = '';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    async function uploadFiles(files, path) {
        if (!files || files.length === 0) return;

        const progressEl = document.getElementById('upload-progress');
        const fillEl = document.getElementById('upload-fill');
        const textEl = document.getElementById('upload-text');
        progressEl.classList.remove('hidden');
        fillEl.style.width = '0%';
        textEl.textContent = `0 / ${files.length} files`;

        const formData = new FormData();
        formData.append('path', path || state.path);
        formData.append('_csrf', state.csrf);
        for (const f of files) {
            formData.append('files[]', f);
        }

        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', `${API}?action=upload`, true);
            xhr.setRequestHeader('X-CSRF-Token', state.csrf);

            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    fillEl.style.width = pct + '%';
                    textEl.textContent = pct + '%';
                }
            };

            const result = await new Promise((resolve, reject) => {
                xhr.onload = () => {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        resolve(data);
                    } catch {
                        reject(new Error('Upload response parse error'));
                    }
                };
                xhr.onerror = () => reject(new Error('Upload failed'));
                xhr.send(formData);
            });

            if (result.errors && result.errors.length > 0) {
                result.errors.forEach(e => toast(e, 'error'));
            }
            if (result.count > 0) {
                toast(`${result.count} file${result.count > 1 ? 's' : ''} uploaded.`, 'success');
            }
            navigate(state.path);
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setTimeout(() => progressEl.classList.add('hidden'), 2000);
        }
    }

    async function createFolder() {
        const name = await promptInput('New Folder', 'Folder name:', 'New Folder');
        if (!name) return;
        try {
            await api('mkdir', { method: 'POST', body: { path: state.path, name } });
            toast('Folder created.', 'success');
            navigate(state.path);
        } catch (err) { toast(err.message, 'error'); }
    }

    async function createFile() {
        const name = await promptInput('New File', 'File name:', 'untitled.txt');
        if (!name) return;
        try {
            await api('mkfile', { method: 'POST', body: { path: state.path, name } });
            toast('File created.', 'success');
            navigate(state.path);
        } catch (err) { toast(err.message, 'error'); }
    }

    async function renameItem(item) {
        const name = await promptInput('Rename', 'New name:', item.name);
        if (!name || name === item.name) return;
        try {
            await api('rename', { method: 'POST', body: { path: item.path, name } });
            toast('Renamed.', 'success');
            navigate(state.path);
        } catch (err) { toast(err.message, 'error'); }
    }

    async function deleteItems(paths, permanent = false) {
        const count = paths.length;
        const label = count === 1 ? `"${paths[0].split('/').pop()}"` : `${count} items`;
        const msg = permanent ? `Permanently delete ${label}? This cannot be undone.` : `Delete ${label}?`;

        if (!await confirm_(msg, permanent ? 'Delete Permanently' : 'Delete')) return;

        try {
            if (count === 1) {
                await api('delete', { method: 'POST', body: { path: paths[0], permanent } });
            } else {
                await api('bulk_delete', { method: 'POST', body: { paths, permanent } });
            }
            toast(`${count} item${count > 1 ? 's' : ''} deleted.`, 'success');
            state.selected.clear();
            navigate(state.path);
        } catch (err) { toast(err.message, 'error'); }
    }

    async function moveItems(paths, dest) {
        try {
            await api('move', { method: 'POST', body: { from: paths, to: dest } });
            toast('Moved.', 'success');
            state.clipboard = null;
            navigate(state.path);
        } catch (err) { toast(err.message, 'error'); }
    }

    async function copyItems(paths, dest) {
        try {
            await api('copy', { method: 'POST', body: { from: paths, to: dest } });
            toast('Copied.', 'success');
            state.clipboard = null;
            navigate(state.path);
        } catch (err) { toast(err.message, 'error'); }
    }

    const MONACO_CDN = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min';
    let monacoReady = null;

    function loadMonaco() {
        if (monacoReady) return monacoReady;
        monacoReady = new Promise((resolve, reject) => {
            if (window.monaco) { resolve(window.monaco); return; }
            const loaderScript = document.createElement('script');
            loaderScript.src = `${MONACO_CDN}/vs/loader.js`;
            loaderScript.onload = () => {
                window.require.config({ paths: { vs: `${MONACO_CDN}/vs` } });
                window.require(['vs/editor/editor.main'], () => resolve(window.monaco), reject);
            };
            loaderScript.onerror = () => reject(new Error('Failed to load Monaco Editor'));
            document.head.appendChild(loaderScript);
        });
        return monacoReady;
    }

    function getMonacoTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'vs-dark' : 'vs';
    }

    function getLanguageForFile(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const map = {
            js: 'javascript', mjs: 'javascript', cjs: 'javascript',
            ts: 'typescript', tsx: 'typescript', jsx: 'javascript',
            json: 'json', jsonc: 'json',
            html: 'html', htm: 'html',
            css: 'css', scss: 'scss', less: 'less',
            php: 'php', py: 'python', rb: 'ruby',
            java: 'java', c: 'c', cpp: 'cpp', h: 'c', hpp: 'cpp',
            cs: 'csharp', go: 'go', rs: 'rust', swift: 'swift', kt: 'kotlin',
            lua: 'lua', r: 'r', dart: 'dart',
            sh: 'shell', bash: 'shell', zsh: 'shell',
            sql: 'sql', xml: 'xml', svg: 'xml',
            yaml: 'yaml', yml: 'yaml', toml: 'ini',
            md: 'markdown', markdown: 'markdown',
            txt: 'plaintext', log: 'plaintext', ini: 'ini', cfg: 'ini', conf: 'ini',
            env: 'plaintext', csv: 'plaintext',
            dockerfile: 'dockerfile',
        };
        return map[ext] || 'plaintext';
    }

    async function editFile(item) {
        try {
            const data = await api('read', { params: { path: item.path } });

            showModal(`Edit: ${item.name}`, `
                <div id="monaco-container" style="height:70vh;border:1px solid var(--border);border-radius:6px;overflow:hidden;">
                    <div style="height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)">Loading editor...</div>
                </div>
            `, [
                { label: 'Cancel', cls: '', action: () => { editorInstance = null; closeModal(); } },
                {
                    label: 'Save', cls: 'btn-primary', action: async () => {
                        if (!editorInstance) return;
                        const content = editorInstance.getValue();
                        try {
                            await api('save', { method: 'POST', body: { path: item.path, content } });
                            toast('File saved.', 'success');
                            editorInstance = null;
                            closeModal();
                        } catch (err) { toast(err.message, 'error'); }
                    }
                },
            ], 'modal-xl');

            let editorInstance = null;
            try {
                const monaco = await loadMonaco();
                const container = document.getElementById('monaco-container');
                if (!container) return;
                container.innerHTML = '';

                editorInstance = monaco.editor.create(container, {
                    value: data.content,
                    language: getLanguageForFile(item.name),
                    theme: getMonacoTheme(),
                    automaticLayout: true,
                    minimap: { enabled: false },
                    fontSize: 14,
                    lineNumbers: 'on',
                    scrollBeyondLastLine: false,
                    wordWrap: 'on',
                    tabSize: 4,
                    insertSpaces: true,
                    renderWhitespace: 'selection',
                });

                editorInstance.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
                    document.querySelector('.modal-footer .btn-primary')?.click();
                });

                // Sync theme when toggled
                const themeObserver = new MutationObserver(() => {
                    if (editorInstance) {
                        monaco.editor.setTheme(getMonacoTheme());
                    }
                });
                themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

                // Clean up observer when modal closes
                const overlay = document.getElementById('modal-overlay');
                const cleanup = new MutationObserver(() => {
                    if (overlay.classList.contains('hidden')) {
                        themeObserver.disconnect();
                        cleanup.disconnect();
                        editorInstance = null;
                    }
                });
                cleanup.observe(overlay, { attributes: true, attributeFilter: ['class'] });

                editorInstance.focus();
            } catch {
                // Fallback to textarea if Monaco fails to load
                const container = document.getElementById('monaco-container');
                if (container) {
                    container.innerHTML = `<textarea class="editor-textarea" id="editor-content" spellcheck="false" style="width:100%;height:100%;border:none;resize:none;padding:12px;font-family:monospace;font-size:14px;">${escHtml(data.content)}</textarea>`;
                    const textarea = document.getElementById('editor-content');
                    if (textarea) {
                        textarea.addEventListener('keydown', (e) => {
                            if (e.key === 'Tab') {
                                e.preventDefault();
                                const s = textarea.selectionStart, end = textarea.selectionEnd;
                                textarea.value = textarea.value.substring(0, s) + '    ' + textarea.value.substring(end);
                                textarea.selectionStart = textarea.selectionEnd = s + 4;
                            }
                            if (e.ctrlKey && e.key === 's') {
                                e.preventDefault();
                                document.querySelector('.modal-footer .btn-primary')?.click();
                            }
                        });
                        textarea.focus();
                    }
                }
                toast('Monaco Editor unavailable, using fallback editor.', 'warning');
            }
        } catch (err) { toast(err.message, 'error'); }
    }

    function previewFile(item) {
        const url = `${API}?action=preview&path=${encodeURIComponent(item.path)}`;
        let content = '';

        if (item.is_image) {
            content = `<img class="preview-image" src="${url}" alt="${escHtml(item.name)}">`;
        } else if (item.is_video) {
            content = `<video class="preview-video" src="${url}" controls autoplay></video>`;
        } else if (item.is_audio) {
            content = `<div style="text-align:center;padding:40px 0">
                <svg viewBox="0 0 24 24" width="64" height="64" style="color:var(--primary);margin-bottom:20px"><path d="M9 18V5l12-2v13" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="6" cy="18" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="18" cy="16" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                <audio class="preview-audio" src="${url}" controls autoplay style="width:100%"></audio>
            </div>`;
        } else if (item.ext === 'pdf') {
            content = `<iframe src="${url}" style="width:100%;height:70vh;border:none;border-radius:var(--radius-sm)"></iframe>`;
        }

        showModal(item.name, content, [
            { label: 'Download', cls: '', action: () => downloadFile(item.path) },
            { label: 'Close', cls: 'btn-primary', action: closeModal },
        ], item.ext === 'pdf' ? 'modal-xl' : 'modal-lg');
    }

    async function showFileInfo(item) {
        try {
            const data = await api('info', { params: { path: item.path } });
            const html = `<div class="info-grid">
                <div class="info-label">Name</div><div class="info-value">${escHtml(data.name)}</div>
                <div class="info-label">Path</div><div class="info-value">${escHtml(data.path)}</div>
                <div class="info-label">Type</div><div class="info-value">${data.is_dir ? 'Directory' : escHtml(data.mime)}</div>
                <div class="info-label">Size</div><div class="info-value">${escHtml(data.size_human)}</div>
                ${data.item_count !== undefined ? `<div class="info-label">Items</div><div class="info-value">${data.item_count}</div>` : ''}
                <div class="info-label">Modified</div><div class="info-value">${escHtml(data.modified)}</div>
                <div class="info-label">Created</div><div class="info-value">${escHtml(data.created)}</div>
                <div class="info-label">Permissions</div><div class="info-value">${escHtml(data.perms)}</div>
                <div class="info-label">Readable</div><div class="info-value">${data.readable ? 'Yes' : 'No'}</div>
                <div class="info-label">Writable</div><div class="info-value">${data.writable ? 'Yes' : 'No'}</div>
            </div>`;
            showModal('Properties', html, [
                { label: 'Close', cls: 'btn-primary', action: closeModal },
            ]);
        } catch (err) { toast(err.message, 'error'); }
    }

    async function extractArchive(item) {
        if (!item || item.is_dir || item.ext !== 'zip') {
            toast('Only ZIP files can be extracted.', 'warning');
            return;
        }

        try {
            await api('extract', { method: 'POST', body: { path: item.path } });
            toast('Archive extracted.', 'success');
            navigate(state.path);
        } catch (err) { toast(err.message, 'error'); }
    }

    async function extractSelectedArchives() {
        const selectedPaths = [...state.selected];
        if (selectedPaths.length === 0) {
            toast('Select one or more ZIP files first.', 'warning');
            return;
        }

        const zipPaths = selectedPaths.filter((path) => {
            const item = state.items.find((i) => i.path === path);
            return item && !item.is_dir && item.ext === 'zip';
        });

        if (zipPaths.length === 0) {
            toast('Only ZIP files can be extracted.', 'warning');
            return;
        }

        let extracted = 0;
        let failed = 0;

        for (const path of zipPaths) {
            try {
                await api('extract', { method: 'POST', body: { path } });
                extracted++;
            } catch (err) {
                failed++;
                const name = path.split('/').pop() || path;
                toast(`${name}: ${err.message}`, 'error');
            }
        }

        if (extracted > 0) {
            toast(`Extracted ${extracted} ZIP file${extracted === 1 ? '' : 's'}.`, 'success');
        }
        if (failed > 0) {
            toast(`${failed} ZIP file${failed === 1 ? '' : 's'} failed to extract.`, 'warning');
        }

        navigate(state.path);
    }

    async function compressItems(paths) {
        const name = await promptInput('Compress', 'Archive name:', 'archive');
        if (!name) return;
        try {
            await api('compress', { method: 'POST', body: { paths, name } });
            toast('Archive created.', 'success');
            navigate(state.path);
        } catch (err) { toast(err.message, 'error'); }
    }

    let searchTimer = null;
    function handleSearch(query) {
        clearTimeout(searchTimer);
        const clearBtn = document.getElementById('search-clear');

        if (!query || query.length < 2) {
            clearBtn.classList.add('hidden');
            if (state.searchMode) {
                state.searchMode = false;
                navigate(state.path);
            }
            return;
        }
        clearBtn.classList.remove('hidden');

        searchTimer = setTimeout(async () => {
            state.searchMode = true;
            setLoading(true);
            try {
                const data = await api('search', { params: { query, path: state.path } });
                state.items = data.results || [];
                renderFileList();
                updateStatusBar();
                if (data.truncated) toast('Results truncated. Try a more specific query.', 'warning');
            } catch (err) {
                toast(err.message, 'error');
            } finally {
                setLoading(false);
            }
        }, 300);
    }

    function clipCopy() {
        const paths = [...state.selected];
        if (paths.length === 0) return;
        state.clipboard = { mode: 'copy', paths };
        toast(`${paths.length} item${paths.length > 1 ? 's' : ''} copied.`, 'info');
        updateSelectionUI();
        renderFileList();
    }

    function clipCut() {
        const paths = [...state.selected];
        if (paths.length === 0) return;
        state.clipboard = { mode: 'cut', paths };
        toast(`${paths.length} item${paths.length > 1 ? 's' : ''} cut.`, 'info');
        updateSelectionUI();
        renderFileList();
    }

    async function clipPaste() {
        if (!state.clipboard) return;
        const { mode, paths } = state.clipboard;
        if (mode === 'cut') {
            await moveItems(paths, state.path);
        } else {
            await copyItems(paths, state.path);
        }
    }

    function showContextMenu(e, item, x, y) {
        const menu = document.getElementById('context-menu');
        menu.innerHTML = '';

        const items = [];

        if (item) {
            if (item.is_dir) {
                items.push({ label: 'Open', icon: 'folder', action: () => openItem(item) });
            } else if (item.editable) {
                items.push({ label: 'Edit', icon: 'file', action: () => editFile(item) });
            }
            if (item.is_image || item.is_video || item.is_audio) {
                items.push({ label: 'Preview', icon: 'file', action: () => previewFile(item) });
            }
            if (item.ext === 'pdf') {
                items.push({ label: 'Preview', icon: 'file', action: () => previewFile(item) });
            }

            items.push({ label: 'Download', icon: 'download', kbd: '', action: () => downloadFile(item.path) });
            items.push('---');
            items.push({ label: 'Rename', icon: 'rename', kbd: 'F2', action: () => renameItem(item) });
            if (state.selected.size > 1) {
                items.push({ label: 'Batch Rename', icon: 'rename', kbd: 'Ctrl+R', action: batchRename });
            }
            items.push({ label: 'Copy', icon: 'copy', kbd: 'Ctrl+C', action: clipCopy });
            items.push({ label: 'Cut', icon: 'cut', kbd: 'Ctrl+X', action: clipCut });

            if (item.ext === 'zip') {
                items.push('---');
                items.push({ label: 'Extract', icon: 'archive', action: () => extractArchive(item) });
            }

            items.push('---');
            items.push({ label: 'Properties', icon: 'info', action: () => showFileInfo(item) });
            items.push('---');
            items.push({
                label: 'Delete', icon: 'delete', cls: 'text-danger', action: () => {
                    const paths = state.selected.size > 0 ? [...state.selected] : [item.path];
                    deleteItems(paths);
                }
            });
        } else {
            if (state.clipboard) {
                items.push({ label: 'Paste', icon: 'paste', kbd: 'Ctrl+V', action: clipPaste });
                items.push('---');
            }
            items.push({ label: 'New Folder', icon: 'folder', action: createFolder });
            items.push({ label: 'New File', icon: 'file', action: createFile });
            items.push({ label: 'Upload', icon: 'upload', action: () => document.getElementById('file-input').click() });
            items.push({ label: 'Upload Folder', icon: 'upload', action: uploadFolder });
            items.push('---');
            items.push({ label: 'Refresh', icon: 'refresh', action: () => navigate(state.path) });
            if (state.items.length > 0) {
                items.push({ label: 'Select All', icon: 'selectall', kbd: 'Ctrl+A', action: selectAll });
            }
        }

        const ctxIcons = {
            folder: '<svg viewBox="0 0 24 24"><path d="M2 9a2 2 0 012-2h5l2 2h7a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2Z" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
            file: '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
            download: '<svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            rename: '<svg viewBox="0 0 24 24"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5Z" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
            copy: '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
            cut: '<svg viewBox="0 0 24 24"><circle cx="6" cy="6" r="3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="6" cy="18" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 4L8.12 15.88M14.47 14.48L20 20M8.12 8.12L12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
            paste: '<svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2" fill="none" stroke="currentColor" stroke-width="2"/><rect x="8" y="2" width="8" height="4" rx="1" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
            delete: '<svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m1 0v14a2 2 0 01-2 2H9a2 2 0 01-2-2V6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
            info: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 16v-4M12 8h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
            upload: '<svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            refresh: '<svg viewBox="0 0 24 24"><path d="M1 4v6h6M23 20v-6h-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.49 9A9 9 0 005.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 013.51 15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            selectall: '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M9 12l2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            archive: '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        };

        for (const entry of items) {
            if (entry === '---') {
                menu.appendChild(Object.assign(document.createElement('div'), { className: 'context-menu-sep' }));
                continue;
            }
            const btn = document.createElement('button');
            btn.className = 'context-menu-item' + (entry.cls ? ' ' + entry.cls : '');
            btn.innerHTML = (ctxIcons[entry.icon] || '') + ` <span>${escHtml(entry.label)}</span>` +
                (entry.kbd ? `<kbd>${entry.kbd}</kbd>` : '');
            btn.addEventListener('click', () => { hideContextMenu(); entry.action(); });
            menu.appendChild(btn);
        }

        const posX = x ?? e.clientX;
        const posY = y ?? e.clientY;
        menu.style.left = posX + 'px';
        menu.style.top = posY + 'px';
        menu.classList.remove('hidden');

        requestAnimationFrame(() => {
            const rect = menu.getBoundingClientRect();
            if (rect.right > window.innerWidth) menu.style.left = (window.innerWidth - rect.width - 8) + 'px';
            if (rect.bottom > window.innerHeight) menu.style.top = (window.innerHeight - rect.height - 8) + 'px';
        });
    }

    function showShortcutsHelp() {
        const shortcuts = [
            ['Ctrl+A', 'Select all items'],
            ['Ctrl+C', 'Copy selected'],
            ['Ctrl+X', 'Cut selected'],
            ['Ctrl+V', 'Paste'],
            ['Ctrl+F', 'Focus search'],
            ['Ctrl+U', 'Upload files'],
            ['Delete', 'Delete selected'],
            ['F2', 'Rename selected'],
            ['F5', 'Refresh'],
            ['Enter', 'Open selected'],
            ['Escape', 'Close / Deselect'],
            ['Backspace', 'Go up one level'],
            ['Shift+N', 'New folder'],
            ['\u2191 / \u2193', 'Navigate items'],
            ['?', 'Show this help'],
        ];

        const html = `<div style="display:grid;grid-template-columns:auto 1fr;gap:10px 20px;align-items:center">
            ${shortcuts.map(([key, desc]) => `
                <kbd style="background:var(--bg);padding:4px 10px;border-radius:var(--radius-sm);border:1px solid var(--border);font-family:var(--font);font-size:.8rem;font-weight:600;text-align:center;min-width:60px">${escHtml(key)}</kbd>
                <span style="font-size:.9rem;color:var(--text-secondary)">${escHtml(desc)}</span>
            `).join('')}
        </div>`;

        showModal('Keyboard Shortcuts', html, [
            { label: 'Close', cls: 'btn-primary', action: closeModal },
        ]);
    }

    function uploadFolder() {
        const input = document.createElement('input');
        input.type = 'file';
        input.webkitdirectory = true;
        input.multiple = true;
        input.addEventListener('change', () => {
            if (input.files.length > 0) {
                uploadFilesWithPaths(input.files, state.path);
            }
        });
        input.click();
    }

    async function uploadFilesWithPaths(files, basePath) {
        if (!files || files.length === 0) return;

        const progressEl = document.getElementById('upload-progress');
        const fillEl = document.getElementById('upload-fill');
        const textEl = document.getElementById('upload-text');
        progressEl.classList.remove('hidden');
        fillEl.style.width = '0%';
        textEl.textContent = `0 / ${files.length} files`;

        const formData = new FormData();
        formData.append('path', basePath || state.path);
        formData.append('_csrf', state.csrf);
        formData.append('preserve_paths', '1');
        for (const f of files) {
            formData.append('files[]', f);
            formData.append('relative_paths[]', f.webkitRelativePath || f.name);
        }

        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', `${API}?action=upload`, true);
            xhr.setRequestHeader('X-CSRF-Token', state.csrf);

            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    fillEl.style.width = pct + '%';
                    textEl.textContent = `${pct}% (${files.length} files)`;
                }
            };

            const result = await new Promise((resolve, reject) => {
                xhr.onload = () => {
                    try { resolve(JSON.parse(xhr.responseText)); }
                    catch { reject(new Error('Upload response parse error')); }
                };
                xhr.onerror = () => reject(new Error('Upload failed'));
                xhr.send(formData);
            });

            if (result.errors && result.errors.length > 0) {
                result.errors.forEach(e => toast(e, 'error'));
            }
            if (result.count > 0) {
                toast(`${result.count} file${result.count > 1 ? 's' : ''} uploaded.`, 'success');
            }
            navigate(state.path);
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setTimeout(() => progressEl.classList.add('hidden'), 2000);
        }
    }

    async function batchRename() {
        const paths = [...state.selected];
        if (paths.length < 2) return;

        const html = `
            <p style="margin-bottom:16px;color:var(--text-secondary);font-size:.9rem">Rename ${paths.length} items using a pattern.</p>
            <div class="form-group">
                <label>Pattern</label>
                <input type="text" id="batch-pattern" value="file_{n}" placeholder="file_{n}">
                <div style="font-size:.78rem;color:var(--text-muted);margin-top:4px">
                    Use <code>{n}</code> for sequence number, <code>{name}</code> for original name, <code>{ext}</code> for extension
                </div>
            </div>
            <div class="form-group">
                <label>Start number</label>
                <input type="number" id="batch-start" value="1" min="0" style="width:100px">
            </div>
            <div style="margin-top:12px;padding:12px;background:var(--bg);border-radius:var(--radius-sm);font-size:.85rem">
                <strong>Preview:</strong>
                <div id="batch-preview" style="margin-top:8px;max-height:150px;overflow-y:auto"></div>
            </div>
        `;

        showModal('Batch Rename', html, [
            { label: 'Cancel', cls: '', action: closeModal },
            {
                label: 'Rename All', cls: 'btn-primary', action: async () => {
                    const pattern = document.getElementById('batch-pattern').value.trim();
                    const startNum = parseInt(document.getElementById('batch-start').value) || 1;
                    if (!pattern) { toast('Pattern is required.', 'error'); return; }

                    closeModal();
                    let success = 0;
                    for (let i = 0; i < paths.length; i++) {
                        const item = state.items.find(it => it.path === paths[i]);
                        if (!item) continue;

                        const ext = item.name.includes('.') ? item.name.substring(item.name.lastIndexOf('.')) : '';
                        const nameNoExt = item.name.includes('.') ? item.name.substring(0, item.name.lastIndexOf('.')) : item.name;
                        const newName = pattern
                            .replace(/\{n\}/g, String(startNum + i).padStart(String(startNum + paths.length).length, '0'))
                            .replace(/\{name\}/g, nameNoExt)
                            .replace(/\{ext\}/g, ext.replace('.', ''));
                        const finalName = newName.includes('.') ? newName : newName + ext;

                        try {
                            await api('rename', { method: 'POST', body: { path: item.path, name: finalName } });
                            success++;
                        } catch {
                            // continue
                        }
                    }
                    toast(`${success} of ${paths.length} items renamed.`, 'success');
                    state.selected.clear();
                    navigate(state.path);
                }
            },
        ]);

        function updatePreview() {
            const pattern = document.getElementById('batch-pattern')?.value || 'file_{n}';
            const startNum = parseInt(document.getElementById('batch-start')?.value) || 1;
            const preview = document.getElementById('batch-preview');
            if (!preview) return;

            preview.innerHTML = paths.slice(0, 10).map((p, i) => {
                const item = state.items.find(it => it.path === p);
                if (!item) return '';
                const ext = item.name.includes('.') ? item.name.substring(item.name.lastIndexOf('.')) : '';
                const nameNoExt = item.name.includes('.') ? item.name.substring(0, item.name.lastIndexOf('.')) : item.name;
                const newName = pattern
                    .replace(/\{n\}/g, String(startNum + i).padStart(String(startNum + paths.length).length, '0'))
                    .replace(/\{name\}/g, nameNoExt)
                    .replace(/\{ext\}/g, ext.replace('.', ''));
                const finalName = newName.includes('.') ? newName : newName + ext;
                return `<div style="display:flex;gap:8px;padding:2px 0"><span style="color:var(--text-muted);text-decoration:line-through">${escHtml(item.name)}</span> <span>\u2192</span> <span style="color:var(--primary)">${escHtml(finalName)}</span></div>`;
            }).join('') + (paths.length > 10 ? `<div style="color:var(--text-muted);padding-top:4px">...and ${paths.length - 10} more</div>` : '');
        }

        setTimeout(() => {
            updatePreview();
            document.getElementById('batch-pattern')?.addEventListener('input', updatePreview);
            document.getElementById('batch-start')?.addEventListener('input', updatePreview);
            document.getElementById('batch-pattern')?.focus();
        }, 100);
    }

    function enableFileDragMove(el, item) {
        if (!item.is_dir) {
            el.setAttribute('draggable', 'true');
            el.addEventListener('dragstart', (e) => {
                if (state.selected.size === 0 || !state.selected.has(item.path)) {
                    selectOnly(item.path);
                }
                const paths = [...state.selected];
                e.dataTransfer.setData('application/fm-paths', JSON.stringify(paths));
                e.dataTransfer.effectAllowed = 'move';
                el.classList.add('cut');
            });
            el.addEventListener('dragend', () => {
                el.classList.remove('cut');
            });
        }

        if (item.is_dir) {
            el.addEventListener('dragover', (e) => {
                const data = e.dataTransfer.types.includes('application/fm-paths');
                if (data) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    el.style.outline = '2px solid var(--primary)';
                    el.style.outlineOffset = '-2px';
                }
            });
            el.addEventListener('dragleave', () => {
                el.style.outline = '';
                el.style.outlineOffset = '';
            });
            el.addEventListener('drop', async (e) => {
                el.style.outline = '';
                el.style.outlineOffset = '';
                const raw = e.dataTransfer.getData('application/fm-paths');
                if (!raw) return;
                e.preventDefault();
                e.stopPropagation();
                try {
                    const paths = JSON.parse(raw);
                    await moveItems(paths, item.path);
                } catch (err) {
                    toast(err.message, 'error');
                }
            });
        }
    }

    return {
        openItem,
        downloadFile,
        uploadFiles,
        createFolder,
        createFile,
        renameItem,
        deleteItems,
        moveItems,
        copyItems,
        editFile,
        previewFile,
        showFileInfo,
        extractArchive,
        extractSelectedArchives,
        compressItems,
        handleSearch,
        clipCopy,
        clipCut,
        clipPaste,
        showContextMenu,
        showShortcutsHelp,
        uploadFolder,
        uploadFilesWithPaths,
        batchRename,
        enableFileDragMove,
    };
}
