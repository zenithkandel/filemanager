const state = {
    csrf: '',
    currentDir: '/',
    files: [],
    selected: new Set(),
    clipboard: null,
    adminVerified: false,
    editorPath: null,
    settings: null,
};

const el = {
    rows: document.getElementById('fileRows'),
    breadcrumbs: document.getElementById('breadcrumbs'),
    status: document.getElementById('statusBar'),
    fileInput: document.getElementById('fileInput'),
    dropZone: document.getElementById('dropZone'),
    selectAll: document.getElementById('selectAll'),
    editorDialog: document.getElementById('editorDialog'),
    editorArea: document.getElementById('editorArea'),
    editorTitle: document.getElementById('editorTitle'),
    saveEditorBtn: document.getElementById('saveEditorBtn'),
    viewerDialog: document.getElementById('viewerDialog'),
    viewerBody: document.getElementById('viewerBody'),
    contextMenu: document.getElementById('contextMenu'),
    settingsDialog: document.getElementById('settingsDialog'),
    folderTree: document.getElementById('folderTree'),
    app: document.getElementById('app'),
    settingsBtn: document.getElementById('settingsBtn'),
};

function setStatus(msg) {
    el.status.textContent = msg;
}

async function apiGet(action, params = {}) {
    const query = new URLSearchParams({ action, ...params }).toString();
    const res = await fetch(`api.php?${query}`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Request failed');
    return data;
}

async function apiPostJson(action, body = {}) {
    const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': state.csrf,
        },
        body: JSON.stringify(body),
    });

    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Request failed');
    return data;
}

async function initSession() {
    const data = await apiGet('session');
    state.csrf = data.csrf;
    state.adminVerified = !!data.admin_verified;
    state.settings = data.settings;
    applyUiSettings();
}

function applyUiSettings() {
    if (!state.settings) return;
    el.app.dataset.theme = state.settings.theme;
    document.body.classList.toggle('compact', state.settings.density === 'compact');
}

