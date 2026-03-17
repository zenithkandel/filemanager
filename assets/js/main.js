/**
 * Portable Secure Web File Manager - Frontend Application
 * Complete SPA: file ops, search, sort, trash, favorites, recent,
 * context menu, keyboard shortcuts, Monaco/CodeMirror/textarea editor
 */

(() => {
'use strict';

// ═══════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════
const S = {
    csrf: window.FM.csrf,
    role: window.FM.role,
    isAdmin: window.FM.isAdmin,
    editorPref: window.FM.editorPref,
    dir: '/',
    files: [],
    selected: new Set(),
    clipboard: { action: null, paths: [] },
    adminVerified: false,
    sort: 'name',
    order: 'asc',
    settings: {},
    editorPath: '',
    editorInstance: null,
    editorType: null,  // 'monaco' | 'codemirror' | 'textarea'
    searchTimeout: null,
};

// ═══════════════════════════════════════════════════════════════
// API COMMUNICATION
// ═══════════════════════════════════════════════════════════════
async function apiGet(action, params = {}) {
    const url = new URL('api.php', location.href);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) {
        if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
    }
    const res = await fetch(url);
    if (res.status === 401) { location.reload(); return null; }
    const data = await res.json();
    if (data.error && data.needs_verify) {
        const verified = await promptAdminVerify();
        if (verified) return apiGet(action, params);
        return null;
    }
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
}

async function apiPost(action, body = {}) {
    const res = await fetch(`api.php?action=${action}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': S.csrf,
        },
        body: JSON.stringify(body),
    });
    if (res.status === 401) { location.reload(); return null; }
    const data = await res.json();
    if (data.error && data.needs_verify) {
        const verified = await promptAdminVerify();
        if (verified) return apiPost(action, body);
        return null;
    }
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
}

async function apiUpload(formData) {
    const res = await fetch('api.php?action=upload', {
        method: 'POST',
        headers: { 'X-CSRF-Token': S.csrf },
        body: formData,
    });
    if (res.status === 401) { location.reload(); return null; }
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Upload failed');
    return data;
}

// ═══════════════════════════════════════════════════════════════
// INITIALIZATION
// ═══════════════════════════════════════════════════════════════
async function init() {
    try {
        const session = await apiGet('session');
        if (!session) return;
        S.csrf = session.csrf;
        S.adminVerified = session.admin_verified;
        S.settings = session.settings;
        S.sort = 'name';
        S.order = 'asc';
        $('rootPath').textContent = session.base_dir;
        applyTheme(S.settings.theme);
        applyDensity(S.settings.density);
        bindEvents();
        await loadFiles();
        await loadFolderTree();
    } catch (e) {
        status('Init error: ' + e.message);
    }
}

// ═══════════════════════════════════════════════════════════════
// FILE LISTING & NAVIGATION
// ═══════════════════════════════════════════════════════════════
async function loadFiles() {
    showLoading(true);
    try {
        const data = await apiGet('list', { dir: S.dir, sort: S.sort, order: S.order });
        if (!data) return;
        S.files = data.files || [];
        S.selected.clear();
        renderFiles();
        buildBreadcrumbs(data.dir);
        updateSelectionUI();
        status(`${S.files.length} items`);
    } catch (e) {
        status('Error: ' + e.message);
    } finally {
        showLoading(false);
    }
}

function openDir(dir) {
    S.dir = dir;
    loadFiles();
    loadFolderTree();
}

function openPath(file) {
    if (file.is_dir) {
        openDir(file.path);
    } else if (['image', 'video', 'audio'].includes(file.type)) {
        showPreview(file);
    } else if (['code', 'file'].includes(file.type) || file.type === 'pdf') {
        if (file.type === 'pdf') {
            window.open(`api.php?action=download&path=${encodeURIComponent(file.path)}`, '_blank');
        } else {
            openEditor(file.path);
        }
    } else {
        openEditor(file.path);
    }
}

// ═══════════════════════════════════════════════════════════════
// RENDERING
// ═══════════════════════════════════════════════════════════════
function renderFiles() {
    const tbody = $('fileTableBody');
    if (S.files.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6">
            <div class="empty-state">
                <div class="empty-state-icon">&#128193;</div>
                <div class="empty-state-text">Empty directory</div>
            </div>
        </td></tr>`;
        return;
    }
    tbody.innerHTML = S.files.map(f => {
        const checked = S.selected.has(f.path) ? 'checked' : '';
        const selClass = S.selected.has(f.path) ? 'selected' : '';
        const icon = fileIcon(f);
        const fav = f.favorite ? '<span class="file-fav" title="Favorite">&#9733;</span>' : '';
        const size = f.is_dir ? '--' : formatSize(f.size);
        const modified = f.modified ? formatDate(f.modified) : '--';
        return `<tr class="${selClass}" data-path="${esc(f.path)}" data-isdir="${f.is_dir}">
            <td class="col-check"><input type="checkbox" ${checked} data-check="${esc(f.path)}"></td>
            <td class="col-name"><span class="file-name" data-open="${esc(f.path)}"><span class="file-icon">${icon}</span>${esc(f.name)}${fav}</span></td>
            <td class="col-size"><span class="file-size">${size}</span></td>
            <td class="col-type"><span class="file-type">${esc(f.type)}</span></td>
            <td class="col-modified"><span class="file-modified">${modified}</span></td>
            <td class="col-actions"><div class="row-actions">
                <button class="row-action" data-act="rename" data-path="${esc(f.path)}" title="Rename">Ren</button>
                <button class="row-action" data-act="copy" data-path="${esc(f.path)}" title="Copy">Cpy</button>
                <button class="row-action" data-act="cut" data-path="${esc(f.path)}" title="Cut">Cut</button>
                <button class="row-action" data-act="delete" data-path="${esc(f.path)}" title="Delete">Del</button>
                ${!f.is_dir ? `<button class="row-action" data-act="download" data-path="${esc(f.path)}" title="Download">Dl</button>` : ''}
            </div></td>
        </tr>`;
    }).join('');
}

function buildBreadcrumbs(dir) {
    const bc = $('breadcrumbs');
    const parts = dir.split('/').filter(Boolean);
    let html = `<button class="breadcrumb-item${parts.length === 0 ? ' active' : ''}" data-dir="/">/root</button>`;
    let path = '';
    parts.forEach((p, i) => {
        path += '/' + p;
        const isLast = i === parts.length - 1;
        html += `<span class="breadcrumb-sep">/</span>`;
        html += `<button class="breadcrumb-item${isLast ? ' active' : ''}" data-dir="${esc(path)}">${esc(p)}</button>`;
    });
    bc.innerHTML = html;
}

async function loadFolderTree() {
    try {
        const data = await apiGet('list', { dir: '/', sort: 'name', order: 'asc' });
        if (!data) return;
        const tree = $('folderTree');
        const dirs = (data.files || []).filter(f => f.is_dir);
        if (dirs.length === 0) {
            tree.innerHTML = '<div style="padding:8px 16px;color:var(--text-muted);font-size:11px">No folders</div>';
            return;
        }
        tree.innerHTML = dirs.map(d => {
            const isActive = S.dir === d.path ? ' active' : '';
            return `<div class="tree-item${isActive}" data-dir="${esc(d.path)}" style="--depth:0">
                <span class="tree-icon">&#128193;</span>${esc(d.name)}
            </div>`;
        }).join('');
    } catch (e) { /* silent */ }
}

// ═══════════════════════════════════════════════════════════════
// FILE OPERATIONS
// ═══════════════════════════════════════════════════════════════
async function createFolder() {
    const name = prompt('New folder name:');
    if (!name) return;
    await runSafe(async () => {
        await apiPost('create_folder', { dir: S.dir, name });
        await loadFiles();
        await loadFolderTree();
        status('Folder created');
    });
}

async function createFile() {
    const name = prompt('New file name (with extension):');
    if (!name) return;
    await runSafe(async () => {
        await apiPost('create_file', { dir: S.dir, name });
        await loadFiles();
        status('File created');
    });
}

async function doRename(path) {
    const current = path.split('/').pop();
    const newName = prompt('Rename to:', current);
    if (!newName || newName === current) return;
    await runSafe(async () => {
        await apiPost('rename', { path, new_name: newName });
        await loadFiles();
        await loadFolderTree();
        status('Renamed');
    });
}

async function doDelete(paths) {
    if (!Array.isArray(paths)) paths = [paths];
    if (!confirm(`Move ${paths.length} item(s) to trash?`)) return;
    await runSafe(async () => {
        await apiPost('delete', { paths });
        await loadFiles();
        await loadFolderTree();
        status('Moved to trash');
    });
}

function doCopy(paths) {
    if (!Array.isArray(paths)) paths = [paths];
    S.clipboard = { action: 'copy', paths };
    status(`${paths.length} item(s) copied to clipboard`);
}

function doCut(paths) {
    if (!Array.isArray(paths)) paths = [paths];
    S.clipboard = { action: 'cut', paths };
    status(`${paths.length} item(s) cut to clipboard`);
}

async function doPaste() {
    if (!S.clipboard.action || S.clipboard.paths.length === 0) {
        status('Clipboard empty');
        return;
    }
    await runSafe(async () => {
        const action = S.clipboard.action === 'cut' ? 'move' : 'copy';
        await apiPost(action, { paths: S.clipboard.paths, dest: S.dir });
        if (S.clipboard.action === 'cut') {
            S.clipboard = { action: null, paths: [] };
        }
        await loadFiles();
        await loadFolderTree();
        status('Pasted');
    });
}

async function doDownload(path) {
    window.open(`api.php?action=download&path=${encodeURIComponent(path)}`, '_blank');
}

async function doDownloadSelected() {
    const paths = [...S.selected];
    if (paths.length === 0) return;
    if (paths.length === 1) {
        const f = S.files.find(f => f.path === paths[0]);
        if (f && !f.is_dir) { doDownload(paths[0]); return; }
    }
    // Multi-download via form POST
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api.php?action=download_multi';
    form.target = '_blank';
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden'; csrfInput.name = 'csrf_token'; csrfInput.value = S.csrf;
    form.appendChild(csrfInput);
    paths.forEach(p => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'paths[]'; inp.value = p;
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
    form.remove();
}

async function doZipSelected() {
    const paths = [...S.selected];
    if (paths.length === 0) return;
    const name = prompt('ZIP archive name:', 'archive.zip');
    if (!name) return;
    await runSafe(async () => {
        await apiPost('zip', { paths, dest: S.dir, name });
        await loadFiles();
        status('ZIP created');
    });
}

async function doUnzip(path) {
    await runSafe(async () => {
        await apiPost('unzip', { path });
        await loadFiles();
        await loadFolderTree();
        status('Extracted');
    });
}

async function doDeleteSelected() {
    const paths = [...S.selected];
    if (paths.length === 0) return;
    await doDelete(paths);
}

async function toggleFavorite(path) {
    const file = S.files.find(f => f.path === path);
    const action = file && file.favorite ? 'remove' : 'add';
    await runSafe(async () => {
        await apiPost('favorites', { action, path });
        await loadFiles();
    });
}

// ═══════════════════════════════════════════════════════════════
// UPLOAD
// ═══════════════════════════════════════════════════════════════
async function uploadFiles(fileList) {
    if (!fileList || fileList.length === 0) return;
    showLoading(true);
    let success = 0, fail = 0;
    for (const file of fileList) {
        try {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('dir', S.dir);
            await apiUpload(fd);
            success++;
        } catch (e) {
            fail++;
            console.error('Upload error:', file.name, e.message);
        }
    }
    showLoading(false);
    await loadFiles();
    status(`Uploaded: ${success} success${fail > 0 ? `, ${fail} failed` : ''}`);
}

// ═══════════════════════════════════════════════════════════════
// EDITOR (Monaco / CodeMirror / Textarea)
// ═══════════════════════════════════════════════════════════════
async function openEditor(path) {
    showLoading(true);
    try {
        const data = await apiGet('read_file', { path });
        if (!data) return;
        S.editorPath = data.path;
        $('editorTitle').textContent = data.name;
        $('editorSize').textContent = formatSize(data.size);

        // Populate language selector
        const langSelect = $('editorLang');
        langSelect.innerHTML = LANGUAGES.map(l =>
            `<option value="${l}" ${l === data.language ? 'selected' : ''}>${l}</option>`
        ).join('');

        const dialog = $('editorDialog');
        dialog.showModal();

        // Determine editor to use
        const pref = S.settings.editor || S.editorPref || 'monaco';
        await initEditor(pref, data.content, data.language);
    } catch (e) {
        alert('Error opening file: ' + e.message);
    } finally {
        showLoading(false);
    }
}

async function initEditor(type, content, language) {
    destroyEditor();
    const container = $('editorContainer');
    container.innerHTML = '';

    if (type === 'monaco') {
        try {
            await loadMonaco();
            const theme = document.documentElement.dataset.theme === 'dark' ? 'vs-dark' : 'vs';
            S.editorInstance = monaco.editor.create(container, {
                value: content,
                language: language,
                theme: theme,
                automaticLayout: true,
                minimap: { enabled: true },
                fontSize: 13,
                fontFamily: "'JetBrains Mono', 'Fira Code', 'Consolas', monospace",
                wordWrap: 'on',
                lineNumbers: 'on',
                scrollBeyondLastLine: false,
                renderWhitespace: 'selection',
                tabSize: 4,
            });
            S.editorType = 'monaco';
            // Ctrl+S save
            S.editorInstance.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => saveEditor());
            return;
        } catch (e) {
            console.warn('Monaco failed, falling back:', e);
        }
    }

    if (type === 'codemirror') {
        try {
            await loadCodeMirror();
            const el = document.createElement('div');
            el.style.height = '100%';
            container.appendChild(el);
            S.editorInstance = CodeMirror(el, {
                value: content,
                mode: cmMode(language),
                lineNumbers: true,
                theme: document.documentElement.dataset.theme === 'dark' ? 'material-darker' : 'default',
                tabSize: 4,
                indentWithTabs: false,
                lineWrapping: true,
                matchBrackets: true,
                autoCloseBrackets: true,
            });
            S.editorType = 'codemirror';
            S.editorInstance.setSize('100%', '100%');
            // Ctrl+S
            S.editorInstance.setOption('extraKeys', {
                'Ctrl-S': () => saveEditor(),
                'Cmd-S': () => saveEditor(),
            });
            return;
        } catch (e) {
            console.warn('CodeMirror failed, falling back:', e);
        }
    }

    // Textarea fallback
    const textarea = document.createElement('textarea');
    textarea.value = content;
    textarea.spellcheck = false;
    container.appendChild(textarea);
    S.editorInstance = textarea;
    S.editorType = 'textarea';

    // Ctrl+S
    textarea.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveEditor();
        }
        // Tab support
        if (e.key === 'Tab') {
            e.preventDefault();
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            textarea.value = textarea.value.substring(0, start) + '    ' + textarea.value.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + 4;
        }
    });
}

