import { setSessionPref } from './state.js';

export function createBrowserModule(deps) {
    const {
        state,
        api,
        setLoading,
        toast,
        humanSize,
        formatDate,
        escHtml,
        API,
        ICONS,
        CODE_EXTS,
        DOC_EXTS,
        onOpenItem,
        onShowContextMenu,
        onEnableFileDragMove,
    } = deps;

    async function navigate(path) {
        state.path = normalizePath(path);
        state.selected.clear();
        updateSelectionUI();

        // Clear search state and input when navigating
        if (state.searchMode) {
            state.searchMode = false;
            const searchInput = document.getElementById('search-input');
            if (searchInput) searchInput.value = '';
            const clearBtn = document.getElementById('search-clear');
            if (clearBtn) clearBtn.classList.add('hidden');
        }

        setLoading(true);
        try {
            const data = await api('list', {
                params: { path: state.path, sort: state.sort, order: state.order }
            });
            state.items = data.items || [];
            setSessionPref(state, 'path', state.path);
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

        const chk = el.querySelector('.item-check');
        chk.checked = state.selected.has(item.path);

        el.addEventListener('dblclick', (e) => {
            if (e.target.closest('.item-check') || e.target.closest('.item-menu')) return;
            onOpenItem(item);
        });

        el.addEventListener('click', (e) => {
            if (e.target.closest('.item-check')) {
                toggleSelect(item.path, chk.checked);
                return;
            }
            if (e.target.closest('.item-menu')) {
                const btn = e.target.closest('.item-menu');
                const rect = btn.getBoundingClientRect();
                onShowContextMenu(e, item, rect.right, rect.bottom);
                return;
            }
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
            onShowContextMenu(e, item);
        });

        onEnableFileDragMove(el, item);
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

        document.querySelectorAll('.admin-only').forEach(el => {
            el.style.display = state.role === 'admin' ? '' : 'none';
        });
    }

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
        document.querySelectorAll('.file-item').forEach(el => {
            const path = el.dataset.path;
            const chk = el.querySelector('.item-check');
            const selected = state.selected.has(path);
            el.classList.toggle('selected', selected);
            if (chk) chk.checked = selected;
        });

        const selectAllChk = document.getElementById('select-all');
        if (selectAllChk) {
            selectAllChk.checked = state.items.length > 0 && state.selected.size === state.items.length;
            selectAllChk.indeterminate = state.selected.size > 0 && state.selected.size < state.items.length;
        }

        const selActions = document.getElementById('selection-actions');
        const count = document.getElementById('sel-count');

        if (state.selected.size > 0) {
            selActions.style.display = '';
            count.textContent = `${state.selected.size} selected`;
        } else {
            selActions.style.display = 'none';
        }

        const pasteBtn = document.getElementById('btn-paste');
        pasteBtn.style.display = state.clipboard ? '' : 'none';
    }

    function navigateItemByKey(direction) {
        const paths = state.items.map(i => i.path);
        if (paths.length === 0) return;

        const current = [...state.selected].pop();
        let idx = current ? paths.indexOf(current) : -1;
        idx += direction;
        idx = Math.max(0, Math.min(paths.length - 1, idx));

        selectOnly(paths[idx]);

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
        setSessionPref(state, 'view', view);
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

    return {
        navigate,
        normalizePath,
        renderFileList,
        renderBreadcrumb,
        updateStatusBar,
        updateUserUI,
        toggleSelect,
        selectOnly,
        selectAll,
        selectNone,
        rangeSelect,
        updateSelectionUI,
        navigateItemByKey,
        goUp,
        setView,
        updateViewToggle,
        updateSortUI,
    };
}