function bytes(size) {
    if (!size) return '-';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let n = size;
    while (n > 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n.toFixed(1)} ${units[i]}`;
}

function iconFor(file) {
    if (file.is_dir) return 'DIR';
    if (file.type === 'image') return 'IMG';
    if (file.type === 'video') return 'VID';
    if (file.type === 'audio') return 'AUD';
    if (file.type === 'archive') return 'ZIP';
    if (file.type === 'code') return 'TXT';
    return 'FIL';
}

function buildBreadcrumbs() {
    const parts = state.currentDir.split('/').filter(Boolean);
    let cursor = '';
    const links = ['<a href="#" data-bc="/">/</a>'];
    for (const part of parts) {
        cursor += '/' + part;
        links.push(`<span> -> </span><a href="#" data-bc="${cursor}">${escapeHtml(part)}</a>`);
    }
    el.breadcrumbs.innerHTML = links.join('');

    el.breadcrumbs.querySelectorAll('[data-bc]').forEach((node) => {
        node.addEventListener('click', (ev) => {
            ev.preventDefault();
            openDir(node.dataset.bc);
        });
    });
}

function renderRows() {
    el.rows.innerHTML = '';
    state.selected.clear();
    el.selectAll.checked = false;

    for (const file of state.files) {
        const tr = document.createElement('tr');
        tr.dataset.path = file.path;

        tr.innerHTML = `
            <td><input type="checkbox" data-select="${escapeAttr(file.path)}"></td>
            <td><span class="row-name" data-open="${escapeAttr(file.path)}">${iconFor(file)} ${escapeHtml(file.name)}</span></td>
            <td>${bytes(file.size)}</td>
            <td>${escapeHtml(file.type)}</td>
            <td>${escapeHtml(file.modified)}</td>
            <td>
                <button class="btn secondary" data-action="rename" data-path="${escapeAttr(file.path)}">Rename</button>
                <button class="btn secondary" data-action="copy" data-path="${escapeAttr(file.path)}">Copy</button>
                <button class="btn secondary" data-action="cut" data-path="${escapeAttr(file.path)}">Cut</button>
                <button class="btn secondary" data-action="delete" data-path="${escapeAttr(file.path)}">Delete</button>
                <button class="btn secondary" data-action="download" data-path="${escapeAttr(file.path)}">Download</button>
            </td>
        `;

        tr.addEventListener('contextmenu', (ev) => {
            ev.preventDefault();
            openContextMenu(ev.clientX, ev.clientY, file);
        });

        el.rows.appendChild(tr);
    }
}

function escapeHtml(v) {
    return String(v)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function escapeAttr(v) {
    return escapeHtml(v);
}

async function loadFiles() {
    const data = await apiGet('list', { dir: state.currentDir });
    state.files = data.files;
    buildBreadcrumbs();
    renderRows();
    renderFolderTree();
    setStatus(`Loaded ${state.files.length} items`);
}

function renderFolderTree() {
    const dirs = [{ name: '/', path: '/' }]
        .concat(state.files.filter((f) => f.is_dir).map((f) => ({ name: f.name, path: f.path })));

    el.folderTree.innerHTML = dirs
        .map((d) => `<button class="tree-item mono ${d.path === state.currentDir ? 'active' : ''}" data-dir="${escapeAttr(d.path)}">${escapeHtml(d.name)}</button>`)
        .join('');

    el.folderTree.querySelectorAll('[data-dir]').forEach((btn) => {
        btn.addEventListener('click', () => openDir(btn.dataset.dir));
    });
}

async function openDir(dir) {
    state.currentDir = dir;
    await loadFiles();
}

function getFile(path) {
    return state.files.find((f) => f.path === path);
}

async function openPath(path) {
    const file = getFile(path);
    if (!file) return;

    if (file.is_dir) {
        await openDir(path);
        return;
    }

    if (['image', 'video', 'audio'].includes(file.type)) {
        showPreview(file);
        return;
    }

    await openEditor(path);
}

async function openEditor(path) {
    const data = await apiGet('read_file', { path });
    state.editorPath = path;
    el.editorTitle.textContent = `Editor: ${data.filename}`;
    el.editorArea.value = data.content;
    el.editorDialog.showModal();
}

function showPreview(file) {
    const source = `api.php?action=download&path=${encodeURIComponent(file.path)}`;
    if (file.type === 'image') {
        el.viewerBody.innerHTML = `<img src="${source}" alt="preview">`;
    } else if (file.type === 'video') {
        el.viewerBody.innerHTML = `<video controls src="${source}"></video>`;
    } else {
        el.viewerBody.innerHTML = `<audio controls src="${source}"></audio>`;
    }
    el.viewerDialog.showModal();
}

function selectedPaths() {
    return Array.from(state.selected);
}

async function doDelete(path) {
    if (!confirm(`Delete ${path}?`)) return;
    await apiPostJson('delete', { path });
    await loadFiles();
}

async function doRename(path) {
    const current = path.split('/').pop();
    const name = prompt('New name', current);
    if (!name) return;
    await apiPostJson('rename', { path, name });
    await loadFiles();
}

function doDownload(path) {
    window.open(`api.php?action=download&path=${encodeURIComponent(path)}`, '_blank');
}

async function doZip() {
    const items = selectedPaths();
    if (!items.length) {
        alert('Select files first');
        return;
    }
    const filename = prompt('Archive name', 'archive.zip') || 'archive.zip';
    await apiPostJson('zip', { paths: items, target_dir: state.currentDir, filename });
    await loadFiles();
}

async function doUnzip(path) {
    await apiPostJson('unzip', { path, target_dir: state.currentDir });
    await loadFiles();
}

function openContextMenu(x, y, file) {
    el.contextMenu.innerHTML = '';
    const buttons = [
        ['Open', () => openPath(file.path)],
        ['Rename', () => doRename(file.path)],
        ['Delete', () => doDelete(file.path)],
        ['Copy', () => {
            state.clipboard = { mode: 'copy', path: file.path };
            setStatus(`Copied ${file.path}`);
        }],
        ['Cut', () => {
            state.clipboard = { mode: 'cut', path: file.path };
            setStatus(`Cut ${file.path}`);
        }],
        ['Paste Here', async () => {
            await doPaste(state.currentDir);
        }],
        ['Download', () => doDownload(file.path)],
    ];

    if (file.type === 'archive') {
        buttons.push(['Extract Here', () => doUnzip(file.path)]);
    }

    for (const [title, handler] of buttons) {
        const btn = document.createElement('button');
        btn.textContent = title;
        btn.addEventListener('click', async () => {
            closeContextMenu();
            try {
                await handler();
            } catch (err) {
                alert(err.message);
            }
        });
        el.contextMenu.appendChild(btn);
    }

    el.contextMenu.style.left = `${x}px`;
    el.contextMenu.style.top = `${y}px`;
    el.contextMenu.classList.remove('hidden');
}

function closeContextMenu() {
    el.contextMenu.classList.add('hidden');
}

async function doPaste(targetDir) {
    if (!state.clipboard) {
        alert('Clipboard empty');
        return;
    }
    const action = state.clipboard.mode === 'copy' ? 'copy' : 'move';
    await apiPostJson(action, { source: state.clipboard.path, target_dir: targetDir });
    if (state.clipboard.mode === 'cut') {
        state.clipboard = null;
    }
    await loadFiles();
}

async function uploadSingle(file) {
    const fd = new FormData();
    fd.append('dir', state.currentDir);
    fd.append('csrf_token', state.csrf);
    fd.append('file', file);

    const res = await fetch('api.php?action=upload', {
        method: 'POST',
        body: fd,
    });

    const data = await res.json();
    if (!data.ok) {
        throw new Error(data.error || 'Upload failed');
    }
}

async function uploadFiles(fileList) {
    for (const file of fileList) {
        await uploadSingle(file);
    }
    await loadFiles();
}

async function saveEditor() {
    if (!state.editorPath) return;
    await apiPostJson('save_file', {
        path: state.editorPath,
        content: el.editorArea.value,
    });
    setStatus('Saved file');
    el.editorDialog.close();
    await loadFiles();
}

async function createFolder() {
    const name = prompt('Folder name');
    if (!name) return;
    await apiPostJson('create_folder', { dir: state.currentDir, name });
    await loadFiles();
}

async function createFile() {
    const name = prompt('File name');
    if (!name) return;
    await apiPostJson('create_file', { dir: state.currentDir, name });
    await loadFiles();
}

async function verifyAdmin() {
    const password = prompt('Admin password');
    if (!password) return;
    await apiPostJson('verify_admin', { password });
    state.adminVerified = true;
    setStatus('Admin verification enabled');
}

function openSettingsDialog() {
    fillSettingsForm();
    el.settingsDialog.showModal();
}

function fillSettingsForm() {
    const s = state.settings;
    if (!s) return;

    document.getElementById('setShowHidden').checked = !!s.show_hidden;
    document.getElementById('setAllowUpload').checked = !!s.allow_upload;
    document.getElementById('setAllowDelete').checked = !!s.allow_delete;
    document.getElementById('setUseParent').checked = !!s.use_parent_dir;
    document.getElementById('setFixedDir').value = s.fixed_dir || '';
    document.getElementById('setAllowedExt').value = (s.allowed_extensions || []).join(',');
    document.getElementById('setMaxUpload').value = s.max_upload_size || 10485760;
    document.getElementById('setTheme').value = s.theme || 'light';
    document.getElementById('setDensity').value = s.density || 'comfortable';
    document.getElementById('setAllowPhp').checked = !!s.allow_php_upload;
    document.getElementById('setEditProtected').checked = !!s.allow_edit_protected;
    document.getElementById('setDisablePath').checked = !!s.disable_path_restrictions;
}

async function saveSettings() {
    const payload = {
        show_hidden: document.getElementById('setShowHidden').checked,
        allow_upload: document.getElementById('setAllowUpload').checked,
        allow_delete: document.getElementById('setAllowDelete').checked,
        use_parent_dir: document.getElementById('setUseParent').checked,
        fixed_dir: document.getElementById('setFixedDir').value.trim(),
        allowed_extensions: document
            .getElementById('setAllowedExt')
            .value.split(',')
            .map((v) => v.trim())
            .filter(Boolean),
        max_upload_size: Number(document.getElementById('setMaxUpload').value || 10485760),
        theme: document.getElementById('setTheme').value,
        density: document.getElementById('setDensity').value,
        allow_php_upload: document.getElementById('setAllowPhp').checked,
        allow_edit_protected: document.getElementById('setEditProtected').checked,
        disable_path_restrictions: document.getElementById('setDisablePath').checked,
    };

    const data = await apiPostJson('settings', payload);
    state.settings = data.settings;
    applyUiSettings();
    await loadFiles();
    el.settingsDialog.close();
}

function bindEvents() {
    document.getElementById('newFolderBtn').addEventListener('click', runSafe(createFolder));
    document.getElementById('newFileBtn').addEventListener('click', runSafe(createFile));

    document.getElementById('uploadBtn').addEventListener('click', () => el.fileInput.click());
    el.fileInput.addEventListener('change', runSafe(async () => {
        if (!el.fileInput.files || !el.fileInput.files.length) return;
        await uploadFiles(el.fileInput.files);
        el.fileInput.value = '';
    }));

    document.getElementById('downloadSelectedBtn').addEventListener('click', runSafe(async () => {
        const paths = selectedPaths();
        if (!paths.length) {
            alert('Select files first');
            return;
        }

        if (paths.length === 1) {
            doDownload(paths[0]);
            return;
        }

        const res = await fetch('api.php?action=download_multi', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': state.csrf,
            },
            body: JSON.stringify({ paths }),
        });

        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'download.zip';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }));

    document.getElementById('settingsBtn').addEventListener('click', runSafe(openSettingsDialog));
    document.getElementById('verifyAdminBtn').addEventListener('click', runSafe(verifyAdmin));
    document.getElementById('saveSettingsBtn').addEventListener('click', runSafe(saveSettings));
    el.saveEditorBtn.addEventListener('click', runSafe(saveEditor));

    el.rows.addEventListener('click', runSafe(async (ev) => {
        const openNode = ev.target.closest('[data-open]');
        if (openNode) {
            await openPath(openNode.dataset.open);
            return;
        }

        const actionBtn = ev.target.closest('[data-action]');
        if (!actionBtn) return;
        const path = actionBtn.dataset.path;
        const action = actionBtn.dataset.action;

        if (action === 'rename') await doRename(path);
        if (action === 'copy') {
            state.clipboard = { mode: 'copy', path };
            setStatus(`Copied ${path}`);
        }
        if (action === 'cut') {
            state.clipboard = { mode: 'cut', path };
            setStatus(`Cut ${path}`);
        }
        if (action === 'delete') await doDelete(path);
        if (action === 'download') doDownload(path);
    }));

    el.rows.addEventListener('change', (ev) => {
        const cb = ev.target.closest('[data-select]');
        if (!cb) return;

        const path = cb.dataset.select;
        if (cb.checked) {
            state.selected.add(path);
        } else {
            state.selected.delete(path);
        }

        const tr = cb.closest('tr');
        if (tr) tr.classList.toggle('selected', cb.checked);
    });

    el.selectAll.addEventListener('change', () => {
        const checked = el.selectAll.checked;
        state.selected.clear();
        el.rows.querySelectorAll('[data-select]').forEach((cb) => {
            cb.checked = checked;
            const path = cb.dataset.select;
            if (checked) state.selected.add(path);
            const tr = cb.closest('tr');
            if (tr) tr.classList.toggle('selected', checked);
        });
    });

    document.addEventListener('click', (ev) => {
        if (!ev.target.closest('#contextMenu')) {
            closeContextMenu();
        }
    });

    document.addEventListener('keydown', runSafe(async (ev) => {
        if (ev.key === 'Escape') {
            closeContextMenu();
        }
        if (ev.ctrlKey && ev.key.toLowerCase() === 'v') {
            ev.preventDefault();
            await doPaste(state.currentDir);
        }
        if (ev.ctrlKey && ev.key.toLowerCase() === 'z') {
            ev.preventDefault();
            await doZip();
        }
    }));

    ['dragenter', 'dragover'].forEach((evt) => {
        el.dropZone.addEventListener(evt, (ev) => {
            ev.preventDefault();
            el.dropZone.classList.add('active');
        });
    });

    ['dragleave', 'drop'].forEach((evt) => {
        el.dropZone.addEventListener(evt, (ev) => {
            ev.preventDefault();
            if (evt === 'drop') {
                const files = ev.dataTransfer.files;
                runSafe(async () => {
                    await uploadFiles(files);
                })();
            }
            el.dropZone.classList.remove('active');
        });
    });
}

function runSafe(fn) {
    return async (ev) => {
        try {
            await fn(ev);
        } catch (err) {
            console.error(err);
            alert(err.message || 'Unexpected error');
            setStatus(err.message || 'Operation failed');
        }
    };
}

(async function boot() {
    try {
        await initSession();
        bindEvents();
        await loadFiles();
        setStatus('Ready');
    } catch (err) {
        alert(err.message || 'Failed to initialize');
    }
})();