function destroyEditor() {
    if (S.editorInstance) {
        if (S.editorType === 'monaco' && S.editorInstance.dispose) {
            S.editorInstance.dispose();
        }
        S.editorInstance = null;
        S.editorType = null;
    }
    $('editorContainer').innerHTML = '';
}

function getEditorContent() {
    if (!S.editorInstance) return '';
    if (S.editorType === 'monaco') return S.editorInstance.getValue();
    if (S.editorType === 'codemirror') return S.editorInstance.getValue();
    return S.editorInstance.value; // textarea
}

async function saveEditor() {
    await runSafe(async () => {
        const content = getEditorContent();
        await apiPost('save_file', { path: S.editorPath, content });
        status('File saved');
    });
}

function closeEditor() {
    destroyEditor();
    $('editorDialog').close();
    S.editorPath = '';
}

// Monaco loader
let monacoLoading = null;
function loadMonaco() {
    if (window.monaco) return Promise.resolve();
    if (monacoLoading) return monacoLoading;
    monacoLoading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js';
        script.onload = () => {
            require.config({ paths: { vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' } });
            require(['vs/editor/editor.main'], () => resolve());
        };
        script.onerror = reject;
        document.head.appendChild(script);
    });
    return monacoLoading;
}

// CodeMirror loader
let cmLoading = null;
function loadCodeMirror() {
    if (window.CodeMirror) return Promise.resolve();
    if (cmLoading) return cmLoading;
    cmLoading = new Promise((resolve, reject) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.css';
        document.head.appendChild(link);
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.js';
        script.onload = () => {
            // Load common modes
            const modes = ['javascript', 'css', 'xml', 'htmlmixed', 'php', 'python', 'sql', 'yaml', 'markdown', 'shell'];
            let loaded = 0;
            modes.forEach(m => {
                const ms = document.createElement('script');
                ms.src = `https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/${m}/${m}.min.js`;
                ms.onload = () => { if (++loaded >= modes.length) resolve(); };
                ms.onerror = () => { if (++loaded >= modes.length) resolve(); };
                document.head.appendChild(ms);
            });
        };
        script.onerror = reject;
        document.head.appendChild(script);
    });
    return cmLoading;
}

