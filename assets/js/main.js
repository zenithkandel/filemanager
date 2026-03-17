/**
 * FileManager — Frontend Application
 * Module coordinator.
 */
import { api } from './app/api.js';
import { createAccountModule } from './app/account.js';
import { createBrowserModule } from './app/browser.js';
import { createEventsModule } from './app/events.js';
import { createOperationsModule } from './app/operations.js';
import { API, CODE_EXTS, DOC_EXTS, ICONS, getSessionPref, state } from './app/state.js';
import { escHtml, formatDate, humanSize } from './app/utils.js';
import { closeModal, confirm_, hideContextMenu, promptInput, setLoading, showModal, toast } from './app/ui.js';

let browser;
let operations;
let account;
let events;

function initApp() {
    events.bindEvents();
    browser.updateUserUI();
    browser.updateViewToggle();
    browser.navigate(state.path);
}

function init() {
    const app = document.getElementById('app');

    browser = createBrowserModule({
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
        onOpenItem: (item) => operations.openItem(item),
        onShowContextMenu: (e, item, x, y) => operations.showContextMenu(e, item, x, y),
        onEnableFileDragMove: (el, item) => operations.enableFileDragMove(el, item),
    });

    operations = createOperationsModule({
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
        navigate: browser.navigate,
        renderFileList: browser.renderFileList,
        updateStatusBar: browser.updateStatusBar,
        updateSelectionUI: browser.updateSelectionUI,
        selectOnly: browser.selectOnly,
        selectAll: browser.selectAll,
    });

    account = createAccountModule({
        state,
        api,
        showModal,
        closeModal,
        confirm_,
        toast,
        escHtml,
        navigate: browser.navigate,
        initApp,
    });

    events = createEventsModule({
        state,
        API,
        closeModal,
        hideContextMenu,
        resolveTheme: account.resolveTheme,
        handleSearch: operations.handleSearch,
        uploadFolder: operations.uploadFolder,
        createFolder: operations.createFolder,
        createFile: operations.createFile,
        clipPaste: operations.clipPaste,
        downloadFile: operations.downloadFile,
        clipCopy: operations.clipCopy,
        clipCut: operations.clipCut,
        deleteItems: operations.deleteItems,
        extractSelectedArchives: operations.extractSelectedArchives,
        compressItems: operations.compressItems,
        selectAll: browser.selectAll,
        selectNone: browser.selectNone,
        setView: browser.setView,
        updateSortUI: browser.updateSortUI,
        navigate: browser.navigate,
        handleLogout: account.handleLogout,
        showChangePassword: account.showChangePassword,
        showTrash: account.showTrash,
        showUsers: account.showUsers,
        showSettings: account.showSettings,
        showStorageInfo: account.showStorageInfo,
        showShortcutsHelp: operations.showShortcutsHelp,
        toggleTheme: account.toggleTheme,
        uploadFiles: operations.uploadFiles,
        batchRename: operations.batchRename,
        goUp: browser.goUp,
        navigateItemByKey: browser.navigateItemByKey,
        openItem: operations.openItem,
        renameItem: operations.renameItem,
        showContextMenu: operations.showContextMenu,
    });

    if (app && !app.classList.contains('hidden')) {
        state.user = app.dataset.user;
        state.role = app.dataset.role;
        state.csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        try {
            state.settings = JSON.parse(app.dataset.settings || '{}');
        } catch {
            state.settings = {};
        }

        state.view = getSessionPref(state, 'view', state.settings.default_view || 'list');
        state.path = getSessionPref(state, 'path', '/');

        const sessionTheme = getSessionPref(state, 'theme', '');
        if (sessionTheme) {
            document.documentElement.dataset.theme = sessionTheme;
        }

        initApp();
    }

    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', account.handleLogin);
    }

    account.resolveTheme();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
