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
        const searchInput = document.getElementById('search-input');
        searchInput.addEventListener('input', (e) => handleSearch(e.target.value.trim()));
        document.getElementById('search-clear').addEventListener('click', () => {
            searchInput.value = '';
            handleSearch('');
        });

        document.getElementById('btn-upload').addEventListener('click', () => document.getElementById('file-input').click());
        document.getElementById('btn-upload-folder').addEventListener('click', uploadFolder);
        document.getElementById('btn-new-folder').addEventListener('click', createFolder);
        document.getElementById('btn-new-file').addEventListener('click', createFile);
        document.getElementById('btn-paste').addEventListener('click', clipPaste);

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

        document.getElementById('select-all').addEventListener('change', (e) => {
            e.target.checked ? selectAll() : selectNone();
        });

        document.getElementById('btn-view-list').addEventListener('click', () => setView('list'));
        document.getElementById('btn-view-grid').addEventListener('click', () => setView('grid'));

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

        document.getElementById('user-menu-btn').addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('user-dropdown').classList.toggle('open');
        });

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

        document.getElementById('shortcuts-btn').addEventListener('click', showShortcutsHelp);
        document.getElementById('theme-toggle').addEventListener('click', toggleTheme);

        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('modal-overlay').addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeModal();
        });

        document.getElementById('upload-progress-close').addEventListener('click', () => {
            document.getElementById('upload-progress').classList.add('hidden');
        });

        document.getElementById('file-input').addEventListener('change', (e) => {
            uploadFiles(e.target.files, state.path);
            e.target.value = '';
        });

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

        main.addEventListener('contextmenu', (e) => {
            if (e.target.closest('.file-item')) return;
            e.preventDefault();
            showContextMenu(e, null);
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#user-menu')) {
                document.getElementById('user-dropdown').classList.remove('open');
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
                if (!document.getElementById('modal-overlay').classList.contains('hidden')) {
                    closeModal();
                } else if (!document.getElementById('context-menu').classList.contains('hidden')) {
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
                searchInput.focus();
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
                document.getElementById('file-input').click();
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

        const savedTheme = localStorage.getItem('fm_theme');
        if (savedTheme) {
            document.documentElement.dataset.theme = savedTheme;
            resolveTheme();
        }
    }

    return { bindEvents };
}