function cmMode(lang) {
    const map = {
        javascript: 'javascript', typescript: 'javascript', css: 'css',
        html: 'htmlmixed', xml: 'xml', php: 'php', python: 'python',
        sql: 'sql', yaml: 'yaml', markdown: 'markdown', shell: 'shell',
        json: { name: 'javascript', json: true },
    };
    return map[lang] || 'text/plain';
}

const LANGUAGES = [
    'plaintext','javascript','typescript','php','python','html','css','json',
    'xml','yaml','markdown','sql','c','cpp','java','go','rust','ruby','shell',
    'bat','powershell','ini','scss','less',
];

// ═══════════════════════════════════════════════════════════════
// MEDIA PREVIEW
// ═══════════════════════════════════════════════════════════════
function showPreview(file) {
    const content = $('viewerContent');
    $('viewerTitle').textContent = file.name;
    const url = `api.php?action=download&path=${encodeURIComponent(file.path)}`;

    if (file.type === 'image') {
        content.innerHTML = `<img src="${esc(url)}" alt="${esc(file.name)}">`;
    } else if (file.type === 'video') {
        content.innerHTML = `<video controls autoplay><source src="${esc(url)}"></video>`;
    } else if (file.type === 'audio') {
        content.innerHTML = `<audio controls autoplay><source src="${esc(url)}"></audio>`;
    } else {
        content.innerHTML = '<div class="empty-state"><div class="empty-state-text">Preview not available</div></div>';
    }
    $('viewerDialog').showModal();
}

