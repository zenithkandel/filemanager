export const API = 'api.php';

export const state = {
    path: '/',
    items: [],
    selected: new Set(),
    clipboard: null,
    sort: 'name',
    order: 'asc',
    view: 'grid',
    user: '',
    role: '',
    csrf: '',
    settings: {},
    searchMode: false,
    loading: false,
};

export function getSessionPref(state, key, fallback = '') {
    try {
        const user = state.user || 'guest';
        const value = sessionStorage.getItem(`fm:${user}:${key}`);
        return value === null ? fallback : value;
    } catch {
        return fallback;
    }
}

export function setSessionPref(state, key, value) {
    try {
        const user = state.user || 'guest';
        sessionStorage.setItem(`fm:${user}:${key}`, String(value));
    } catch {
        // Ignore storage errors (private mode/quota/security restrictions)
    }
}

export const ICONS = {
    folder: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M2 9a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2Z" fill="currentColor"/></svg>',
    file: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
    image: '<svg viewBox="0 0 24 24" width="20" height="20"><rect x="3" y="3" width="18" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
    video: '<svg viewBox="0 0 24 24" width="20" height="20"><rect x="2" y="4" width="15" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="m17 8 5-3v14l-5-3Z" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
    audio: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M9 18V5l12-2v13" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="6" cy="18" r="3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="16" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
    archive: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6" fill="none" stroke="currentColor" stroke-width="2"/><path d="M10 12h.01M10 15h.01M10 18h.01M10 9h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    code: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="m16 18 6-6-6-6M8 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    doc: '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
};

export const CODE_EXTS = ['js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue', 'svelte', 'json', 'jsonc', 'xml', 'svg', 'html', 'htm', 'css', 'scss', 'sass', 'less', 'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rs', 'swift', 'kt', 'lua', 'r', 'dart', 'sh', 'bash', 'zsh', 'sql', 'yaml', 'yml', 'toml', 'makefile', 'dockerfile', 'asm'];
export const DOC_EXTS = ['txt', 'md', 'markdown', 'csv', 'log', 'ini', 'cfg', 'conf', 'env', 'properties', 'lock'];
