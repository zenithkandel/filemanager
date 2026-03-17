export function createEventsModule(deps) {
    const {
        state,
        API,
        closeModal,
        hideContextMenu,
        resolveTheme,
        handleSearch,
        uploadFolder,
        createFolder,
        createFile,
        clipPaste,
        downloadFile,
        clipCopy,
        clipCut,
        deleteItems,
        extractSelectedArchives,
        compressItems,
        selectAll,
        selectNone,
        setView,
        updateSortUI,
        navigate,
        handleLogout,
        showChangePassword,
        showTrash,
        showUsers,
        showSettings,
        showStorageInfo,
        purgeCaching,
        showShortcutsHelp,
        toggleTheme,
        uploadFiles,
        batchRename,
        goUp,
        navigateItemByKey,
        openItem,
        renameItem,
        showContextMenu,
    } = deps;

    function bindEvents() {
        const byId = (id) => document.getElementById(id);
        const on = (id, event, handler) => {
            const el = byId(id);
            if (!el) return;
            el.addEventListener(event, handler);
        };

        const searchInput = document.getElementById('search-input');
        const addressInput = byId('address-bar-input');
        searchInput?.addEventListener('input', (e) => handleSearch(e.target.value.trim()));
        on('search-clear', 'click', () => {
            searchInput.value = '';
            handleSearch('');
        });

        on('btn-upload', 'click', () => byId('file-input')?.click());
        on('btn-upload-folder', 'click', uploadFolder);
        on('btn-new-folder', 'click', createFolder);
        on('btn-new-file', 'click', createFile);
        on('btn-paste', 'click', clipPaste);

        on('btn-sel-download', 'click', () => {
            const paths = [...state.selected];
            if (paths.length === 1) {
                downloadFile(paths[0]);
            } else {
                window.open(`${API}?action=bulk_download&paths=${encodeURIComponent(paths.join(','))}`, '_blank');
            }
        });
        on('btn-sel-copy', 'click', clipCopy);
        on('btn-sel-cut', 'click', clipCut);
        on('btn-sel-delete', 'click', () => deleteItems([...state.selected]));
        on('btn-sel-extract', 'click', extractSelectedArchives);
        on('btn-sel-compress', 'click', () => compressItems([...state.selected]));

        on('select-all', 'change', (e) => {
            e.target.checked ? selectAll() : selectNone();
        });

        on('btn-view-list', 'click', () => setView('list'));
        on('btn-view-grid', 'click', () => setView('grid'));

        on('btn-go-path', 'click', () => {
            const targetPath = addressInput?.value?.trim() || '/';
            navigate(targetPath);
        });
        addressInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const targetPath = addressInput.value.trim() || '/';
                navigate(targetPath);
            }
        });

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

        on('user-menu-btn', 'click', (e) => {
            e.stopPropagation();
            byId('user-dropdown')?.classList.toggle('open');
        });

        document.querySelectorAll('#user-dropdown .dropdown-item').forEach(el => {
            el.addEventListener('click', () => {
                byId('user-dropdown')?.classList.remove('open');
                const action = el.dataset.action;
                switch (action) {
                    case 'logout': handleLogout(); break;
                    case 'change-password': showChangePassword(); break;
                    case 'trash': showTrash(); break;
                    case 'users': showUsers(); break;
                    case 'settings': showSettings(); break;
                    case 'purge-cache': purgeCaching(); break;
                    case 'storage-info': showStorageInfo(); break;
                }
            });
        });

        on('shortcuts-btn', 'click', showShortcutsHelp);
        on('theme-toggle', 'click', toggleTheme);

        on('modal-close', 'click', closeModal);
        byId('modal-overlay')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeModal();
        });

        on('upload-progress-close', 'click', () => {
            byId('upload-progress')?.classList.add('hidden');
        });

        byId('file-input')?.addEventListener('change', (e) => {
            uploadFiles(e.target.files, state.path);
            e.target.value = '';
        });

        const main = byId('main');
        if (!main) return;
        let dragCounter = 0;
        main.addEventListener('dragenter', (e) => {
            e.preventDefault();
            dragCounter++;
            byId('drop-zone')?.classList.remove('hidden');
        });
        main.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dragCounter--;
            if (dragCounter <= 0) {
                dragCounter = 0;
                byId('drop-zone')?.classList.add('hidden');
            }
        });
        main.addEventListener('dragover', (e) => e.preventDefault());
        main.addEventListener('drop', (e) => {
            e.preventDefault();
            dragCounter = 0;
            byId('drop-zone')?.classList.add('hidden');
            const files = e.dataTransfer?.files;
            if (files && files.length > 0) uploadFiles(files, state.path);
        });

        main.addEventListener('contextmenu', (e) => {
            if (e.target.closest('.file-item')) return;
            e.preventDefault();
            showContextMenu(e, null);
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#user-menu')) {
                byId('user-dropdown')?.classList.remove('open');
            }
            if (!e.target.closest('.context-menu')) {
                hideContextMenu();
            }
            if (e.target === main || e.target.id === 'file-list') {
                if (!e.ctrlKey && !e.shiftKey) selectNone();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.target.matches('input, textarea, select')) {
                if (e.key === 'Escape') e.target.blur();
                return;
            }

            if (e.key === 'Escape') {
                if (!byId('modal-overlay')?.classList.contains('hidden')) {
                    closeModal();
                } else if (!byId('context-menu')?.classList.contains('hidden')) {
                    hideContextMenu();
                } else {
                    selectNone();
                }
                return;
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                selectAll();
                return;
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
                if (state.selected.size > 0) { e.preventDefault(); clipCopy(); }
                return;
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'x') {
                if (state.selected.size > 0) { e.preventDefault(); clipCut(); }
                return;
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
                if (state.clipboard) { e.preventDefault(); clipPaste(); }
                return;
            }

            if (e.key === 'Delete' && state.selected.size > 0) {
                e.preventDefault();
                deleteItems([...state.selected]);
                return;
            }

            if (e.key === 'F2' && state.selected.size === 1) {
                e.preventDefault();
                const path = [...state.selected][0];
                const item = state.items.find(i => i.path === path);
                if (item) renameItem(item);
                return;
            }

            if (e.key === 'F5') {
                e.preventDefault();
                navigate(state.path);
                return;
            }

            if (e.key === 'Backspace') {
                e.preventDefault();
                goUp();
                return;
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                searchInput?.focus();
                return;
            }

            if (e.shiftKey && e.key === 'N') {
                e.preventDefault();
                createFolder();
                return;
            }

            if (e.key === '?' || (e.shiftKey && e.key === '/')) {
                e.preventDefault();
                showShortcutsHelp();
                return;
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
                e.preventDefault();
                byId('file-input')?.click();
                return;
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 'r' && state.selected.size > 1) {
                e.preventDefault();
                batchRename();
                return;
            }

            if (e.key === 'Enter' && state.selected.size === 1) {
                e.preventDefault();
                const path = [...state.selected][0];
                const item = state.items.find(i => i.path === path);
                if (item) openItem(item);
                return;
            }

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                navigateItemByKey(e.key === 'ArrowDown' ? 1 : -1);
                return;
            }
        });

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', resolveTheme);
    }

    return { bindEvents };
}