// ═══════════════════════════════════════════════════════════════
// SEARCH
// ═══════════════════════════════════════════════════════════════
function handleSearch(query) {
    clearTimeout(S.searchTimeout);
    if (!query || query.length < 1) {
        loadFiles();
        return;
    }
    S.searchTimeout = setTimeout(async () => {
        try {
            showLoading(true);
            const data = await apiGet('search', { q: query, dir: S.dir });
            if (!data) return;
            S.files = data.results || [];
            S.selected.clear();
            renderFiles();
            status(`Search: ${S.files.length} result(s) for "${query}"`);
        } catch (e) {
            status('Search error: ' + e.message);
        } finally {
            showLoading(false);
        }
    }, 300);
}

// ═══════════════════════════════════════════════════════════════
// TRASH
// ═══════════════════════════════════════════════════════════════
async function showTrash() {
    try {
        const data = await apiGet('trash');
        if (!data) return;
        const list = $('trashList');
        if (!data.items || data.items.length === 0) {
            list.innerHTML = '<div class="empty-state"><div class="empty-state-text">Trash is empty</div></div>';
        } else {
            list.innerHTML = data.items.map(item => `
                <div class="list-item">
                    <span class="list-item-name" title="${esc(item.original_path)}">${esc(item.name)}</span>
                    <span class="list-item-meta">${esc(item.original_path)}</span>
                    <span class="list-item-meta">${item.deleted_at ? formatDate(new Date(item.deleted_at).getTime() / 1000) : ''}</span>
                    <div class="list-item-actions">
                        <button class="btn btn-sm" data-trash-restore="${esc(item.name)}">Restore</button>
                        <button class="btn btn-sm btn-danger" data-trash-delete="${esc(item.name)}">Delete</button>
                    </div>
                </div>
            `).join('');
        }
        $('trashDialog').showModal();
    } catch (e) {
        alert('Error loading trash: ' + e.message);
    }
}

async function restoreFromTrash(name) {
    await runSafe(async () => {
        await apiPost('restore', { name });
        await loadFiles();
        await showTrash();
        status('Restored from trash');
    });
}

async function permanentDelete(name) {
    if (!confirm(`Permanently delete "${name}"? This cannot be undone.`)) return;
    await runSafe(async () => {
        await apiPost('permanent_delete', { names: [name] });
        await showTrash();
        status('Permanently deleted');
    });
}

async function emptyTrash() {
    if (!confirm('Empty trash? All items will be permanently deleted.')) return;
    await runSafe(async () => {
        await apiPost('empty_trash');
        await showTrash();
        status('Trash emptied');
    });
}

