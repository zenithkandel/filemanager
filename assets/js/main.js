/**
 * FileManager — Frontend Application
 * Vanilla JavaScript, no dependencies.
 */
(function () {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════
    //  CONFIGURATION & STATE
    // ═══════════════════════════════════════════════════════════════════════

    const API = 'api.php';

    const state = {
        path: '/',
        items: [],
        selected: new Set(),
        clipboard: null,       // { mode: 'copy'|'cut', paths: [] }
        sort: 'name',
        order: 'asc',
        view: 'list',          // 'list' | 'grid'
        user: '',
        role: '',
        csrf: '',
        settings: {},
        searchMode: false,
        loading: false,
    };

    // SVG icon templates (small inline SVGs for file types)
    const ICONS = {
        folder: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M2 9a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2Z" fill="currentColor"/></svg>',
        file: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        image: '<svg viewBox="0 0 24 24" width="20" height="20"><rect x="3" y="3" width="18" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        video: '<svg viewBox="0 0 24 24" width="20" height="20"><rect x="2" y="4" width="15" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="m17 8 5-3v14l-5-3Z" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        audio: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M9 18V5l12-2v13" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="6" cy="18" r="3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="16" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        archive: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6" fill="none" stroke="currentColor" stroke-width="2"/><path d="M10 12h.01M10 15h.01M10 18h.01M10 9h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        code: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="m16 18 6-6-6-6M8 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        doc: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    };

    const CODE_EXTS = ['js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue', 'svelte', 'json', 'jsonc', 'xml', 'svg', 'html', 'htm', 'css', 'scss', 'sass', 'less', 'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rs', 'swift', 'kt', 'lua', 'r', 'dart', 'sh', 'bash', 'zsh', 'sql', 'yaml', 'yml', 'toml', 'makefile', 'dockerfile', 'asm'];
    const DOC_EXTS = ['txt', 'md', 'markdown', 'csv', 'log', 'ini', 'cfg', 'conf', 'env', 'properties', 'lock'];

    // ═══════════════════════════════════════════════════════════════════════
    //  API LAYER
    // ═══════════════════════════════════════════════════════════════════════

    async function api(action, opts = {}) {
        const { method = 'GET', body = null, params = {} } = opts;
        const url = new URL(API, window.location.href);
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) {
            url.searchParams.set(k, v);
        }

        const headers = {};
        let fetchBody = null;

        if (method === 'POST') {
            headers['X-CSRF-Token'] = state.csrf;
            if (body instanceof FormData) {
                fetchBody = body;
            } else if (body !== null) {
                headers['Content-Type'] = 'application/json';
                fetchBody = JSON.stringify(body);
            }
        }

        const resp = await fetch(url.toString(), { method, headers, body: fetchBody, credentials: 'same-origin' });
        const contentType = resp.headers.get('content-type') || '';

        if (contentType.includes('application/json')) {
            const data = await resp.json();
            if (!data.ok) {
                const errMsg = data.error || 'Unknown error';
                // Special case: re-auth required
                if (resp.status === 449) {
                    const pw = await promptReauth();
                    if (pw !== null) {
                        // Attempt reauth, then retry original action
                        const reauthResp = await api('reauth', { method: 'POST', body: { password: pw } });
                        if (reauthResp.ok) {
                            return api(action, opts); // Retry
                        }
                    }
                    throw new Error('Re-authentication cancelled.');
                }
                throw new Error(errMsg);
            }
            return data;
        }
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        return resp;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  INITIALIZATION
    // ═══════════════════════════════════════════════════════════════════════

    function init() {
        const app = document.getElementById('app');
        const loginScreen = document.getElementById('login-screen');

        // If already logged in (rendered server-side)
        if (app && !app.classList.contains('hidden')) {
            state.user = app.dataset.user;
            state.role = app.dataset.role;
            state.csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            try { state.settings = JSON.parse(app.dataset.settings || '{}'); } catch { state.settings = {}; }
            state.view = state.settings.default_view || 'list';
            initApp();
        }

        // Login form
        const loginForm = document.getElementById('login-form');
        if (loginForm) {
            loginForm.addEventListener('submit', handleLogin);
        }

        // Resolve theme
        resolveTheme();
    }

    function initApp() {
        bindEvents();
        updateUserUI();
        updateViewToggle();
        navigate(state.path);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AUTHENTICATION
    // ═══════════════════════════════════════════════════════════════════════

    async function handleLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('login-btn');
        const errEl = document.getElementById('login-error');
        const username = document.getElementById('login-user').value.trim();
        const password = document.getElementById('login-pass').value;

        if (!username || !password) return;

        btn.disabled = true;
        btn.querySelector('.btn-text').textContent = 'Signing in...';
        btn.querySelector('.btn-spinner').classList.remove('hidden');
        errEl.classList.add('hidden');

        try {
            const data = await api('login', { method: 'POST', body: { username, password } });
            state.user = data.user;
            state.role = data.role;
            state.csrf = data.csrf;
            state.settings = data.settings || {};
            state.view = state.settings.default_view || 'list';

            document.getElementById('login-screen').classList.add('hidden');
            document.getElementById('app').classList.remove('hidden');
            document.getElementById('app').dataset.user = state.user;
            document.getElementById('app').dataset.role = state.role;
            initApp();
        } catch (err) {
            errEl.textContent = err.message;
            errEl.classList.remove('hidden');
        } finally {
            btn.disabled = false;
            btn.querySelector('.btn-text').textContent = 'Sign In';
            btn.querySelector('.btn-spinner').classList.add('hidden');
        }
    }

    async function handleLogout() {
        try {
            await api('logout', { method: 'POST', body: {} });
        } catch { /* ignore */ }
        window.location.reload();
    }

    function promptReauth() {
        return new Promise(resolve => {
            showModal('Re-authenticate', `
                <p style="margin-bottom:16px;color:var(--text-secondary)">This action requires password confirmation.</p>
                <div class="form-group">
                    <label for="reauth-pass">Password</label>
                    <input type="password" id="reauth-pass" autocomplete="current-password">
                </div>
            `, [
                { label: 'Cancel', cls: '', action: () => { closeModal(); resolve(null); } },
                {
                    label: 'Confirm', cls: 'btn-primary', action: () => {
                        const pw = document.getElementById('reauth-pass')?.value || '';
                        closeModal();
                        resolve(pw || null);
                    }
                },
            ]);
            setTimeout(() => document.getElementById('reauth-pass')?.focus(), 100);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  NAVIGATION & FILE LISTING
    // ═══════════════════════════════════════════════════════════════════════

    async function navigate(path) {
        state.path = normalizePath(path);
        state.searchMode = false;
        state.selected.clear();
        updateSelectionUI();

        setLoading(true);
        try {
            const data = await api('list', {
                params: { path: state.path, sort: state.sort, order: state.order }
            });
            state.items = data.items || [];
            renderFileList();
            renderBreadcrumb();
            updateStatusBar();
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setLoading(false);
        }
    }

    function normalizePath(p) {
        if (!p || p === '/') return '/';
        return p.replace(/\\/g, '/').replace(/\/+/g, '/').replace(/\/$/, '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  RENDERING
    // ═══════════════════════════════════════════════════════════════════════

    function renderFileList() {
        const container = document.getElementById('file-list');
        const emptyState = document.getElementById('empty-state');
        const listHeader = document.getElementById('list-header');

        container.innerHTML = '';
        container.className = state.view === 'grid' ? 'grid-view' : '';

        if (state.view === 'grid') {
            listHeader.style.display = 'none';
        } else {
            listHeader.style.display = '';
        }

        if (state.items.length === 0) {
            emptyState.classList.remove('hidden');
            return;
        }
        emptyState.classList.add('hidden');

        const fragment = document.createDocumentFragment();
        for (const item of state.items) {
            fragment.appendChild(createFileItem(item));
        }
        container.appendChild(fragment);
    }

    function createFileItem(item) {
        const el = document.createElement('div');
        el.className = 'file-item';
        el.dataset.path = item.path;
        el.dataset.name = item.name;
        el.dataset.isDir = item.is_dir ? '1' : '0';

        if (state.selected.has(item.path)) el.classList.add('selected');
        if (state.clipboard?.mode === 'cut' && state.clipboard.paths.includes(item.path)) {
            el.classList.add('cut');
        }

        const iconClass = getIconClass(item);
        const iconSvg = getIconSvg(item);
        const sizeStr = item.is_dir ? '--' : humanSize(item.size);
        const dateStr = item.modified ? formatDate(item.modified) : '--';

        // For grid view with images, add thumbnail
        let thumbHtml = '';
        if (state.view === 'grid' && item.is_image) {
            thumbHtml = `<img class="grid-thumb" src="${API}?action=preview&path=${encodeURIComponent(item.path)}" alt="" loading="lazy" onerror="this.style.display='none'">`;
        }

        el.innerHTML = `
            <div class="col-check"><input type="checkbox" class="item-check" tabindex="-1"></div>
            <div class="col-name">
                ${thumbHtml || `<div class="file-icon ${iconClass}">${iconSvg}</div>`}
                <span class="file-name" title="${escHtml(item.name)}">${escHtml(item.name)}</span>
            </div>
            <div class="col-size">${sizeStr}</div>
            <div class="col-modified">${dateStr}</div>
            <div class="col-actions">
                <button class="btn btn-icon btn-xs item-menu" title="Actions">
                    <svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="5" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="19" r="1.5" fill="currentColor"/></svg>
                </button>
            </div>
        `;

        // Check the checkbox if selected
        const chk = el.querySelector('.item-check');
        chk.checked = state.selected.has(item.path);

        // Events
        el.addEventListener('dblclick', (e) => {
            if (e.target.closest('.item-check') || e.target.closest('.item-menu')) return;
            openItem(item);
        });

        el.addEventListener('click', (e) => {
            if (e.target.closest('.item-check')) {
                toggleSelect(item.path, chk.checked);
                return;
            }
            if (e.target.closest('.item-menu')) {
                const btn = e.target.closest('.item-menu');
                const rect = btn.getBoundingClientRect();
                showContextMenu(e, item, rect.right, rect.bottom);
                return;
            }
            // Click on row: single select (or multi with Ctrl/Shift)
            if (e.ctrlKey || e.metaKey) {
                toggleSelect(item.path);
            } else if (e.shiftKey && state.items.length > 0) {
                rangeSelect(item.path);
            } else {
                selectOnly(item.path);
            }
        });

        el.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            if (!state.selected.has(item.path)) {
                selectOnly(item.path);
            }
            showContextMenu(e, item);
        });

        // Enable drag-to-move between folders
        enableFileDragMove(el, item);

        return el;
    }

    function getIconClass(item) {
        if (item.is_dir) return 'folder-icon';
        if (item.is_image) return 'image-icon';
        if (item.is_video) return 'video-icon';
        if (item.is_audio) return 'audio-icon';
        if (item.is_archive) return 'archive-icon';
        if (CODE_EXTS.includes(item.ext)) return 'code-icon';
        if (DOC_EXTS.includes(item.ext)) return 'doc-icon';
        return 'file-icon-default';
    }

    function getIconSvg(item) {
        if (item.is_dir) return ICONS.folder;
        if (item.is_image) return ICONS.image;
        if (item.is_video) return ICONS.video;
        if (item.is_audio) return ICONS.audio;
        if (item.is_archive) return ICONS.archive;
        if (CODE_EXTS.includes(item.ext)) return ICONS.code;
        if (DOC_EXTS.includes(item.ext)) return ICONS.doc;
        return ICONS.file;
    }

    function renderBreadcrumb() {
        const bc = document.getElementById('breadcrumb');
        bc.innerHTML = '';

        const parts = state.path === '/' ? [''] : state.path.split('/');

        // Root
        const rootLink = document.createElement('a');
        rootLink.href = '#';
        rootLink.textContent = 'Root';
        rootLink.dataset.path = '/';
        rootLink.addEventListener('click', (e) => { e.preventDefault(); navigate('/'); });
        bc.appendChild(rootLink);

        let cumulative = '';
        for (let i = 1; i < parts.length; i++) {
            if (!parts[i]) continue;
            cumulative += '/' + parts[i];

            const sep = document.createElement('span');
            sep.className = 'sep';
            sep.textContent = '/';
            bc.appendChild(sep);

            const link = document.createElement('a');
            link.href = '#';
            link.textContent = parts[i];
            const navPath = cumulative;
            link.addEventListener('click', (e) => { e.preventDefault(); navigate(navPath); });
            bc.appendChild(link);
        }
    }

    function updateStatusBar() {
        const info = document.getElementById('status-info');
        const pathEl = document.getElementById('status-path');

        const dirs = state.items.filter(i => i.is_dir).length;
        const files = state.items.length - dirs;
        const totalSize = state.items.reduce((s, i) => s + (i.is_dir ? 0 : i.size), 0);

        let text = `${state.items.length} item${state.items.length !== 1 ? 's' : ''}`;
        if (dirs > 0 && files > 0) text = `${dirs} folder${dirs !== 1 ? 's' : ''}, ${files} file${files !== 1 ? 's' : ''}`;
        if (totalSize > 0) text += ` (${humanSize(totalSize)})`;
        if (state.searchMode) text = `Search results: ${state.items.length} found`;

        info.textContent = text;
        pathEl.textContent = state.path === '/' ? '/' : state.path;
    }

    function updateUserUI() {
        const avatar = document.getElementById('user-avatar');
        const nameEl = document.getElementById('user-name-display');
        const roleEl = document.getElementById('dropdown-role');

        if (avatar) avatar.textContent = (state.user || '?')[0].toUpperCase();
        if (nameEl) nameEl.textContent = state.user;
        if (roleEl) roleEl.textContent = state.role === 'admin' ? 'Administrator' : 'User';

        // Show/hide admin items
        document.querySelectorAll('.admin-only').forEach(el => {
            el.style.display = state.role === 'admin' ? '' : 'none';
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  SELECTION
    // ═══════════════════════════════════════════════════════════════════════

    function toggleSelect(path, forceState) {
        if (forceState !== undefined) {
            forceState ? state.selected.add(path) : state.selected.delete(path);
        } else {
            state.selected.has(path) ? state.selected.delete(path) : state.selected.add(path);
        }
        updateSelectionUI();
    }

    function selectOnly(path) {
        state.selected.clear();
        state.selected.add(path);
        updateSelectionUI();
    }

    function selectAll() {
        state.items.forEach(i => state.selected.add(i.path));
        updateSelectionUI();
    }

    function selectNone() {
        state.selected.clear();
        updateSelectionUI();
    }

    function rangeSelect(path) {
        const paths = state.items.map(i => i.path);
        const last = [...state.selected].pop();
        const from = paths.indexOf(last);
        const to = paths.indexOf(path);
        if (from === -1 || to === -1) return;
        const [start, end] = from < to ? [from, to] : [to, from];
        for (let i = start; i <= end; i++) state.selected.add(paths[i]);
        updateSelectionUI();
    }

    function updateSelectionUI() {
        // Update checkboxes
        document.querySelectorAll('.file-item').forEach(el => {
            const path = el.dataset.path;
            const chk = el.querySelector('.item-check');
            const selected = state.selected.has(path);
            el.classList.toggle('selected', selected);
            if (chk) chk.checked = selected;
        });

        // Update select all
        const selectAllChk = document.getElementById('select-all');
        if (selectAllChk) {
            selectAllChk.checked = state.items.length > 0 && state.selected.size === state.items.length;
            selectAllChk.indeterminate = state.selected.size > 0 && state.selected.size < state.items.length;
        }

        // Show/hide selection actions
        const selActions = document.getElementById('selection-actions');
        const mainActions = document.getElementById('main-actions');
        const count = document.getElementById('sel-count');

        if (state.selected.size > 0) {
            selActions.style.display = '';
            count.textContent = `${state.selected.size} selected`;
        } else {
            selActions.style.display = 'none';
        }

        // Paste button
        const pasteBtn = document.getElementById('btn-paste');
        pasteBtn.style.display = state.clipboard ? '' : 'none';
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  FILE OPERATIONS
    // ═══════════════════════════════════════════════════════════════════════

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

    async function editFile(item) {
        try {
            const data = await api('read', { params: { path: item.path } });
            showModal(`Edit: ${item.name}`, `
                <textarea class="editor-textarea" id="editor-content" spellcheck="false">${escHtml(data.content)}</textarea>
            `, [
                { label: 'Cancel', cls: '', action: closeModal },
                {
                    label: 'Save', cls: 'btn-primary', action: async () => {
                        const content = document.getElementById('editor-content').value;
                        try {
                            await api('save', { method: 'POST', body: { path: item.path, content } });
                            toast('File saved.', 'success');
                            closeModal();
                        } catch (err) { toast(err.message, 'error'); }
                    }
                },
            ], 'modal-xl');

            // Enable tab key in textarea
            const textarea = document.getElementById('editor-content');
            if (textarea) {
                textarea.addEventListener('keydown', (e) => {
                    if (e.key === 'Tab') {
                        e.preventDefault();
                        const start = textarea.selectionStart;
                        const end = textarea.selectionEnd;
                        textarea.value = textarea.value.substring(0, start) + '    ' + textarea.value.substring(end);
                        textarea.selectionStart = textarea.selectionEnd = start + 4;
                    }
                    if (e.ctrlKey && e.key === 's') {
                        e.preventDefault();
                        document.querySelector('.modal-footer .btn-primary')?.click();
                    }
                });
                textarea.focus();
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
        try {
            const data = await api('extract', { method: 'POST', body: { path: item.path } });
            toast('Archive extracted.', 'success');
            navigate(state.path);
        } catch (err) { toast(err.message, 'error'); }
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

    // ═══════════════════════════════════════════════════════════════════════
    //  SEARCH
    // ═══════════════════════════════════════════════════════════════════════

    let searchTimer = null;
    function handleSearch(query) {
        clearTimeout(searchTimer);
        const clearBtn = document.getElementById('search-clear');

        if (!query || query.length < 1) {
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

    // ═══════════════════════════════════════════════════════════════════════
    //  CLIPBOARD (cut / copy / paste)
    // ═══════════════════════════════════════════════════════════════════════

    function clipCopy() {
        const paths = [...state.selected];
        if (paths.length === 0) return;
        state.clipboard = { mode: 'copy', paths };
        toast(`${paths.length} item${paths.length > 1 ? 's' : ''} copied.`, 'info');
        updateSelectionUI();
        renderFileList(); // update cut styling
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

    // ═══════════════════════════════════════════════════════════════════════
    //  CONTEXT MENU
    // ═══════════════════════════════════════════════════════════════════════

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

            if (item.is_archive) {
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
            // Background context menu
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

        // Position
        const posX = x ?? e.clientX;
        const posY = y ?? e.clientY;
        menu.style.left = posX + 'px';
        menu.style.top = posY + 'px';
        menu.classList.remove('hidden');

        // Adjust if offscreen
        requestAnimationFrame(() => {
            const rect = menu.getBoundingClientRect();
            if (rect.right > window.innerWidth) menu.style.left = (window.innerWidth - rect.width - 8) + 'px';
            if (rect.bottom > window.innerHeight) menu.style.top = (window.innerHeight - rect.height - 8) + 'px';
        });
    }

    function hideContextMenu() {
        document.getElementById('context-menu').classList.add('hidden');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TRASH
    // ═══════════════════════════════════════════════════════════════════════

    async function showTrash() {
        try {
            const data = await api('trash_list');
            const items = data.items || [];

            let html = '';
            if (items.length === 0) {
                html = '<p style="text-align:center;color:var(--text-muted);padding:40px">Trash is empty.</p>';
            } else {
                html = items.map(item => `
                    <div class="trash-item" data-trash-name="${escHtml(item.trash_name)}">
                        <svg viewBox="0 0 24 24" width="16" height="16" style="flex-shrink:0;color:var(--text-muted)">
                            ${item.is_dir ? '<path d="M2 9a2 2 0 012-2h5l2 2h7a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2Z" fill="none" stroke="currentColor" stroke-width="2"/>' : '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6" fill="none" stroke="currentColor" stroke-width="2"/>'}
                        </svg>
                        <span class="trash-item-name" title="${escHtml(item.original)}">${escHtml(item.original)}</span>
                        <span class="trash-item-date">${escHtml(item.deleted)}</span>
                        <button class="btn btn-sm trash-restore" data-name="${escHtml(item.trash_name)}">Restore</button>
                    </div>
                `).join('');
            }

            const footerBtns = [{ label: 'Close', cls: '', action: closeModal }];
            if (items.length > 0 && state.role === 'admin') {
                footerBtns.push({ label: 'Empty Trash', cls: 'btn-danger', action: emptyTrash });
            }

            showModal('Trash', html, footerBtns, 'modal-lg');

            // Bind restore buttons
            document.querySelectorAll('.trash-restore').forEach(btn => {
                btn.addEventListener('click', async () => {
                    try {
                        await api('trash_restore', { method: 'POST', body: { trash_name: btn.dataset.name } });
                        toast('Item restored.', 'success');
                        closeModal();
                        navigate(state.path);
                    } catch (err) { toast(err.message, 'error'); }
                });
            });
        } catch (err) { toast(err.message, 'error'); }
    }

    async function emptyTrash() {
        if (!await confirm_('Empty all items in trash? This cannot be undone.', 'Empty Trash')) return;
        try {
            await api('trash_empty', { method: 'POST', body: {} });
            toast('Trash emptied.', 'success');
            closeModal();
        } catch (err) { toast(err.message, 'error'); }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  USER MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════════

    async function showUsers() {
        try {
            const data = await api('users');
            const users = data.users || [];

            let html = users.map(u => `
                <div class="user-item">
                    <div class="avatar" style="width:32px;height:32px;font-size:.8rem">${u.username[0].toUpperCase()}</div>
                    <span class="user-item-name">${escHtml(u.username)}</span>
                    <span class="user-item-role">${escHtml(u.role)}</span>
                    ${u.username !== state.user ? `<button class="btn btn-sm btn-danger user-delete" data-username="${escHtml(u.username)}">Delete</button>` : ''}
                </div>
            `).join('');

            html += `<div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
                <h3 style="font-size:.95rem;margin-bottom:12px">Add User</h3>
                <div class="form-group"><label>Username</label><input type="text" id="new-username"></div>
                <div class="form-group"><label>Password</label><input type="password" id="new-password"></div>
                <div class="form-group"><label>Role</label>
                    <select id="new-role" style="width:100%;padding:8px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text)">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <button class="btn btn-primary btn-sm" id="btn-add-user">Add User</button>
            </div>`;

            showModal('Manage Users', html, [
                { label: 'Close', cls: 'btn-primary', action: closeModal },
            ]);

            // Bind events
            document.querySelectorAll('.user-delete').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!await confirm_(`Delete user "${btn.dataset.username}"?`, 'Delete')) return;
                    try {
                        await api('delete_user', { method: 'POST', body: { username: btn.dataset.username } });
                        toast('User deleted.', 'success');
                        showUsers(); // refresh
                    } catch (err) { toast(err.message, 'error'); }
                });
            });

            document.getElementById('btn-add-user')?.addEventListener('click', async () => {
                const username = document.getElementById('new-username').value.trim();
                const password = document.getElementById('new-password').value;
                const role = document.getElementById('new-role').value;
                if (!username || !password) { toast('Username and password required.', 'error'); return; }
                try {
                    await api('add_user', { method: 'POST', body: { username, password, role } });
                    toast('User added.', 'success');
                    showUsers();
                } catch (err) { toast(err.message, 'error'); }
            });
        } catch (err) { toast(err.message, 'error'); }
    }

    async function showChangePassword() {
        showModal('Change Password', `
            <div class="form-group"><label>Current Password</label><input type="password" id="cp-old"></div>
            <div class="form-group"><label>New Password</label><input type="password" id="cp-new"></div>
            <div class="form-group"><label>Confirm Password</label><input type="password" id="cp-confirm"></div>
        `, [
            { label: 'Cancel', cls: '', action: closeModal },
            {
                label: 'Change', cls: 'btn-primary', action: async () => {
                    const old = document.getElementById('cp-old').value;
                    const _new = document.getElementById('cp-new').value;
                    const confirm = document.getElementById('cp-confirm').value;
                    if (_new !== confirm) { toast('Passwords do not match.', 'error'); return; }
                    try {
                        await api('change_password', { method: 'POST', body: { old_password: old, new_password: _new } });
                        toast('Password changed.', 'success');
                        closeModal();
                    } catch (err) { toast(err.message, 'error'); }
                }
            },
        ]);
        setTimeout(() => document.getElementById('cp-old')?.focus(), 100);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  SETTINGS
    // ═══════════════════════════════════════════════════════════════════════

    async function showSettings() {
        const s = state.settings;
        const html = `
            <div class="setting-row">
                <div><div class="setting-label">Show Hidden Files</div><div class="setting-desc">Display files starting with a dot</div></div>
                <label class="toggle"><input type="checkbox" id="s-hidden" ${s.show_hidden ? 'checked' : ''}><span class="toggle-slider"></span></label>
            </div>
            <div class="setting-row">
                <div><div class="setting-label">Default View</div><div class="setting-desc">Choose list or grid view</div></div>
                <select id="s-view" style="padding:6px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text)">
                    <option value="list" ${s.default_view === 'list' ? 'selected' : ''}>List</option>
                    <option value="grid" ${s.default_view === 'grid' ? 'selected' : ''}>Grid</option>
                </select>
            </div>
            <div class="setting-row">
                <div><div class="setting-label">Enable Trash</div><div class="setting-desc">Move deleted items to trash instead of permanent delete</div></div>
                <label class="toggle"><input type="checkbox" id="s-trash" ${s.enable_trash ? 'checked' : ''}><span class="toggle-slider"></span></label>
            </div>
            <div class="setting-row">
                <div><div class="setting-label">Theme</div><div class="setting-desc">Application color scheme</div></div>
                <select id="s-theme" style="padding:6px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text)">
                    <option value="auto" ${s.theme === 'auto' ? 'selected' : ''}>Auto</option>
                    <option value="light" ${s.theme === 'light' ? 'selected' : ''}>Light</option>
                    <option value="dark" ${s.theme === 'dark' ? 'selected' : ''}>Dark</option>
                </select>
            </div>
            <div class="setting-row">
                <div><div class="setting-label">Date Format</div><div class="setting-desc">Format for file dates</div></div>
                <select id="s-datefmt" style="padding:6px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text)">
                    <option value="Y-m-d H:i" ${s.date_format === 'Y-m-d H:i' ? 'selected' : ''}>2024-01-15 14:30</option>
                    <option value="d/m/Y H:i" ${s.date_format === 'd/m/Y H:i' ? 'selected' : ''}>15/01/2024 14:30</option>
                    <option value="m/d/Y h:i A" ${s.date_format === 'm/d/Y h:i A' ? 'selected' : ''}>01/15/2024 02:30 PM</option>
                    <option value="relative" ${s.date_format === 'relative' ? 'selected' : ''}>Relative (2 hours ago)</option>
                </select>
            </div>
        `;

        showModal('Settings', html, [
            { label: 'Cancel', cls: '', action: closeModal },
            {
                label: 'Save', cls: 'btn-primary', action: async () => {
                    const newSettings = {
                        show_hidden: document.getElementById('s-hidden').checked,
                        default_view: document.getElementById('s-view').value,
                        enable_trash: document.getElementById('s-trash').checked,
                        theme: document.getElementById('s-theme').value,
                        date_format: document.getElementById('s-datefmt').value,
                    };
                    try {
                        const resp = await api('settings', { method: 'POST', body: newSettings });
                        state.settings = resp.settings;
                        document.documentElement.dataset.theme = state.settings.theme;
                        resolveTheme();
                        toast('Settings saved.', 'success');
                        closeModal();
                        navigate(state.path);
                    } catch (err) { toast(err.message, 'error'); }
                }
            },
        ]);
    }

    async function showStorageInfo() {
        try {
            const data = await api('storage');
            const usedPct = data.total > 0 ? Math.round((data.used / data.total) * 100) : 0;
            const html = `
                <div style="margin-bottom:20px">
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.9rem">
                        <span>Used: ${escHtml(data.used_human)}</span>
                        <span>${usedPct}%</span>
                    </div>
                    <div style="height:12px;background:var(--bg);border-radius:6px;overflow:hidden">
                        <div style="height:100%;width:${usedPct}%;background:${usedPct > 90 ? 'var(--danger)' : 'var(--primary)'};border-radius:6px;transition:width .3s"></div>
                    </div>
                </div>
                <div class="info-grid">
                    <div class="info-label">Total</div><div class="info-value">${escHtml(data.total_human)}</div>
                    <div class="info-label">Used</div><div class="info-value">${escHtml(data.used_human)}</div>
                    <div class="info-label">Free</div><div class="info-value">${escHtml(data.free_human)}</div>
                </div>
            `;
            showModal('Storage Info', html, [
                { label: 'Close', cls: 'btn-primary', action: closeModal },
            ]);
        } catch (err) { toast(err.message, 'error'); }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  THEME
    // ═══════════════════════════════════════════════════════════════════════

    function resolveTheme() {
        const html = document.documentElement;
        const theme = html.dataset.theme || 'auto';
        if (theme === 'auto') {
            const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            html.dataset.resolvedTheme = isDark ? 'dark' : 'light';
        } else {
            html.dataset.resolvedTheme = theme;
        }
    }

    function toggleTheme() {
        const html = document.documentElement;
        const current = html.dataset.resolvedTheme;
        const newTheme = current === 'dark' ? 'light' : 'dark';
        html.dataset.theme = newTheme;
        html.dataset.resolvedTheme = newTheme;
        // Save preference locally
        localStorage.setItem('fm_theme', newTheme);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  MODAL SYSTEM
    // ═══════════════════════════════════════════════════════════════════════

    function showModal(title, bodyHtml, buttons = [], extraClass = '') {
        const overlay = document.getElementById('modal-overlay');
        const modal = document.getElementById('modal');
        const titleEl = document.getElementById('modal-title');
        const bodyEl = document.getElementById('modal-body');
        const footerEl = document.getElementById('modal-footer');

        titleEl.textContent = title;
        bodyEl.innerHTML = bodyHtml;
        footerEl.innerHTML = '';

        modal.className = 'modal' + (extraClass ? ' ' + extraClass : '');

        for (const btn of buttons) {
            const b = document.createElement('button');
            b.className = 'btn ' + (btn.cls || '');
            b.textContent = btn.label;
            b.addEventListener('click', btn.action);
            footerEl.appendChild(b);
        }

        overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        document.getElementById('modal-overlay').classList.add('hidden');
        document.body.style.overflow = '';
    }

    function promptInput(title, label, defaultValue = '') {
        return new Promise(resolve => {
            showModal(title, `
                <div class="form-group">
                    <label>${escHtml(label)}</label>
                    <input type="text" id="prompt-input" value="${escHtml(defaultValue)}">
                </div>
            `, [
                { label: 'Cancel', cls: '', action: () => { closeModal(); resolve(null); } },
                {
                    label: 'OK', cls: 'btn-primary', action: () => {
                        const v = document.getElementById('prompt-input')?.value?.trim() || '';
                        closeModal();
                        resolve(v || null);
                    }
                },
            ]);

            setTimeout(() => {
                const input = document.getElementById('prompt-input');
                if (input) {
                    input.focus();
                    // Select filename without extension
                    const dot = defaultValue.lastIndexOf('.');
                    input.setSelectionRange(0, dot > 0 ? dot : defaultValue.length);
                }
            }, 100);

            // Enter key
            setTimeout(() => {
                document.getElementById('prompt-input')?.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        document.querySelector('.modal-footer .btn-primary')?.click();
                    }
                });
            }, 50);
        });
    }

    function confirm_(message, confirmLabel = 'Confirm') {
        return new Promise(resolve => {
            showModal('Confirm', `<p>${escHtml(message)}</p>`, [
                { label: 'Cancel', cls: '', action: () => { closeModal(); resolve(false); } },
                { label: confirmLabel, cls: 'btn-danger', action: () => { closeModal(); resolve(true); } },
            ]);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TOAST NOTIFICATIONS
    // ═══════════════════════════════════════════════════════════════════════

    function toast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.innerHTML = `
            <span>${escHtml(message)}</span>
            <button class="toast-close" title="Dismiss">
                <svg viewBox="0 0 24 24" width="14" height="14"><path d="M18 6 6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        `;

        el.querySelector('.toast-close').addEventListener('click', () => removeToast(el));
        container.appendChild(el);

        setTimeout(() => removeToast(el), 4000);
    }

    function removeToast(el) {
        if (!el.parentNode) return;
        el.classList.add('removing');
        setTimeout(() => el.remove(), 250);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  EVENT BINDING
    // ═══════════════════════════════════════════════════════════════════════

    function bindEvents() {
        // Search
        const searchInput = document.getElementById('search-input');
        searchInput.addEventListener('input', (e) => handleSearch(e.target.value.trim()));
        document.getElementById('search-clear').addEventListener('click', () => {
            searchInput.value = '';
            handleSearch('');
        });

        // Toolbar buttons
        document.getElementById('btn-upload').addEventListener('click', () => document.getElementById('file-input').click());
        document.getElementById('btn-upload-folder').addEventListener('click', uploadFolder);
        document.getElementById('btn-new-folder').addEventListener('click', createFolder);
        document.getElementById('btn-new-file').addEventListener('click', createFile);
        document.getElementById('btn-paste').addEventListener('click', clipPaste);

        // Selection actions
        document.getElementById('btn-sel-download').addEventListener('click', () => {
            const paths = [...state.selected];
            if (paths.length === 1) {
                downloadFile(paths[0]);
            } else {
                window.open(`${API}?action=bulk_download&paths=${encodeURIComponent(paths.join(','))}`, '_blank');
            }
        });
        document.getElementById('btn-sel-copy').addEventListener('click', clipCopy);
        document.getElementById('btn-sel-cut').addEventListener('click', clipCut);
        document.getElementById('btn-sel-delete').addEventListener('click', () => deleteItems([...state.selected]));
        document.getElementById('btn-sel-compress').addEventListener('click', () => compressItems([...state.selected]));

        // Select all checkbox
        document.getElementById('select-all').addEventListener('change', (e) => {
            e.target.checked ? selectAll() : selectNone();
        });

        // View toggle
        document.getElementById('btn-view-list').addEventListener('click', () => setView('list'));
        document.getElementById('btn-view-grid').addEventListener('click', () => setView('grid'));

        // Sort headers
        document.querySelectorAll('.sortable').forEach(el => {
            el.addEventListener('click', () => {
                const sort = el.dataset.sort;
                if (state.sort === sort) {
                    state.order = state.order === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sort = sort;
                    state.order = 'asc';
                }
                updateSortUI();
                navigate(state.path);
            });
        });

        // User dropdown
        document.getElementById('user-menu-btn').addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('user-dropdown').classList.toggle('open');
        });

        // Dropdown actions
        document.querySelectorAll('#user-dropdown .dropdown-item').forEach(el => {
            el.addEventListener('click', () => {
                document.getElementById('user-dropdown').classList.remove('open');
                const action = el.dataset.action;
                switch (action) {
                    case 'logout': handleLogout(); break;
                    case 'change-password': showChangePassword(); break;
                    case 'trash': showTrash(); break;
                    case 'users': showUsers(); break;
                    case 'settings': showSettings(); break;
                    case 'storage-info': showStorageInfo(); break;
                }
            });
        });

        // Shortcuts help button
        document.getElementById('shortcuts-btn').addEventListener('click', showShortcutsHelp);

        // Theme toggle
        document.getElementById('theme-toggle').addEventListener('click', toggleTheme);

        // Modal close
        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('modal-overlay').addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeModal();
        });

        // Upload progress close
        document.getElementById('upload-progress-close').addEventListener('click', () => {
            document.getElementById('upload-progress').classList.add('hidden');
        });

        // File input
        document.getElementById('file-input').addEventListener('change', (e) => {
            uploadFiles(e.target.files, state.path);
            e.target.value = '';
        });

        // Drag & drop
        const main = document.getElementById('main');
        let dragCounter = 0;
        main.addEventListener('dragenter', (e) => {
            e.preventDefault();
            dragCounter++;
            document.getElementById('drop-zone').classList.remove('hidden');
        });
        main.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dragCounter--;
            if (dragCounter <= 0) {
                dragCounter = 0;
                document.getElementById('drop-zone').classList.add('hidden');
            }
        });
        main.addEventListener('dragover', (e) => e.preventDefault());
        main.addEventListener('drop', (e) => {
            e.preventDefault();
            dragCounter = 0;
            document.getElementById('drop-zone').classList.add('hidden');
            const files = e.dataTransfer?.files;
            if (files && files.length > 0) uploadFiles(files, state.path);
        });

        // Background context menu
        main.addEventListener('contextmenu', (e) => {
            if (e.target.closest('.file-item')) return;
            e.preventDefault();
            showContextMenu(e, null);
        });

        // Click to deselect / close menus
        document.addEventListener('click', (e) => {
            // Close dropdown
            if (!e.target.closest('#user-menu')) {
                document.getElementById('user-dropdown').classList.remove('open');
            }
            // Close context menu
            if (!e.target.closest('.context-menu')) {
                hideContextMenu();
            }
            // Deselect when clicking empty space in main
            if (e.target === main || e.target.id === 'file-list') {
                if (!e.ctrlKey && !e.shiftKey) selectNone();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Don't handle when in input/textarea
            if (e.target.matches('input, textarea, select')) {
                if (e.key === 'Escape') e.target.blur();
                return;
            }

            // Escape — close modal/context/deselect
            if (e.key === 'Escape') {
                if (!document.getElementById('modal-overlay').classList.contains('hidden')) {
                    closeModal();
                } else if (!document.getElementById('context-menu').classList.contains('hidden')) {
                    hideContextMenu();
                } else {
                    selectNone();
                }
                return;
            }

            // Ctrl+A select all
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                selectAll();
                return;
            }

            // Ctrl+C copy
            if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
                if (state.selected.size > 0) { e.preventDefault(); clipCopy(); }
                return;
            }
            // Ctrl+X cut
            if ((e.ctrlKey || e.metaKey) && e.key === 'x') {
                if (state.selected.size > 0) { e.preventDefault(); clipCut(); }
                return;
            }
            // Ctrl+V paste
            if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
                if (state.clipboard) { e.preventDefault(); clipPaste(); }
                return;
            }

            // Delete key
            if (e.key === 'Delete' && state.selected.size > 0) {
                e.preventDefault();
                deleteItems([...state.selected]);
                return;
            }

            // F2 — rename
            if (e.key === 'F2' && state.selected.size === 1) {
                e.preventDefault();
                const path = [...state.selected][0];
                const item = state.items.find(i => i.path === path);
                if (item) renameItem(item);
                return;
            }

            // F5 — refresh
            if (e.key === 'F5') {
                e.preventDefault();
                navigate(state.path);
                return;
            }

            // Backspace — go up
            if (e.key === 'Backspace') {
                e.preventDefault();
                goUp();
                return;
            }

            // Ctrl+F — focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                searchInput.focus();
                return;
            }

            // Shift+N — new folder
            if (e.shiftKey && e.key === 'N') {
                e.preventDefault();
                createFolder();
                return;
            }

            // ? — show keyboard shortcuts
            if (e.key === '?' || (e.shiftKey && e.key === '/')) {
                e.preventDefault();
                showShortcutsHelp();
                return;
            }

            // Ctrl+U — upload
            if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
                e.preventDefault();
                document.getElementById('file-input').click();
                return;
            }

            // Ctrl+R — batch rename
            if ((e.ctrlKey || e.metaKey) && e.key === 'r' && state.selected.size > 1) {
                e.preventDefault();
                batchRename();
                return;
            }

            // Enter — open selected
            if (e.key === 'Enter' && state.selected.size === 1) {
                e.preventDefault();
                const path = [...state.selected][0];
                const item = state.items.find(i => i.path === path);
                if (item) openItem(item);
                return;
            }

            // Arrow down / up for navigating items
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                navigateItemByKey(e.key === 'ArrowDown' ? 1 : -1);
                return;
            }
        });

        // OS dark mode change
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', resolveTheme);

        // Load local theme preference
        const savedTheme = localStorage.getItem('fm_theme');
        if (savedTheme) {
            document.documentElement.dataset.theme = savedTheme;
            resolveTheme();
        }
    }

    function navigateItemByKey(direction) {
        const paths = state.items.map(i => i.path);
        if (paths.length === 0) return;

        const current = [...state.selected].pop();
        let idx = current ? paths.indexOf(current) : -1;
        idx += direction;
        idx = Math.max(0, Math.min(paths.length - 1, idx));

        selectOnly(paths[idx]);

        // Scroll item into view
        const el = document.querySelector(`.file-item[data-path="${CSS.escape(paths[idx])}"]`);
        el?.scrollIntoView({ block: 'nearest' });
    }

    function goUp() {
        if (state.path === '/' || state.path === '') return;
        const parent = state.path.substring(0, state.path.lastIndexOf('/')) || '/';
        navigate(parent);
    }

    function setView(view) {
        state.view = view;
        updateViewToggle();
        renderFileList();
    }

    function updateViewToggle() {
        document.getElementById('btn-view-list').classList.toggle('active', state.view === 'list');
        document.getElementById('btn-view-grid').classList.toggle('active', state.view === 'grid');
    }

    function updateSortUI() {
        document.querySelectorAll('.sortable').forEach(el => {
            el.classList.remove('active', 'asc', 'desc');
            if (el.dataset.sort === state.sort) {
                el.classList.add('active', state.order);
            }
        });
    }

    function setLoading(show) {
        state.loading = show;
        const el = document.getElementById('loading');
        const list = document.getElementById('file-list');
        if (show) {
            el.classList.remove('hidden');
            list.style.opacity = '0.5';
        } else {
            el.classList.add('hidden');
            list.style.opacity = '';
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  UTILITIES
    // ═══════════════════════════════════════════════════════════════════════

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function humanSize(bytes) {
        if (bytes < 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0, size = bytes;
        while (size >= 1024 && i < 4) { size /= 1024; i++; }
        return (i === 0 ? size : size.toFixed(1)) + ' ' + units[i];
    }

    function formatDate(ts) {
        if (!ts) return '--';

        const fmt = state.settings?.date_format || 'Y-m-d H:i';

        if (fmt === 'relative') {
            const now = Date.now() / 1000;
            const diff = now - ts;
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
            // Fall through to date
        }

        const d = new Date(ts * 1000);
        const Y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const H = String(d.getHours()).padStart(2, '0');
        const i = String(d.getMinutes()).padStart(2, '0');

        if (fmt === 'd/m/Y H:i') return `${dd}/${m}/${Y} ${H}:${i}`;
        if (fmt === 'm/d/Y h:i A') {
            const h12 = d.getHours() % 12 || 12;
            const ampm = d.getHours() >= 12 ? 'PM' : 'AM';
            return `${m}/${dd}/${Y} ${h12}:${i} ${ampm}`;
        }
        return `${Y}-${m}-${dd} ${H}:${i}`;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  KEYBOARD SHORTCUTS HELP
    // ═══════════════════════════════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════════════════════════════
    //  FOLDER UPLOAD
    // ═══════════════════════════════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════════════════════════════
    //  BATCH RENAME
    // ═══════════════════════════════════════════════════════════════════════

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
                        } catch { /* continue */ }
                    }
                    toast(`${success} of ${paths.length} items renamed.`, 'success');
                    state.selected.clear();
                    navigate(state.path);
                }
            },
        ]);

        // Live preview
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

    // ═══════════════════════════════════════════════════════════════════════
    //  DRAG TO MOVE FILES
    // ═══════════════════════════════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════════════════════════════
    //  BOOT
    // ═══════════════════════════════════════════════════════════════════════

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