// ═══════════════════════════════════════════════════════════════
// FAVORITES
// ═══════════════════════════════════════════════════════════════
async function showFavorites() {
    try {
        const data = await apiGet('favorites');
        if (!data) return;
        const list = $('favoritesList');
        const favs = data.favorites || [];
        if (favs.length === 0) {
            list.innerHTML = '<div class="empty-state"><div class="empty-state-text">No favorites yet</div></div>';
        } else {
            list.innerHTML = favs.map(path => `
                <div class="list-item">
                    <span class="list-item-name" data-nav-path="${esc(path)}">&#9733; ${esc(path)}</span>
                    <button class="btn btn-sm" data-unfav="${esc(path)}">Remove</button>
                </div>
            `).join('');
        }
        $('favoritesDialog').showModal();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function removeFavorite(path) {
    await runSafe(async () => {
        await apiPost('favorites', { action: 'remove', path });
        await showFavorites();
        await loadFiles();
    });
}

// ═══════════════════════════════════════════════════════════════
// RECENT FILES
// ═══════════════════════════════════════════════════════════════
async function showRecent() {
    try {
        const data = await apiGet('recent');
        if (!data) return;
        const list = $('recentList');
        const items = data.recent || [];
        if (items.length === 0) {
            list.innerHTML = '<div class="empty-state"><div class="empty-state-text">No recent files</div></div>';
        } else {
            list.innerHTML = items.map(item => `
                <div class="list-item">
                    <span class="list-item-name" data-nav-path="${esc(item.path)}">${esc(item.name)}</span>
                    <span class="list-item-meta">${esc(item.path)}</span>
                    <span class="list-item-meta">${formatDate(item.time)}</span>
                </div>
            `).join('');
        }
        $('recentDialog').showModal();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// ═══════════════════════════════════════════════════════════════
// LOGS
// ═══════════════════════════════════════════════════════════════
async function showLogs() {
    try {
        const data = await apiGet('logs');
        if (!data) return;
        const list = $('logsList');
        const logs = data.logs || [];
        if (logs.length === 0) {
            list.innerHTML = '<div class="empty-state"><div class="empty-state-text">No logs yet</div></div>';
        } else {
            list.innerHTML = logs.map(log => `
                <div class="log-entry">
                    <span class="log-time">${esc(log.time)}</span>
                    <span class="log-category">${esc(log.category)}</span>
                    <span class="log-action">${esc(log.action)}</span>
                    <span class="log-data">${log.data ? esc(JSON.stringify(log.data)) : ''}</span>
                </div>
            `).join('');
        }
        $('logsDialog').showModal();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// ═══════════════════════════════════════════════════════════════
// STORAGE STATS
// ═══════════════════════════════════════════════════════════════
async function showStorage() {
    showLoading(true);
    try {
        const data = await apiGet('storage');
        if (!data) return;
        const c = $('storageContent');
        const usedPct = data.disk_total > 0 ? ((data.disk_total - data.disk_free) / data.disk_total * 100).toFixed(1) : 0;

        let html = `
            <div class="stat-row"><span class="stat-label">Total files in root</span><span class="stat-value">${data.file_count.toLocaleString()}</span></div>
            <div class="stat-row"><span class="stat-label">Total folders</span><span class="stat-value">${data.dir_count.toLocaleString()}</span></div>
            <div class="stat-row"><span class="stat-label">Total size</span><span class="stat-value">${data.total_size_fmt}</span></div>
            <div class="stat-row"><span class="stat-label">Disk free</span><span class="stat-value">${data.disk_free_fmt}</span></div>
            <div class="stat-row"><span class="stat-label">Disk total</span><span class="stat-value">${data.disk_total_fmt}</span></div>
            <div class="stat-bar"><div class="stat-bar-fill" style="width:${usedPct}%"></div></div>
            <div style="text-align:center;font-size:11px;color:var(--text-muted)">${usedPct}% used</div>
        `;

        if (data.types && Object.keys(data.types).length > 0) {
            html += '<div class="type-breakdown"><h4 style="margin:8px 0">By Type</h4>';
            for (const [type, info] of Object.entries(data.types)) {
                html += `<div class="type-row">
                    <span class="type-name">${esc(type)}</span>
                    <span>${info.count} files</span>
                    <span>${formatSize(info.size)}</span>
                </div>`;
            }
            html += '</div>';
        }
        c.innerHTML = html;
        $('storageDialog').showModal();
    } catch (e) {
        alert('Error: ' + e.message);
    } finally {
        showLoading(false);
    }
}

// ═══════════════════════════════════════════════════════════════
// PROPERTIES
// ═══════════════════════════════════════════════════════════════
async function showProperties(path) {
    try {
        const data = await apiGet('info', { path });
        if (!data) return;
        const c = $('propsContent');
        c.innerHTML = `
            <div class="prop-row"><span class="prop-label">Name</span><span class="prop-value">${esc(data.name)}</span></div>
            <div class="prop-row"><span class="prop-label">Path</span><span class="prop-value">${esc(data.path)}</span></div>
            <div class="prop-row"><span class="prop-label">Type</span><span class="prop-value">${esc(data.type)}${data.ext ? ' (.' + esc(data.ext) + ')' : ''}</span></div>
            <div class="prop-row"><span class="prop-label">Size</span><span class="prop-value">${formatSize(data.size)}</span></div>
            <div class="prop-row"><span class="prop-label">Modified</span><span class="prop-value">${formatDate(data.modified)}</span></div>
            <div class="prop-row"><span class="prop-label">Permissions</span><span class="prop-value">${esc(data.perms)}</span></div>
            <div class="prop-row"><span class="prop-label">Writable</span><span class="prop-value">${data.writable ? 'Yes' : 'No'}</span></div>
            ${data.mime ? `<div class="prop-row"><span class="prop-label">MIME</span><span class="prop-value">${esc(data.mime)}</span></div>` : ''}
        `;
        $('propsDialog').showModal();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// ═══════════════════════════════════════════════════════════════
// SETTINGS
// ═══════════════════════════════════════════════════════════════
function openSettings() {
    const form = $('settingsForm');
    const s = S.settings;

    form.show_hidden.checked = s.show_hidden;
    form.allow_upload.checked = s.allow_upload;
    form.allow_delete.checked = s.allow_delete;
    form.use_parent_dir.checked = s.use_parent_dir;
    form.fixed_dir.value = s.fixed_dir || '';
    form.allowed_extensions.value = (s.allowed_extensions || []).join(', ');
    form.blocked_extensions.value = (s.blocked_extensions || []).join(', ');
    form.max_upload_size.value = s.max_upload_size || 10485760;
    form.theme.value = s.theme || 'light';
    form.density.value = s.density || 'comfortable';
    form.editor.value = s.editor || 'monaco';
    form.allow_php_upload.checked = s.allow_php_upload;
    form.allow_edit_protected.checked = s.allow_edit_protected;
    form.disable_path_restrictions.checked = s.disable_path_restrictions;
    form.new_password_user.value = '';
    form.new_password_admin.value = '';

    // Show admin verify if not verified
    $('settingsAdminVerify').style.display = S.adminVerified ? 'none' : 'flex';

    $('settingsDialog').showModal();
}

async function saveSettings(e) {
    e.preventDefault();
    const form = $('settingsForm');
    const data = {
        show_hidden: form.show_hidden.checked,
        allow_upload: form.allow_upload.checked,
        allow_delete: form.allow_delete.checked,
        use_parent_dir: form.use_parent_dir.checked,
        fixed_dir: form.fixed_dir.value,
        allowed_extensions: form.allowed_extensions.value,
        blocked_extensions: form.blocked_extensions.value,
        max_upload_size: parseInt(form.max_upload_size.value) || 10485760,
        theme: form.theme.value,
        density: form.density.value,
        editor: form.editor.value,
        allow_php_upload: form.allow_php_upload.checked,
        allow_edit_protected: form.allow_edit_protected.checked,
        disable_path_restrictions: form.disable_path_restrictions.checked,
    };
    // Include password changes if filled
    if (form.new_password_user.value) data.new_password_user = form.new_password_user.value;
    if (form.new_password_admin.value) data.new_password_admin = form.new_password_admin.value;

    await runSafe(async () => {
        const result = await apiPost('settings', data);
        if (result && result.settings) {
            S.settings = result.settings;
            applyTheme(S.settings.theme);
            applyDensity(S.settings.density);
        }
        $('settingsDialog').close();
        await loadFiles();
        status('Settings saved');
    });
}

// ═══════════════════════════════════════════════════════════════
// ADMIN VERIFICATION
// ═══════════════════════════════════════════════════════════════
function promptAdminVerify() {
    return new Promise(resolve => {
        const dialog = $('adminVerifyDialog');
        const pw = $('adminVerifyPw');
        const btn = $('btnAdminVerifySubmit');
        pw.value = '';
        dialog.showModal();
        pw.focus();

        const handler = async () => {
            try {
                await apiPost('verify_admin', { password: pw.value });
                S.adminVerified = true;
                dialog.close();
                btn.removeEventListener('click', handler);
                resolve(true);
            } catch (e) {
                alert('Verification failed: ' + e.message);
                resolve(false);
            }
        };
        btn.onclick = handler;
        pw.onkeydown = e => { if (e.key === 'Enter') handler(); };
    });
}

async function verifyForSettings() {
    const pw = $('settingsAdminPw');
    try {
        await apiPost('verify_admin', { password: pw.value });
        S.adminVerified = true;
        $('settingsAdminVerify').style.display = 'none';
        status('Admin verified');
    } catch (e) {
        alert('Verification failed');
    }
}

// ═══════════════════════════════════════════════════════════════
// CONTEXT MENU
// ═══════════════════════════════════════════════════════════════
function showContextMenu(e, path) {
    e.preventDefault();
    const menu = $('contextMenu');
    const file = S.files.find(f => f.path === path);

    // Show/hide relevant options
    const isZip = file && file.ext === 'zip';
    menu.querySelector('[data-action="unzip"]').style.display = isZip ? '' : 'none';
    menu.querySelector('[data-action="edit"]').style.display = (file && !file.is_dir) ? '' : 'none';
    menu.querySelector('[data-action="paste"]').style.display = S.clipboard.paths.length > 0 ? '' : 'none';

    menu.dataset.path = path;
    menu.classList.add('visible');

    // Position
    const x = Math.min(e.clientX, window.innerWidth - 200);
    const y = Math.min(e.clientY, window.innerHeight - 300);
    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
}

function hideContextMenu() {
    $('contextMenu').classList.remove('visible');
}

function handleContextAction(action, path) {
    hideContextMenu();
    switch (action) {
        case 'open': {
            const f = S.files.find(f => f.path === path);
            if (f) openPath(f);
            break;
        }
        case 'edit': openEditor(path); break;
        case 'rename': doRename(path); break;
        case 'copy': doCopy([path]); break;
        case 'cut': doCut([path]); break;
        case 'paste': doPaste(); break;
        case 'favorite': toggleFavorite(path); break;
        case 'info': showProperties(path); break;
        case 'download': doDownload(path); break;
        case 'zip': {
            S.selected.add(path);
            doZipSelected();
            break;
        }
        case 'unzip': doUnzip(path); break;
        case 'delete': doDelete([path]); break;
    }
}

// ═══════════════════════════════════════════════════════════════
// SELECTION
// ═══════════════════════════════════════════════════════════════
function toggleSelect(path) {
    if (S.selected.has(path)) {
        S.selected.delete(path);
    } else {
        S.selected.add(path);
    }
    updateSelectionUI();
}

function selectAll(checked) {
    S.selected.clear();
    if (checked) {
        S.files.forEach(f => S.selected.add(f.path));
    }
    renderFiles();
    updateSelectionUI();
}

function updateSelectionUI() {
    const n = S.selected.size;
    $('btnDownloadSel').disabled = n === 0;
    $('btnZipSel').disabled = n === 0;
    $('btnDeleteSel').disabled = n === 0;
    $('statusSelection').textContent = n > 0 ? `${n} selected` : '';

    // Update row highlights
    document.querySelectorAll('#fileTableBody tr').forEach(tr => {
        const path = tr.dataset.path;
        if (path) {
            tr.classList.toggle('selected', S.selected.has(path));
            const cb = tr.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = S.selected.has(path);
        }
    });

    $('selectAll').checked = S.files.length > 0 && S.selected.size === S.files.length;
}

// ═══════════════════════════════════════════════════════════════
// SORTING (column headers)
// ═══════════════════════════════════════════════════════════════
function setSort(field) {
    if (S.sort === field) {
        S.order = S.order === 'asc' ? 'desc' : 'asc';
    } else {
        S.sort = field;
        S.order = 'asc';
    }
    // Update select
    const sel = $('sortSelect');
    sel.value = `${S.sort}:${S.order}`;
    loadFiles();
    updateSortHeaders();
}

function updateSortHeaders() {
    document.querySelectorAll('.file-table th.sortable').forEach(th => {
        th.classList.remove('sorted');
        const arrow = th.querySelector('.sort-arrow');
        if (arrow) arrow.remove();
        if (th.dataset.sort === S.sort) {
            th.classList.add('sorted');
            const a = document.createElement('span');
            a.className = 'sort-arrow';
            a.textContent = S.order === 'asc' ? ' \u25B2' : ' \u25BC';
            th.appendChild(a);
        }
    });
}

// ═══════════════════════════════════════════════════════════════
// DRAG & DROP
// ═══════════════════════════════════════════════════════════════
function setupDragDrop() {
    const zone = $('dropZone');
    const main = document.querySelector('.main-panel');

    ['dragenter', 'dragover'].forEach(evt => {
        main.addEventListener(evt, e => {
            e.preventDefault();
            zone.classList.add('active');
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        main.addEventListener(evt, e => {
            e.preventDefault();
            zone.classList.remove('active');
        });
    });
    main.addEventListener('drop', e => {
        e.preventDefault();
        const files = e.dataTransfer.files;
        if (files.length > 0) uploadFiles(files);
    });
}

// ═══════════════════════════════════════════════════════════════
// EVENT BINDING
// ═══════════════════════════════════════════════════════════════
function bindEvents() {
    // Toolbar buttons
    $('btnNewFile').onclick = createFile;
    $('btnNewFolder').onclick = createFolder;
    $('btnUpload').onclick = () => $('fileInput').click();
    $('btnDownloadSel').onclick = doDownloadSelected;
    $('btnZipSel').onclick = doZipSelected;
    $('btnDeleteSel').onclick = doDeleteSelected;
    $('fileInput').onchange = e => uploadFiles(e.target.files);

    // Sort select
    $('sortSelect').onchange = e => {
        const [field, order] = e.target.value.split(':');
        S.sort = field;
        S.order = order;
        loadFiles();
        updateSortHeaders();
    };

    // Column header sorting
    document.querySelectorAll('.file-table th.sortable').forEach(th => {
        th.onclick = () => setSort(th.dataset.sort);
    });

    // Select all
    $('selectAll').onchange = e => selectAll(e.target.checked);

    // File table clicks (delegation)
    $('fileTableBody').addEventListener('click', e => {
        const target = e.target;
        // Checkbox
        if (target.matches('[data-check]')) {
            toggleSelect(target.dataset.check);
            return;
        }
        // File name
        const nameEl = target.closest('[data-open]');
        if (nameEl) {
            const f = S.files.find(f => f.path === nameEl.dataset.open);
            if (f) openPath(f);
            return;
        }
        // Row actions
        if (target.matches('[data-act]')) {
            const path = target.dataset.path;
            switch (target.dataset.act) {
                case 'rename': doRename(path); break;
                case 'copy': doCopy([path]); break;
                case 'cut': doCut([path]); break;
                case 'delete': doDelete([path]); break;
                case 'download': doDownload(path); break;
            }
        }
    });

    // Right-click context menu
    $('fileTableBody').addEventListener('contextmenu', e => {
        const tr = e.target.closest('tr[data-path]');
        if (tr) {
            showContextMenu(e, tr.dataset.path);
        }
    });

    $('contextMenu').addEventListener('click', e => {
        const btn = e.target.closest('[data-action]');
        if (btn) {
            handleContextAction(btn.dataset.action, $('contextMenu').dataset.path);
        }
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.context-menu')) hideContextMenu();
    });

    // Breadcrumbs
    $('breadcrumbs').addEventListener('click', e => {
        const item = e.target.closest('[data-dir]');
        if (item) openDir(item.dataset.dir);
    });

    // Folder tree
    $('folderTree').addEventListener('click', e => {
        const item = e.target.closest('[data-dir]');
        if (item) openDir(item.dataset.dir);
    });

    // Search
    $('searchInput').addEventListener('input', e => handleSearch(e.target.value));

    // Sidebar quick links
    $('btnFavorites').onclick = showFavorites;
    $('btnRecent').onclick = showRecent;
    $('btnTrash').onclick = showTrash;
    if ($('btnLogs')) $('btnLogs').onclick = showLogs;
    if ($('btnStorage')) $('btnStorage').onclick = showStorage;
    if ($('btnSettings')) $('btnSettings').onclick = openSettings;

    // Sidebar mobile toggle
    $('sidebarToggleBtn').onclick = () => $('sidebar').classList.add('open');
    $('sidebarCloseBtn').onclick = () => $('sidebar').classList.remove('open');

    // Editor
    $('btnEditorSave').onclick = saveEditor;
    $('btnEditorClose').onclick = closeEditor;
    $('editorLang').onchange = e => {
        if (S.editorType === 'monaco' && S.editorInstance) {
            monaco.editor.setModelLanguage(S.editorInstance.getModel(), e.target.value);
        }
    };

    // Viewer
    $('btnViewerClose').onclick = () => $('viewerDialog').close();

    // Settings
    $('settingsForm').onsubmit = saveSettings;
    $('btnSettingsClose').onclick = () => $('settingsDialog').close();
    if ($('btnVerifyForSettings')) $('btnVerifyForSettings').onclick = verifyForSettings;

    // Trash dialog
    $('btnTrashClose').onclick = () => $('trashDialog').close();
    $('btnEmptyTrash').onclick = emptyTrash;
    $('trashList').addEventListener('click', e => {
        if (e.target.matches('[data-trash-restore]')) restoreFromTrash(e.target.dataset.trashRestore);
        if (e.target.matches('[data-trash-delete]')) permanentDelete(e.target.dataset.trashDelete);
    });

    // Favorites dialog
    $('btnFavoritesClose').onclick = () => $('favoritesDialog').close();
    $('favoritesList').addEventListener('click', e => {
        const navEl = e.target.closest('[data-nav-path]');
        if (navEl) {
            $('favoritesDialog').close();
            navigateToPath(navEl.dataset.navPath);
        }
        if (e.target.matches('[data-unfav]')) removeFavorite(e.target.dataset.unfav);
    });

    // Recent dialog
    $('btnRecentClose').onclick = () => $('recentDialog').close();
    $('recentList').addEventListener('click', e => {
        const navEl = e.target.closest('[data-nav-path]');
        if (navEl) {
            $('recentDialog').close();
            navigateToPath(navEl.dataset.navPath);
        }
    });

    // Logs dialog
    $('btnLogsClose').onclick = () => $('logsDialog').close();

    // Storage dialog
    $('btnStorageClose').onclick = () => $('storageDialog').close();

    // Properties dialog
    $('btnPropsClose').onclick = () => $('propsDialog').close();

    // Admin verify dialog
    $('btnAdminVerifyClose').onclick = () => $('adminVerifyDialog').close();

    // Drag & drop
    setupDragDrop();

    // Keyboard shortcuts
    document.addEventListener('keydown', handleKeyboard);

    // Close dialogs on Escape
    document.querySelectorAll('dialog').forEach(d => {
        d.addEventListener('cancel', e => {
            if (d.id === 'editorDialog') {
                e.preventDefault();
                closeEditor();
            }
        });
    });
}

// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════
function handleKeyboard(e) {
    // Don't intercept when typing in inputs
    if (e.target.matches('input, textarea, select, [contenteditable]')) {
        // Allow Ctrl+S in editor textarea
        if (S.editorType === 'textarea' && (e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveEditor();
        }
        return;
    }

    // Escape: close context menu or sidebar
    if (e.key === 'Escape') {
        hideContextMenu();
        $('sidebar').classList.remove('open');
        return;
    }

    // Ctrl+V: paste
    if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
        e.preventDefault();
        doPaste();
        return;
    }

    // Delete key: delete selected
    if (e.key === 'Delete' && S.selected.size > 0) {
        e.preventDefault();
        doDeleteSelected();
        return;
    }

    // Ctrl+A: select all
    if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
        e.preventDefault();
        selectAll(true);
        return;
    }

    // F2: rename (if single selected)
    if (e.key === 'F2' && S.selected.size === 1) {
        e.preventDefault();
        doRename([...S.selected][0]);
        return;
    }

    // Ctrl+F or /: focus search
    if (((e.ctrlKey || e.metaKey) && e.key === 'f') || e.key === '/') {
        e.preventDefault();
        $('searchInput').focus();
        return;
    }

    // Backspace: go to parent
    if (e.key === 'Backspace' && S.dir !== '/') {
        e.preventDefault();
        const parent = S.dir.substring(0, S.dir.lastIndexOf('/')) || '/';
        openDir(parent);
        return;
    }

    // Ctrl+U: upload
    if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
        e.preventDefault();
        $('fileInput').click();
        return;
    }
}

// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════
function $(id) { return document.getElementById(id); }

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function formatSize(bytes) {
    if (bytes === undefined || bytes === null) return '--';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
    return (bytes / 1073741824).toFixed(2) + ' GB';
}

function formatDate(ts) {
    if (!ts) return '--';
    const d = new Date(ts * 1000);
    if (isNaN(d.getTime())) return '--';
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function fileIcon(file) {
    if (file.is_dir) return '&#128193;';
    switch (file.type) {
        case 'image': return '&#128444;';
        case 'video': return '&#127910;';
        case 'audio': return '&#127925;';
        case 'archive': return '&#128230;';
        case 'code': return '&#128196;';
        case 'pdf': return '&#128213;';
        case 'doc': return '&#128195;';
        default: return '&#128196;';
    }
}

function status(msg) {
    $('statusText').textContent = msg;
}

function showLoading(show) {
    $('loadingOverlay').classList.toggle('visible', show);
}

function applyTheme(theme) {
    document.documentElement.dataset.theme = theme || 'light';
}

function applyDensity(density) {
    document.documentElement.dataset.density = density || 'comfortable';
}

function navigateToPath(path) {
    // If it's a file, go to parent dir
    const dir = path.substring(0, path.lastIndexOf('/')) || '/';
    openDir(dir);
}

async function runSafe(fn) {
    try {
        await fn();
    } catch (e) {
        alert('Error: ' + e.message);
        status('Error: ' + e.message);
    }
}

// ═══════════════════════════════════════════════════════════════
// START
// ═══════════════════════════════════════════════════════════════
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

})();
